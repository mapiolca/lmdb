<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       htdocs/custom/lmdb/class/actions_lmdb.class.php
 * \ingroup    lmdb
 * \brief      Hooks for the LMDB module.
 */

require_once DOL_DOCUMENT_ROOT.'/comm/mailing/class/mailing.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';

/**
 * LMDB hooks.
 */
class ActionsLmdb
{
	const SCHEDULED_SEND_FIELD = 'lmdb_scheduled_send_at';

	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var array<int,string> */
	public $errors = array();

	/** @var array<string,mixed> */
	public $results = array();

	/** @var string */
	public $resprints = '';

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
	 * Persist the scheduled send date from the native mailing card.
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param object              $object     Hook object
	 * @param string              $action     Current action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int 0 when not handled, 1 when handled, -1 on hook error
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs, $user;
		$langs->load('lmdb@lmdb');

		if ($action !== 'save_lmdb_mailing_schedule') {
			return 0;
		}
		$requestMethod = empty($_SERVER['REQUEST_METHOD']) ? '' : (string) $_SERVER['REQUEST_METHOD'];
		if ($requestMethod !== 'POST') {
			accessforbidden();
		}
		if (!is_object($object) || !($object instanceof Mailing) || (int) $object->id <= 0 || (int) $object->entity !== (int) $conf->entity) {
			$this->error = $langs->trans('ErrorRecordNotFound');
			$this->errors[] = $this->error;
			return -1;
		}

		$canSchedule = !empty($user->admin) || $user->hasRight('mailing', 'valider');
		if (!$canSchedule) {
			setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
			$action = '';
			return 1;
		}
		if ((string) $object->messtype !== 'email') {
			setEventMessages($langs->trans('LmdbScheduledMailingEmailOnly'), null, 'errors');
			$action = '';
			return 1;
		}
		if (!in_array((int) $object->status, array(Mailing::STATUS_DRAFT, Mailing::STATUS_VALIDATED), true)) {
			setEventMessages($langs->trans('LmdbScheduledMailingDateLocked'), null, 'errors');
			$action = '';
			return 1;
		}

		$scheduledAtInput = GETPOSTDATE(self::SCHEDULED_SEND_FIELD, 'getpost', 'tzuserrel');
		if ($scheduledAtInput === false) {
			setEventMessages($langs->trans('LmdbScheduledMailingInvalidDate'), null, 'errors');
			$action = 'edit_lmdb_mailing_schedule';
			return 1;
		}
		$scheduledAt = $scheduledAtInput === '' ? 0 : (int) $scheduledAtInput;
		$result = $this->saveSchedule((int) $object->id, (int) $conf->entity, $scheduledAt, (int) $user->id);
		if ($result === 0) {
			setEventMessages($langs->trans('LmdbScheduledMailingDateLocked'), null, 'errors');
			$action = '';
			return 1;
		}
		if ($result < 0) {
			setEventMessages($this->error, $this->errors, 'errors');
			$action = 'edit_lmdb_mailing_schedule';
			return 1;
		}

