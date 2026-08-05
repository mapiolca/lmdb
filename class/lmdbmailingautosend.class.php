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
require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

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
	 * is due. The job chains native Web-sized batches until all recipients have
	 * been attempted. Failed recipients are retried once on a later scheduled
	 * pass to avoid an endless loop. Completed campaigns are never selected.
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
		if (!self::isNativeWebSenderAvailable()) {
			$this->error = $langs->trans('LmdbScheduledMailingWebSenderMissing');
			return 1;
		}
		$recipientLimit = getDolGlobalInt('MAILING_LIMIT_SENDBYWEB');
		if ($recipientLimit <= 0) {
			$this->error = $langs->trans('LmdbScheduledMailingWebDisabled');
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
				$result = $this->processCampaign($campaignId, $entity, (string) $candidate['validator_login'], $recipientLimit);
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
	 * @return array{cron_registered:bool,cron_active:bool,due_validated_count:int,due_partial_count:int,native_web_sender_available:bool,web_recipient_limit:int}
	 */
	public static function getDiagnostics($db, $entity)
	{
		$diagnostics = array(
			'cron_registered' => false,
			'cron_active' => false,
			'due_validated_count' => 0,
			'due_partial_count' => 0,
			'native_web_sender_available' => self::isNativeWebSenderAvailable(),
			'web_recipient_limit' => getDolGlobalInt('MAILING_LIMIT_SENDBYWEB'),
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
			$sql .= " INNER JOIN ".MAIN_DB_PREFIX."lmdb_mailing_schedule AS ms ON ms.fk_mailing = m.rowid AND ms.entity = m.entity";
			$sql .= " WHERE m.entity = ".((int) $entity);
			$sql .= " AND m.messtype = 'email'";
			$sql .= " AND (m.statut = ".Mailing::STATUS_VALIDATED;
			$sql .= " OR (m.statut = ".Mailing::STATUS_SENTPARTIALY." AND ms.scheduled_started_at IS NOT NULL))";
			$sql .= " AND ms.scheduled_send_at IS NOT NULL";
			$sql .= " AND ms.scheduled_send_at <= '".$db->idate(dol_now())."'";
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
	 * Check that the native components used by the Web emailing sender exist.
	 *
	 * @return bool
	 */
	public static function isNativeWebSenderAvailable()
	{
		return class_exists('CMailFile')
			&& function_exists('getCommonSubstitutionArray')
			&& function_exists('complete_substitutions_array')
			&& function_exists('make_substitutions');
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
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."lmdb_mailing_schedule AS ms ON ms.fk_mailing = m.rowid AND ms.entity = m.entity";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user AS u ON u.rowid = m.fk_user_valid";
		$sql .= " WHERE m.entity = ".((int) $entity);
		$sql .= " AND m.messtype = 'email'";
		$sql .= " AND (m.statut = ".Mailing::STATUS_VALIDATED;
		$sql .= " OR (m.statut = ".Mailing::STATUS_SENTPARTIALY." AND ms.scheduled_started_at IS NOT NULL))";
		$sql .= " AND ms.scheduled_send_at IS NOT NULL";
		$sql .= " AND ms.scheduled_send_at <= '".$this->db->idate(dol_now())."'";
		$sql .= " ORDER BY ms.scheduled_send_at ASC, m.rowid ASC";
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
	 * Send all pending recipients through native Web-sized batches.
	 *
	 * @param int    $campaignId   Mailing id
	 * @param int    $entity       Entity id
	 * @param string $validatorLogin Validator login used for substitutions
	 * @param int    $recipientLimit Native MAILING_LIMIT_SENDBYWEB limit
	 * @return int 1 when complete, 0 when no longer eligible, -1 on error
	 */
	private function processCampaign($campaignId, $entity, $validatorLogin, $recipientLimit)
	{
		global $langs;

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

		$mailing = new Mailing($this->db);
		$result = $mailing->fetch($campaignId);
		if ($result <= 0 || (int) $mailing->entity !== $entity || (string) $mailing->messtype !== 'email') {
			$this->errors[] = $langs->trans('LmdbScheduledMailingFetchError', $campaignId);
			return -1;
		}

		if ($state['remaining_targets'] === 0) {
			if ($this->setMailingStatus($mailing, Mailing::STATUS_SENTCOMPLETELY) <= 0) {
				$this->errors[] = $langs->trans('LmdbScheduledMailingCompleteStatusError', $campaignId);
				return -1;
			}
			return 1;
		}

		if ($this->markMailingSendDate($campaignId, $entity) <= 0) {
			$this->errors[] = $langs->trans('LmdbScheduledMailingSendDateError', $campaignId);
			return -1;
		}

		$attachmentData = $this->getMailingAttachments($mailing);
		if ($attachmentData === null) {
			$this->errors[] = $langs->trans('LmdbScheduledMailingAttachmentError', $campaignId);
			return -1;
		}

		$signature = $this->getValidatorSignature($validatorLogin);
		$thirdpartyStatic = new Societe($this->db);
		$failedTargets = 0;
		$mailingDelay = (float) getDolGlobalString('MAILING_DELAY');

		// Retry at most one batch that was already in error before this pass.
		// New failures are not selected again immediately, which prevents an
		// endless loop when an address or the SMTP service remains unavailable.
		if ($state['failed_targets'] > 0) {
			$retryTargets = $this->fetchTargets($campaignId, $entity, $recipientLimit, true);
			if ($retryTargets === null) {
				$this->errors[] = $langs->trans('LmdbScheduledMailingTargetsFetchError', $campaignId);
				return -1;
			}
			$batchResult = $this->sendBatch($mailing, $retryTargets, $signature, $attachmentData, $thirdpartyStatic, $mailingDelay);
			$failedTargets += $batchResult['failed'];
			if ($batchResult['persistence_error']) {
				$this->setMailingStatus($mailing, Mailing::STATUS_SENTPARTIALY);
				$this->errors[] = $langs->trans('LmdbScheduledMailingTargetUpdateError', $campaignId);
				return -1;
			}
		}

		// Chain every fresh-recipient batch in the same scheduled execution.
		while (true) {
			$pendingTargets = $this->fetchTargets($campaignId, $entity, $recipientLimit, false);
			if ($pendingTargets === null) {
				$this->errors[] = $langs->trans('LmdbScheduledMailingTargetsFetchError', $campaignId);
				return -1;
			}
			if (empty($pendingTargets)) {
				break;
			}
			$batchResult = $this->sendBatch($mailing, $pendingTargets, $signature, $attachmentData, $thirdpartyStatic, $mailingDelay);
			$failedTargets += $batchResult['failed'];
			if ($batchResult['persistence_error']) {
				$this->setMailingStatus($mailing, Mailing::STATUS_SENTPARTIALY);
				$this->errors[] = $langs->trans('LmdbScheduledMailingTargetUpdateError', $campaignId);
				return -1;
			}
		}

		$state = $this->fetchCampaignState($campaignId, $entity);
		if ($state === null) {
			$this->errors[] = $langs->trans('LmdbScheduledMailingNoLongerEligible', $campaignId);
			return -1;
		}

		if ($state['remaining_targets'] === 0) {
			if ($this->setMailingStatus($mailing, Mailing::STATUS_SENTCOMPLETELY) <= 0) {
				$this->errors[] = $langs->trans('LmdbScheduledMailingCompleteStatusError', $campaignId);
				return -1;
			}
			$state['status'] = Mailing::STATUS_SENTCOMPLETELY;
		} elseif ($this->setMailingStatus($mailing, Mailing::STATUS_SENTPARTIALY) <= 0) {
			$this->errors[] = $langs->trans('LmdbScheduledMailingPartialStatusError', $campaignId);
			return -1;
		}

		if ($failedTargets > 0) {
			$this->errors[] = $langs->trans('LmdbScheduledMailingTargetsFailed', $campaignId, $failedTargets);
			return -1;
		}

		return $state['remaining_targets'] === 0 ? 1 : 0;
	}

	/**
	 * Fetch a recipient batch using the same target states as the core sender.
	 *
	 * @param int  $campaignId Mailing id
	 * @param int  $entity     Entity id
	 * @param int  $limit      Maximum recipients
	 * @param bool $failedOnly Select targets already in error instead of fresh targets
	 * @return array<int,array{rowid:int,fk_mailing:int,lastname:string,firstname:string,email:string,other:string,source_url:string,source_id:int,source_type:string,tag:string}>|null
	 */
	private function fetchTargets($campaignId, $entity, $limit, $failedOnly)
	{
		$targets = array();
		$sql = "SELECT mc.rowid, mc.fk_mailing, mc.lastname, mc.firstname, mc.email, mc.other";
		$sql .= ", mc.source_url, mc.source_id, mc.source_type, mc.tag";
		$sql .= " FROM ".MAIN_DB_PREFIX."mailing_cibles AS mc";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."mailing AS m ON m.rowid = mc.fk_mailing";
		$sql .= " WHERE mc.statut ".($failedOnly ? '< 0' : '= 0');
		$sql .= " AND mc.fk_mailing = ".((int) $campaignId);
		$sql .= " AND m.entity = ".((int) $entity);
		$sql .= " ORDER BY mc.rowid ASC";
		$sql .= $this->db->plimit((int) $limit);
		$resql = $this->db->query($sql);
		if (!$resql) {
			return null;
		}

		while (is_object($obj = $this->db->fetch_object($resql))) {
			$targets[] = array(
				'rowid' => (int) $obj->rowid,
				'fk_mailing' => (int) $obj->fk_mailing,
				'lastname' => isset($obj->lastname) ? (string) $obj->lastname : '',
				'firstname' => isset($obj->firstname) ? (string) $obj->firstname : '',
				'email' => isset($obj->email) ? (string) $obj->email : '',
				'other' => isset($obj->other) ? (string) $obj->other : '',
				'source_url' => isset($obj->source_url) ? (string) $obj->source_url : '',
				'source_id' => isset($obj->source_id) ? (int) $obj->source_id : 0,
				'source_type' => isset($obj->source_type) ? (string) $obj->source_type : '',
				'tag' => isset($obj->tag) ? (string) $obj->tag : '',
			);
		}

		return $targets;
	}

	/**
	 * Send one recipient batch and persist each individual result immediately.
	 *
	 * @param Mailing $mailing Campaign
	 * @param array<int,array{rowid:int,fk_mailing:int,lastname:string,firstname:string,email:string,other:string,source_url:string,source_id:int,source_type:string,tag:string}> $targets Target batch
	 * @param string $signature Validator signature
	 * @param array{files:array<int,string>,mimetypes:array<int,string>,names:array<int,string>,upload_dir:string} $attachmentData Attachments
	 * @param Societe $thirdpartyStatic Reusable third-party object
	 * @param float $mailingDelay Delay between successful deliveries, in seconds
	 * @return array{failed:int,persistence_error:bool} Batch result
	 */
	private function sendBatch($mailing, $targets, $signature, $attachmentData, $thirdpartyStatic, $mailingDelay)
	{
		$failedTargets = 0;
		$persistenceError = false;
		foreach ($targets as $target) {
			$sendResult = $this->sendTarget($mailing, $target, $signature, $attachmentData, $thirdpartyStatic);
			if ($sendResult === -2) {
				$persistenceError = true;
				break;
			}
			if ($sendResult < 0) {
				$failedTargets++;
			} elseif ($mailingDelay > 0) {
				usleep((int) ($mailingDelay * 1000000));
			}
		}

		return array('failed' => $failedTargets, 'persistence_error' => $persistenceError);
	}

	/**
	 * Send one target through the native Dolibarr mail class.
	 *
	 * @param Mailing $mailing Campaign
	 * @param array{rowid:int,fk_mailing:int,lastname:string,firstname:string,email:string,other:string,source_url:string,source_id:int,source_type:string,tag:string} $target Target data
	 * @param string $signature Validator signature
	 * @param array{files:array<int,string>,mimetypes:array<int,string>,names:array<int,string>,upload_dir:string} $attachmentData Attachments
	 * @param Societe $thirdpartyStatic Reusable third-party object
	 * @return int 1 on success, -1 on delivery failure, -2 on persistence failure
	 */
	private function sendTarget($mailing, $target, $signature, $attachmentData, $thirdpartyStatic)
	{
		global $conf, $langs;

		$substitutionArray = getCommonSubstitutionArray($langs, 0, array('object', 'objectamount'), null);
		$otherValues = $this->parseOtherValues($target['other']);
		$substitutionArray['__ID__'] = $target['source_id'];
		$substitutionArray['__EMAIL__'] = $target['email'];
		$substitutionArray['__LASTNAME__'] = $target['lastname'];
		$substitutionArray['__FIRSTNAME__'] = $target['firstname'];
		$substitutionArray['__MAILTOEMAIL__'] = '<a href="mailto:'.$target['email'].'">'.$target['email'].'</a>';
		for ($index = 1; $index <= 5; $index++) {
			$substitutionArray['__OTHER'.$index.'__'] = $otherValues[$index - 1];
		}
		$substitutionArray['__THIRDPARTY_CUSTOMER_CODE__'] = '';
		if ($target['source_type'] === 'thirdparty' && $target['source_id'] > 0 && $thirdpartyStatic->fetch($target['source_id']) > 0) {
			$substitutionArray['__THIRDPARTY_CUSTOMER_CODE__'] = (string) $thirdpartyStatic->code_client;
		}
		$substitutionArray['__USER_SIGNATURE__'] = $signature;
		$substitutionArray['__SENDEREMAIL_SIGNATURE__'] = $signature;
		$substitutionArray['__SIGNATURE__'] = $signature;

		$securityKey = dol_hash(getDolGlobalString('MAILING_EMAIL_UNSUBSCRIBE_KEY').'-'.$target['tag'].'-'.$target['email'].'-'.$target['rowid'], 'md5');
		$trackingQuery = 'tag='.urlencode($target['tag']).'&securitykey='.$securityKey.'&email='.urlencode($target['email']).'&mtid='.$target['rowid'];
		$unsubscribeUrl = DOL_MAIN_URL_ROOT.'/public/emailing/mailing-unsubscribe.php?tag='.urlencode($target['tag']).'&unsuscrib=1&securitykey='.$securityKey.'&email='.urlencode($target['email']).'&mtid='.$target['rowid'];
		$substitutionArray['__CHECK_READ__'] = '<img src="'.DOL_MAIN_URL_ROOT.'/public/emailing/mailing-read.php?'.$trackingQuery.'" width="1" height="1" style="width:1px;height:1px" border="0"/>';
		$substitutionArray['__UNSUBSCRIBE__'] = '<a href="'.$unsubscribeUrl.'" target="_blank" rel="noopener noreferrer">'.$langs->trans('MailUnsubcribe').'</a>';
		$substitutionArray['__UNSUBSCRIBE_URL__'] = $unsubscribeUrl;

		$this->addPaymentSubstitutions($substitutionArray, $target['source_id']);
		if (getDolGlobalInt('MEMBER_ENABLE_PUBLIC')) {
			$substitutionArray['__PUBLICLINK_NEWMEMBERFORM__'] = '<a target="_blank" rel="noopener noreferrer" href="'.DOL_MAIN_URL_ROOT.'/public/members/new.php'.(isModEnabled('multicompany') ? '?entity='.((int) $conf->entity) : '').'">'.$langs->trans('BlankSubscriptionForm').'</a>';
		}
		complete_substitutions_array($substitutionArray, $langs);

		$subject = make_substitutions((string) $mailing->sujet, $substitutionArray);
		$message = make_substitutions((string) $mailing->body, $substitutionArray, null, 0);
		$moreInHeader = '';
		if (preg_match('/__UNSUBSCRIBE_(_|URL_)/', (string) $mailing->body)) {
			$moreInHeader = make_substitutions("List-Unsubscribe: <__UNSUBSCRIBE_URL__>\n", $substitutionArray);
		}

		$recipientName = str_replace(',', ' ', dolGetFirstLastname($target['firstname'], $target['lastname']));
		$sendTo = trim($recipientName) !== '' ? $recipientName.' <'.$target['email'].'>' : $target['email'];
		$msgIsHtml = preg_match('/[\s\t]*<html>/i', (string) $mailing->body) ? 1 : -1;
		$trackId = 'emailing-'.$target['fk_mailing'].'-'.$target['rowid'];
		$mail = new CMailFile(
			$subject,
			$sendTo,
			(string) $mailing->email_from,
			$message,
			$attachmentData['files'],
			$attachmentData['mimetypes'],
			$attachmentData['names'],
			'',
			'',
			0,
			$msgIsHtml,
			(string) $mailing->email_errorsto,
			array(),
			$trackId,
			$moreInHeader,
			'emailing',
			(string) $mailing->email_replyto,
			$attachmentData['upload_dir']
		);

		$result = empty($mail->error) ? $mail->sendfile() : 0;
		$errorText = $result ? '' : (string) $mail->error;
		if ($this->updateTargetResult($target['rowid'], $target['fk_mailing'], (int) $mailing->entity, $result > 0, $errorText) <= 0) {
			dol_syslog(__METHOD__.': failed to update target id='.$target['rowid'], LOG_ERR);
			return -2;
		}
		if (!$result) {
			dol_syslog(__METHOD__.': delivery failed for target id='.$target['rowid'].' '.$errorText, LOG_WARNING);
			return -1;
		}

		if (strpos((string) $mailing->body, '__CHECK_READ__') !== false) {
			$this->updateProspectCommunication($target);
		}

		return 1;
	}

	/**
	 * Add the payment-related substitutions maintained by the core Web sender.
	 *
	 * @param array<string,mixed> $substitutionArray Substitution array
	 * @param int                 $sourceId         Target source id
	 * @return void
	 */
	private function addPaymentSubstitutions(&$substitutionArray, $sourceId)
	{
		$onlinePaymentEnabled = isModEnabled('paypal') || isModEnabled('paybox') || isModEnabled('stripe');
		if ($onlinePaymentEnabled && getDolGlobalString('PAYMENT_SECURITY_TOKEN') !== '') {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/payments.lib.php';
			$substitutionArray['__ONLINEPAYMENTLINK_MEMBER__'] = getHtmlOnlinePaymentLink('member', $sourceId);
			$substitutionArray['__ONLINEPAYMENTLINK_DONATION__'] = getHtmlOnlinePaymentLink('donation', $sourceId);
			$substitutionArray['__ONLINEPAYMENTLINK_ORDER__'] = getHtmlOnlinePaymentLink('order', $sourceId);
			$substitutionArray['__ONLINEPAYMENTLINK_INVOICE__'] = getHtmlOnlinePaymentLink('invoice', $sourceId);
			$substitutionArray['__ONLINEPAYMENTLINK_CONTRACTLINE__'] = getHtmlOnlinePaymentLink('contractline', $sourceId);
			$substitutionArray['__SECUREKEYPAYMENT__'] = dol_hash(getDolGlobalString('PAYMENT_SECURITY_TOKEN'), '2');
			foreach (array('MEMBER' => 'member', 'DONATION' => 'donation', 'ORDER' => 'order', 'INVOICE' => 'invoice', 'CONTRACTLINE' => 'contractline') as $key => $type) {
				$suffix = getDolGlobalInt('PAYMENT_SECURITY_TOKEN_UNIQUE') ? $type.$sourceId : '';
				$substitutionArray['__SECUREKEYPAYMENT_'.$key.'__'] = dol_hash(getDolGlobalString('PAYMENT_SECURITY_TOKEN').$suffix, '2');
			}
		}

		if (isModEnabled('paypal') && getDolGlobalString('PAYPAL_SECURITY_TOKEN') !== '') {
			$substitutionArray['__SECUREKEYPAYPAL__'] = dol_hash(getDolGlobalString('PAYPAL_SECURITY_TOKEN'), '2');
			foreach (array('MEMBER' => 'membersubscription', 'ORDER' => 'order', 'INVOICE' => 'invoice', 'CONTRACTLINE' => 'contractline') as $key => $type) {
				$suffix = getDolGlobalInt('PAYPAL_SECURITY_TOKEN_UNIQUE') ? $type.$sourceId : '';
				$substitutionArray['__SECUREKEYPAYPAL_'.$key.'__'] = dol_hash(getDolGlobalString('PAYPAL_SECURITY_TOKEN').$suffix, '2');
			}
		}
	}

	/**
	 * Return campaign attachments through the same document path as the core.
	 *
	 * @param Mailing $mailing Campaign
	 * @return array{files:array<int,string>,mimetypes:array<int,string>,names:array<int,string>,upload_dir:string}|null
	 */
	private function getMailingAttachments($mailing)
	{
		global $conf;

		if (!isset($conf->mailing) || !is_object($conf->mailing) || empty($conf->mailing->dir_output)) {
			return null;
		}
		$directoryDepth = getDolGlobalInt('MAILING_USE_NEW_PATH_FOR_FILES') ? 0 : 2;
		$uploadDir = (string) $conf->mailing->dir_output.'/'.get_exdir($mailing->id, $directoryDepth, 0, 1, $mailing, 'mailing');
		$files = array();
		$mimetypes = array();
		$names = array();
		$listOfPaths = dol_dir_list($uploadDir, 'all', 0, '', '', 'name', SORT_ASC, 0);
		if (is_array($listOfPaths)) {
			foreach ($listOfPaths as $pathData) {
				if (!isset($pathData['fullname'], $pathData['name'])) {
					continue;
				}
				$files[] = (string) $pathData['fullname'];
				$mimetypes[] = dol_mimetype((string) $pathData['name']);
				$names[] = (string) $pathData['name'];
			}
		}

		return array('files' => $files, 'mimetypes' => $mimetypes, 'names' => $names, 'upload_dir' => $uploadDir);
	}

	/**
	 * Read the signature of the user who validated the campaign.
	 *
	 * @param string $login Validator login
	 * @return string
	 */
	private function getValidatorSignature($login)
	{
		if ($login === '' || getDolGlobalInt('MAIN_MAIL_DO_NOT_USE_SIGN')) {
			return '';
		}
		$validator = new User($this->db);
		if ($validator->fetch(0, $login) <= 0) {
			return '';
		}

		return isset($validator->signature) ? (string) $validator->signature : '';
	}

	/**
	 * Persist the result of one target immediately, like the core sender.
	 *
	 * @param int    $targetId  Target id
	 * @param int    $campaignId Mailing id
	 * @param int    $entity    Entity id
	 * @param bool   $success   Delivery success
	 * @param string $errorText Delivery error
	 * @return int 1 on success, -1 on SQL error
	 */
	private function updateTargetResult($targetId, $campaignId, $entity, $success, $errorText)
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."mailing_cibles AS mc";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."mailing AS m ON m.rowid = mc.fk_mailing";
		$sql .= " SET mc.statut = ".($success ? '1' : '-1');
		$sql .= ", mc.date_envoi = '".$this->db->idate(dol_now())."'";
		$sql .= ", mc.error_text = ".($success ? 'NULL' : "'".$this->db->escape(dol_trunc($errorText, 250))."'");
		$sql .= " WHERE mc.rowid = ".((int) $targetId);
		$sql .= " AND mc.fk_mailing = ".((int) $campaignId);
		$sql .= " AND m.entity = ".((int) $entity);

		$resql = $this->db->query($sql);
		if (!$resql) {
			return -1;
		}

		return $this->db->affected_rows($resql) > 0 ? 1 : -1;
	}

	/**
	 * Keep the native prospect communication update used with read tracking.
	 *
	 * @param array{rowid:int,fk_mailing:int,lastname:string,firstname:string,email:string,other:string,source_url:string,source_id:int,source_type:string,tag:string} $target Target data
	 * @return void
	 */
	private function updateProspectCommunication($target)
	{
		$sql = '';
		if ($target['source_type'] === 'thirdparty' && $target['source_id'] > 0) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."societe SET fk_stcomm = 2 WHERE rowid = ".((int) $target['source_id']);
		} elseif ($target['source_type'] === 'contact' && $target['source_id'] > 0) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."societe AS s";
			$sql .= " INNER JOIN ".MAIN_DB_PREFIX."socpeople AS sp ON sp.fk_soc = s.rowid";
			$sql .= " SET s.fk_stcomm = 2 WHERE sp.rowid = ".((int) $target['source_id']);
		}
		if ($sql !== '' && !$this->db->query($sql)) {
			dol_syslog(__METHOD__.': failed for target id='.$target['rowid'], LOG_WARNING);
		}
	}

	/**
	 * Split the five generic target values used by native substitutions.
	 *
	 * @param string $other Serialized native target values
	 * @return array<int,string>
	 */
	private function parseOtherValues($other)
	{
		$values = array('', '', '', '', '');
		$parts = explode(';', $other);
		for ($index = 0; $index < 5; $index++) {
			if (!isset($parts[$index])) {
				continue;
			}
			$fieldParts = explode('=', $parts[$index], 2);
			$values[$index] = isset($fieldParts[1]) ? $fieldParts[1] : $fieldParts[0];
		}

		return $values;
	}

	/**
	 * Set the first campaign send date without replacing an existing value.
	 *
	 * @param int $campaignId Mailing id
	 * @param int $entity     Entity id
	 * @return int 1 on success, -1 on SQL error
	 */
	private function markMailingSendDate($campaignId, $entity)
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."mailing";
		$sql .= " SET date_envoi = COALESCE(date_envoi, '".$this->db->idate(dol_now())."')";
		$sql .= " WHERE rowid = ".((int) $campaignId)." AND entity = ".((int) $entity);

		return $this->db->query($sql) ? 1 : -1;
	}

	/**
	 * Update the native campaign status.
	 *
	 * @param Mailing $mailing Campaign
	 * @param int     $status  Native Mailing status
	 * @return int Positive on success, negative on failure
	 */
	private function setMailingStatus($mailing, $status)
	{
		$mailingValues = get_object_vars($mailing);
		$currentStatus = isset($mailingValues['status']) ? (int) $mailingValues['status'] : (isset($mailingValues['statut']) ? (int) $mailingValues['statut'] : -1);
		if ($currentStatus === $status) {
			return 1;
		}

		return $mailing->setStatut($status);
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
		$sql = "SELECT m.statut, ms.scheduled_started_at, COUNT(mc.rowid) AS remaining_targets";
		$sql .= ", SUM(CASE WHEN mc.statut < 0 THEN 1 ELSE 0 END) AS failed_targets";
		$sql .= " FROM ".MAIN_DB_PREFIX."mailing AS m";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."lmdb_mailing_schedule AS ms ON ms.fk_mailing = m.rowid AND ms.entity = m.entity";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."mailing_cibles AS mc ON mc.fk_mailing = m.rowid AND mc.statut < 1";
		$sql .= " WHERE m.rowid = ".((int) $campaignId);
		$sql .= " AND m.entity = ".((int) $entity);
		$sql .= " AND m.messtype = 'email'";
		$sql .= " AND ms.scheduled_send_at IS NOT NULL";
		$sql .= " AND ms.scheduled_send_at <= '".$this->db->idate(dol_now())."'";
		$sql .= " GROUP BY m.rowid, m.statut, ms.scheduled_started_at";
		$resql = $this->db->query($sql);
		if (!$resql || !is_object($obj = $this->db->fetch_object($resql))) {
			return null;
		}

		return array(
			'status' => (int) $obj->statut,
			'remaining_targets' => (int) $obj->remaining_targets,
			'failed_targets' => (int) $obj->failed_targets,
			'scheduled_started' => !empty($obj->scheduled_started_at),
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
		$sql = "UPDATE ".MAIN_DB_PREFIX."lmdb_mailing_schedule AS ms";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."mailing AS m ON m.rowid = ms.fk_mailing AND m.entity = ms.entity";
		$sql .= " SET ms.scheduled_started_at = COALESCE(ms.scheduled_started_at, '".$this->db->idate(dol_now())."')";
		$sql .= " WHERE ms.fk_mailing = ".((int) $campaignId);
		$sql .= " AND ms.entity = ".((int) $entity);
		$sql .= " AND m.entity = ".((int) $entity);
		$sql .= " AND m.messtype = 'email'";
		$sql .= " AND m.statut = ".Mailing::STATUS_VALIDATED;
		$sql .= " AND ms.scheduled_send_at IS NOT NULL";
		$sql .= " AND ms.scheduled_send_at <= '".$this->db->idate(dol_now())."'";

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

}
