<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       htdocs/custom/lmdb/class/lmdbmailingautosend.class.php
 * \ingroup    lmdb
 * \brief      Scheduled sending of native Dolibarr email campaigns.
 */

require_once DOL_DOCUMENT_ROOT.'/comm/mailing/class/mailing.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/utils.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

/**
 * Native emailing scheduled-send service.
 */
class LmdbMailingAutoSend
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $output = '';

	/** @var string */
	public $error = '';

	/** @var array<int,string> */
	public $errors = array();

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Run the native scheduled job.
	 *
	 * A validated campaign enters the automatic flow when its scheduled date
	 * is due. Status 2 is considered only to resume a campaign whose first
	 * native pass left failed targets. Completed campaigns are never selected.
	 *
	 * @return int 0 on success, positive error count on failure
	 */
	public function run()
	{
		global $conf, $langs;

		$this->output = '';
		$this->error = '';
		$this->errors = array();
		$langs->loadLangs(array('mails', 'errors', 'lmdb@lmdb'));

		if (!isModEnabled('lmdb') || !isModEnabled('mailing') || !isModEnabled('cron')) {
			$this->error = $langs->trans('LmdbScheduledMailingModulesUnavailable');
			return 1;
		}
		if (getDolGlobalString('MAILING_LIMIT_SENDBYCLI') === '-1') {
			$this->error = $langs->trans('LmdbScheduledMailingCliDisabled');
			return 1;
		}
		if (!self::isNativeMailingScriptAvailable()) {
			$this->error = $langs->trans('LmdbScheduledMailingCoreScriptMissing');
			return 1;
		}
		if (!self::isPhpCliAvailable()) {
			$this->error = $langs->trans('LmdbScheduledMailingPhpCliMissing');
			return 1;
		}

		$entity = (int) $conf->entity;
		if (self::normalizeCronTranslationKeys($this->db, $entity) <= 0) {
			$this->error = $langs->trans('LmdbScheduledMailingCronTranslationUpdateError');
			return 1;
		}

		$maxPerRun = getDolGlobalInt('LMDB_SCHEDULED_MAILING_MAX_PER_RUN', 10);
		if (!in_array($maxPerRun, array(1, 5, 10, 25), true)) {
			$maxPerRun = 10;
		}
		$candidates = $this->fetchCandidateCampaigns($entity, $maxPerRun);
		if ($candidates === null) {
			$this->error = $this->db->lasterror();
			return 1;
		}

		$analysed = 0;
		$completed = 0;
		$pending = 0;
		$failed = 0;
		foreach ($candidates as $candidate) {
			$analysed++;
			$campaignId = (int) $candidate['id'];
			$lockName = 'lmdb_mailing_'.$entity.'_'.$campaignId;
			$lockResult = $this->acquireLock($lockName);
			if ($lockResult === 0) {
				$pending++;
				continue;
			}
			if ($lockResult < 0) {
				$failed++;
				$this->errors[] = $langs->trans('LmdbScheduledMailingLockError', $campaignId);
				continue;
			}

			try {
				$result = $this->processCampaign($campaignId, $entity, (string) $candidate['validator_login']);
			} catch (Throwable $exception) {
				dol_syslog(__METHOD__.': unexpected '.get_class($exception).' for mailing id='.$campaignId, LOG_ERR);
				$this->errors[] = $langs->trans('LmdbScheduledMailingUnexpectedError', $campaignId);
				$result = -1;
			} finally {
				$this->releaseLock($lockName);
			}

			if ($result > 0) {
				$completed++;
			} elseif ($result === 0) {
				$pending++;
			} else {
				$failed++;
			}
		}

		$this->output = $langs->trans('LmdbScheduledMailingCronResult', $analysed, $completed, $pending, $failed);
		if (!empty($this->errors)) {
			$this->error = implode(' | ', $this->errors);
		}
		dol_syslog(__METHOD__.': '.$this->output, $failed > 0 ? LOG_WARNING : LOG_INFO);

		return $failed > 0 ? $failed : 0;
	}

	/**
	 * Return diagnostics used by the setup page.
	 *
	 * @param DoliDB $db     Database handler
	 * @param int    $entity Entity id
	 * @return array{cron_registered:bool,cron_active:bool,due_validated_count:int,due_partial_count:int,core_script_available:bool,php_cli_available:bool}
	 */
	public static function getDiagnostics($db, $entity)
	{
		$diagnostics = array(
			'cron_registered' => false,
			'cron_active' => false,
			'due_validated_count' => 0,
			'due_partial_count' => 0,
			'core_script_available' => self::isNativeMailingScriptAvailable(),
			'php_cli_available' => self::isPhpCliAvailable(),
		);

		$sql = "SELECT status FROM ".MAIN_DB_PREFIX."cronjob";
		$sql .= " WHERE module_name = 'lmdb'";
		$sql .= " AND classesname = '/lmdb/class/lmdbmailingautosend.class.php'";
		$sql .= " AND objectname = 'LmdbMailingAutoSend'";
		$sql .= " AND methodename = 'run'";
		$sql .= " AND entity = ".((int) $entity);
		$sql .= " ORDER BY rowid DESC";
		$sql .= $db->plimit(1);
		$resql = $db->query($sql);
		if ($resql && is_object($obj = $db->fetch_object($resql))) {
			$diagnostics['cron_registered'] = true;
			$diagnostics['cron_active'] = ((int) $obj->status === 1);
		}

		if (isModEnabled('mailing')) {
			$sql = "SELECT m.statut, COUNT(m.rowid) AS nb";
			$sql .= " FROM ".MAIN_DB_PREFIX."mailing AS m";
			$sql .= " INNER JOIN ".MAIN_DB_PREFIX."mailing_extrafields AS me ON me.fk_object = m.rowid";
			$sql .= " WHERE m.entity = ".((int) $entity);
			$sql .= " AND m.messtype = 'email'";
			$sql .= " AND (m.statut = ".Mailing::STATUS_VALIDATED;
			$sql .= " OR (m.statut = ".Mailing::STATUS_SENTPARTIALY." AND me.lmdb_scheduled_started_at IS NOT NULL))";
			$sql .= " AND me.lmdb_scheduled_send_at IS NOT NULL";
			$sql .= " AND me.lmdb_scheduled_send_at <= '".$db->idate(dol_now())."'";
			$sql .= " GROUP BY m.statut";
			$resql = $db->query($sql);
			if ($resql) {
				while (is_object($obj = $db->fetch_object($resql))) {
					if ((int) $obj->statut === Mailing::STATUS_VALIDATED) {
						$diagnostics['due_validated_count'] = (int) $obj->nb;
					} elseif ((int) $obj->statut === Mailing::STATUS_SENTPARTIALY) {
						$diagnostics['due_partial_count'] = (int) $obj->nb;
					}
				}
			}
		}

		return $diagnostics;
	}

	/**
	 * Keep translations of an existing native cron row without changing its
	 * schedule, activation state or execution history.
	 *
	 * @param DoliDB $db     Database handler
	 * @param int    $entity Entity id
	 * @return int 1 if OK, -1 if KO
	 */
	public static function normalizeCronTranslationKeys($db, $entity)
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."cronjob";
		$sql .= " SET label = 'LmdbScheduledMailingCronLabel:lmdb@lmdb'";
		$sql .= ", note = 'LmdbScheduledMailingCronComment'";
		$sql .= " WHERE entity = ".((int) $entity);
		$sql .= " AND module_name = 'lmdb'";
		$sql .= " AND classesname = '/lmdb/class/lmdbmailingautosend.class.php'";
		$sql .= " AND objectname = 'LmdbMailingAutoSend'";
		$sql .= " AND methodename = 'run'";

		return $db->query($sql) ? 1 : -1;
	}

	/**
	 * Check the presence of the core Dolibarr emailing CLI script.
	 *
	 * @return bool
	 */
	public static function isNativeMailingScriptAvailable()
	{
		return defined('DOL_DOCUMENT_ROOT') && is_file(dirname(DOL_DOCUMENT_ROOT).'/scripts/emailings/mailing-send.php');
	}

	/**
	 * Check that a PHP CLI binary and the native execution function are usable.
	 *
	 * @return bool
	 */
	public static function isPhpCliAvailable()
	{
		return function_exists('exec') && self::getPhpCliBinary() !== '';
	}

	/**
	 * Select due campaigns for the current entity.
	 *
	 * @param int $entity   Entity id
	 * @param int $maxPerRun Processing limit
	 * @return array<int,array{id:int,status:int,validator_login:string}>|null
	 */
	private function fetchCandidateCampaigns($entity, $maxPerRun)
	{
		$candidates = array();
		$sql = "SELECT m.rowid, m.statut, u.login AS validator_login";
		$sql .= " FROM ".MAIN_DB_PREFIX."mailing AS m";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."mailing_extrafields AS me ON me.fk_object = m.rowid";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user AS u ON u.rowid = m.fk_user_valid";
		$sql .= " WHERE m.entity = ".((int) $entity);
		$sql .= " AND m.messtype = 'email'";
		$sql .= " AND (m.statut = ".Mailing::STATUS_VALIDATED;
		$sql .= " OR (m.statut = ".Mailing::STATUS_SENTPARTIALY." AND me.lmdb_scheduled_started_at IS NOT NULL))";
		$sql .= " AND me.lmdb_scheduled_send_at IS NOT NULL";
		$sql .= " AND me.lmdb_scheduled_send_at <= '".$this->db->idate(dol_now())."'";
		$sql .= " ORDER BY me.lmdb_scheduled_send_at ASC, m.rowid ASC";
		$sql .= $this->db->plimit((int) $maxPerRun);
		$resql = $this->db->query($sql);
		if (!$resql) {
			return null;
		}
		while (is_object($obj = $this->db->fetch_object($resql))) {
			$candidates[] = array(
				'id' => (int) $obj->rowid,
				'status' => (int) $obj->statut,
				'validator_login' => isset($obj->validator_login) ? (string) $obj->validator_login : '',
			);
		}

		return $candidates;
	}

	/**
	 * Send one campaign with the official Dolibarr CLI script.
	 *
	 * @param int    $campaignId   Mailing id
	 * @param int    $entity       Entity id
	 * @param string $validatorLogin Validator login used for substitutions
	 * @return int 1 when complete, 0 when no longer eligible, -1 on error
	 */
	private function processCampaign($campaignId, $entity, $validatorLogin)
	{
		global $conf, $langs;

		$state = $this->fetchCampaignState($campaignId, $entity);
		if ($state === null) {
			$this->errors[] = $langs->trans('LmdbScheduledMailingNoLongerEligible', $campaignId);
			return 0;
		}
		if (!in_array($state['status'], array(Mailing::STATUS_VALIDATED, Mailing::STATUS_SENTPARTIALY), true)) {
			return 0;
		}
		if ($state['status'] === Mailing::STATUS_SENTPARTIALY && !$state['scheduled_started']) {
			return 0;
		}
		if ($state['status'] === Mailing::STATUS_VALIDATED && !$state['scheduled_started']) {
			if ($this->markCampaignStarted($campaignId, $entity) <= 0) {
				$this->errors[] = $langs->trans('LmdbScheduledMailingStartMarkerError', $campaignId);
				return -1;
			}
			$state['scheduled_started'] = true;
		}

		$moduleConfig = isset($conf->lmdb) && is_object($conf->lmdb) ? get_object_vars($conf->lmdb) : array();
		$tempDir = isset($moduleConfig['dir_temp']) ? (string) $moduleConfig['dir_temp'] : '';
		if ($tempDir === '' || (dol_mkdir($tempDir) < 0 && !is_dir($tempDir))) {
			$this->errors[] = $langs->trans('LmdbScheduledMailingTempDirectoryError', $campaignId);
			return -1;
		}

		$phpCli = self::getPhpCliBinary();
		$script = dirname(DOL_DOCUMENT_ROOT).'/scripts/emailings/mailing-send.php';
		$command = 'dol_entity='.escapeshellarg((string) $entity);
		$command .= ' '.escapeshellarg($phpCli);
		$command .= ' -d variables_order=EGPCS';
		$command .= ' '.escapeshellarg($script);
		$command .= ' '.escapeshellarg((string) $campaignId);
		$command .= ' '.escapeshellarg($validatorLogin);
		$cliRecipientLimit = getDolGlobalInt('MAILING_LIMIT_SENDBYCLI');
		if ($cliRecipientLimit > 0) {
			$command .= ' '.escapeshellarg((string) $cliRecipientLimit);
		}

		$utils = new Utils($this->db);
		$execution = $utils->executeCLI($command, $tempDir.'/mailing-autosend-output.tmp', 1, null, 1);
		$state = $this->fetchCampaignState($campaignId, $entity);
		if ($state === null) {
			$this->errors[] = $langs->trans('LmdbScheduledMailingNoLongerEligible', $campaignId);
			return -1;
		}

		if ($state['remaining_targets'] === 0 && $state['status'] !== Mailing::STATUS_SENTCOMPLETELY) {
			$mailing = new Mailing($this->db);
			$result = $mailing->fetch($campaignId);
			if ($result <= 0 || (int) $mailing->entity !== $entity || $mailing->setStatut(Mailing::STATUS_SENTCOMPLETELY) <= 0) {
				$this->errors[] = $langs->trans('LmdbScheduledMailingCompleteStatusError', $campaignId);
				return -1;
			}
			$state['status'] = Mailing::STATUS_SENTCOMPLETELY;
		}

		if ((int) $execution['result'] !== 0) {
			$this->errors[] = $langs->trans('LmdbScheduledMailingNativeSendError', $campaignId);
			return -1;
		}
		if ($state['failed_targets'] > 0) {
			$this->errors[] = $langs->trans('LmdbScheduledMailingTargetsFailed', $campaignId, $state['failed_targets']);
			return -1;
		}
		if ($state['remaining_targets'] > 0) {
			return 0;
		}

		return $state['status'] === Mailing::STATUS_SENTCOMPLETELY ? 1 : 0;
	}

	/**
	 * Read the status and unsent/failed target count of a campaign.
	 *
	 * @param int $campaignId Mailing id
	 * @param int $entity     Entity id
	 * @return array{status:int,remaining_targets:int,failed_targets:int,scheduled_started:bool}|null
	 */
	private function fetchCampaignState($campaignId, $entity)
	{
		$sql = "SELECT m.statut, me.lmdb_scheduled_started_at, COUNT(mc.rowid) AS remaining_targets";
		$sql .= ", SUM(CASE WHEN mc.statut < 0 THEN 1 ELSE 0 END) AS failed_targets";
		$sql .= " FROM ".MAIN_DB_PREFIX."mailing AS m";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."mailing_extrafields AS me ON me.fk_object = m.rowid";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."mailing_cibles AS mc ON mc.fk_mailing = m.rowid AND mc.statut < 1";
		$sql .= " WHERE m.rowid = ".((int) $campaignId);
		$sql .= " AND m.entity = ".((int) $entity);
		$sql .= " AND m.messtype = 'email'";
		$sql .= " AND me.lmdb_scheduled_send_at IS NOT NULL";
		$sql .= " AND me.lmdb_scheduled_send_at <= '".$this->db->idate(dol_now())."'";
		$sql .= " GROUP BY m.rowid, m.statut, me.lmdb_scheduled_started_at";
		$resql = $this->db->query($sql);
		if (!$resql || !is_object($obj = $this->db->fetch_object($resql))) {
			return null;
		}

		return array(
			'status' => (int) $obj->statut,
			'remaining_targets' => (int) $obj->remaining_targets,
			'failed_targets' => (int) $obj->failed_targets,
			'scheduled_started' => !empty($obj->lmdb_scheduled_started_at),
		);
	}

	/**
	 * Mark that a validated campaign entered the LMDB automatic flow.
	 *
	 * This internal marker is what authorizes retries after the native sender
	 * changes a campaign from validated to partially sent.
	 *
	 * @param int $campaignId Mailing id
	 * @param int $entity     Entity id
	 * @return int 1 if marked, 0 if no longer eligible, -1 on SQL error
	 */
	private function markCampaignStarted($campaignId, $entity)
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."mailing_extrafields AS me";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."mailing AS m ON m.rowid = me.fk_object";
		$sql .= " SET me.lmdb_scheduled_started_at = COALESCE(me.lmdb_scheduled_started_at, '".$this->db->idate(dol_now())."')";
		$sql .= " WHERE me.fk_object = ".((int) $campaignId);
		$sql .= " AND m.entity = ".((int) $entity);
		$sql .= " AND m.messtype = 'email'";
		$sql .= " AND m.statut = ".Mailing::STATUS_VALIDATED;
		$sql .= " AND me.lmdb_scheduled_send_at IS NOT NULL";
		$sql .= " AND me.lmdb_scheduled_send_at <= '".$this->db->idate(dol_now())."'";

		$resql = $this->db->query($sql);
		if (!$resql) {
			return -1;
		}

		return $this->db->affected_rows($resql) > 0 ? 1 : 0;
	}

	/**
	 * Acquire a MySQL/MariaDB advisory lock for a campaign.
	 *
	 * @param string $lockName Lock name
	 * @return int 1 acquired, 0 already held, -1 on SQL error
	 */
	private function acquireLock($lockName)
	{
		$sql = "SELECT GET_LOCK('".$this->db->escape($lockName)."', 0) AS acquired";
		$resql = $this->db->query($sql);
		if (!$resql || !is_object($obj = $this->db->fetch_object($resql))) {
			return -1;
		}

		return (int) $obj->acquired === 1 ? 1 : 0;
	}

	/**
	 * Release a campaign advisory lock.
	 *
	 * @param string $lockName Lock name
	 * @return void
	 */
	private function releaseLock($lockName)
	{
		$sql = "SELECT RELEASE_LOCK('".$this->db->escape($lockName)."')";
		if (!$this->db->query($sql)) {
			dol_syslog(__METHOD__.': failed to release mailing lock', LOG_WARNING);
		}
	}

	/**
	 * Locate PHP CLI without relying on a shell PATH.
	 *
	 * @return string Absolute executable path, or an empty string
	 */
	private static function getPhpCliBinary()
	{
		$candidates = array();
		if (PHP_SAPI === 'cli' && defined('PHP_BINARY')) {
			$candidates[] = PHP_BINARY;
		}
		if (defined('PHP_BINDIR')) {
			$candidates[] = PHP_BINDIR.'/php';
		}
		foreach (array_unique($candidates) as $candidate) {
			if (is_string($candidate) && $candidate !== '' && is_file($candidate) && is_executable($candidate)) {
				return $candidate;
			}
		}

		return '';
	}
}