		setEventMessages($langs->trans('LmdbScheduledMailingDateSaved'), null, 'mesgs');
		header('Location: '.DOL_URL_ROOT.'/comm/mailing/card.php?id='.(int) $object->id);
		exit;
	}

	/**
	 * Add the scheduling field to the native mailing card.
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param object              $object     Hook object
	 * @param string              $action     Current action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int 0 to continue native rendering
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $form, $langs, $user;

		$this->resprints = '';
		if (!is_object($object) || !($object instanceof Mailing) || (int) $object->id <= 0 || (int) $object->entity !== (int) $conf->entity
			|| (string) $object->messtype !== 'email') {
			return 0;
		}

		$langs->load('lmdb@lmdb');
		$schedule = $this->fetchSchedule((int) $object->id, (int) $conf->entity);
		if ($schedule === false) {
			return -1;
		}
		if (!isset($form) || !is_object($form)) {
			$form = new Form($this->db);
		}
		$scheduledAt = is_array($schedule) ? $schedule['scheduled_send_at'] : 0;
		$startedAt = is_array($schedule) ? $schedule['scheduled_started_at'] : 0;
		$canSchedule = (!empty($user->admin) || $user->hasRight('mailing', 'valider'))
			&& (string) $object->messtype === 'email'
			&& in_array((int) $object->status, array(Mailing::STATUS_DRAFT, Mailing::STATUS_VALIDATED), true)
			&& $startedAt <= 0;

		$label = $form->textwithpicto(
			$langs->trans('LmdbScheduledMailingSendAt'),
			$langs->trans('LmdbScheduledMailingSendAtHelp'),
			1,
			'help'
		);
		$html = '<tr class="oddeven"><td class="titlefield">'.$label.'</td><td>';

		if ($action === 'edit_lmdb_mailing_schedule' && $canSchedule) {
			$html .= '<form method="POST" action="'.DOL_URL_ROOT.'/comm/mailing/card.php">';
			$html .= '<input type="hidden" name="token" value="'.newToken().'">';
			$html .= '<input type="hidden" name="action" value="save_lmdb_mailing_schedule">';
			$html .= '<input type="hidden" name="id" value="'.((int) $object->id).'">';
			$html .= $form->selectDate($scheduledAt > 0 ? $scheduledAt : '', self::SCHEDULED_SEND_FIELD, 1, 1, 1, '', 1, 1);
			$html .= ' <button class="button button-save" type="submit">'.$langs->trans('Save').'</button>';
			$html .= ' <a class="button button-cancel" href="'.DOL_URL_ROOT.'/comm/mailing/card.php?id='.((int) $object->id).'">'.$langs->trans('Cancel').'</a>';
			$html .= '</form>';
		} else {
			$html .= $scheduledAt > 0 ? dol_print_date($scheduledAt, 'dayhour', 'tzuserrel') : '<span class="opacitymedium">'.$langs->trans('NotDefined').'</span>';
			if ($canSchedule) {
				$editUrl = DOL_URL_ROOT.'/comm/mailing/card.php?id='.((int) $object->id).'&action=edit_lmdb_mailing_schedule&token='.newToken();
				$html .= ' <a class="editfielda" href="'.$editUrl.'">'.img_edit($langs->trans('Edit')).'</a>';
			}
		}

		$html .= '</td></tr>';
		$this->resprints = $html;

		return 0;
	}

	/**
	 * Read one mailing schedule in the current entity.
	 *
	 * @param int $mailingId Mailing id
	 * @param int $entity    Entity id
	 * @return array{scheduled_send_at:int,scheduled_started_at:int}|null|false
	 */
	private function fetchSchedule($mailingId, $entity)
	{
		global $langs;

		$sql = "SELECT scheduled_send_at, scheduled_started_at";
		$sql .= " FROM ".MAIN_DB_PREFIX."lmdb_mailing_schedule";
		$sql .= " WHERE entity = ".((int) $entity);
		$sql .= " AND fk_mailing = ".((int) $mailingId);
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.': '.$this->db->lasterror(), LOG_ERR);
			$this->error = $langs->trans('LmdbScheduledMailingStorageError');
			$this->errors[] = $this->error;
			return false;
		}
		$obj = $this->db->fetch_object($resql);
		if (!is_object($obj)) {
			return null;
		}

		return array(
			'scheduled_send_at' => empty($obj->scheduled_send_at) ? 0 : (int) $this->db->jdate($obj->scheduled_send_at),
			'scheduled_started_at' => empty($obj->scheduled_started_at) ? 0 : (int) $this->db->jdate($obj->scheduled_started_at),
		);
	}

	/**
	 * Insert or update one mailing schedule without reopening a started send.
	 *
	 * @param int $mailingId  Mailing id
	 * @param int $entity     Entity id
	 * @param int $scheduledAt Scheduled timestamp, or 0 to clear
	 * @param int $userId     User id
	 * @return int 1 if saved, 0 if locked, -1 on SQL error
	 */
	private function saveSchedule($mailingId, $entity, $scheduledAt, $userId)
	{
		global $langs;

		$this->db->begin();

		$sql = "SELECT rowid, scheduled_started_at";
		$sql .= " FROM ".MAIN_DB_PREFIX."lmdb_mailing_schedule";
		$sql .= " WHERE entity = ".((int) $entity);
		$sql .= " AND fk_mailing = ".((int) $mailingId);
		$sql .= " FOR UPDATE";
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.': '.$this->db->lasterror(), LOG_ERR);
			$this->error = $langs->trans('LmdbScheduledMailingStorageError');
			$this->errors[] = $this->error;
			$this->db->rollback();
			return -1;
		}

		$obj = $this->db->fetch_object($resql);
		if (is_object($obj) && !empty($obj->scheduled_started_at)) {
			$this->db->rollback();
			return 0;
		}

		$sqlDate = $scheduledAt > 0 ? "'".$this->db->idate($scheduledAt)."'" : 'NULL';
		if (is_object($obj)) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."lmdb_mailing_schedule";
			$sql .= " SET scheduled_send_at = ".$sqlDate;
			$sql .= ", fk_user_modif = ".((int) $userId);
			$sql .= " WHERE rowid = ".((int) $obj->rowid);
			$sql .= " AND entity = ".((int) $entity);
			$sql .= " AND scheduled_started_at IS NULL";
		} else {
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."lmdb_mailing_schedule";
			$sql .= " (entity, fk_mailing, scheduled_send_at, date_creation, fk_user_creat, fk_user_modif) VALUES (";
			$sql .= ((int) $entity).", ".((int) $mailingId).", ".$sqlDate.", '".$this->db->idate(dol_now())."', ".((int) $userId).", ".((int) $userId).")";
		}

		if (!$this->db->query($sql)) {
			dol_syslog(__METHOD__.': '.$this->db->lasterror(), LOG_ERR);
			$this->error = $langs->trans('LmdbScheduledMailingStorageError');
			$this->errors[] = $this->error;
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		return 1;
	}
}
