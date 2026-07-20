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
require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

/**
 * LMDB hooks.
 */
class ActionsLmdb
{
	const SCHEDULED_SEND_ATTRIBUTE = 'lmdb_scheduled_send_at';

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
	 * Persist the scheduled send extrafield from the native mailing card.
	 *
	 * Dolibarr v20 displays mailing extrafields but its mailing card does not
	 * include the generic update_extras action. This hook uses the native
	 * ExtraFields parser and CommonObject persistence method to complete that
	 * integration without changing the core page.
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

		if ($action !== 'update_extras' || GETPOST('attribute', 'aZ09') !== self::SCHEDULED_SEND_ATTRIBUTE) {
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

		$extrafields = new ExtraFields($this->db);
		$labels = $extrafields->fetch_name_optionals_label($object->table_element);
		if (!is_array($labels) || !isset($labels[self::SCHEDULED_SEND_ATTRIBUTE])) {
			setEventMessages($langs->trans('LmdbScheduledMailingExtraFieldMissing'), null, 'errors');
			$action = '';
			return 1;
		}

		if ($object->fetch_optionals() < 0) {
			setEventMessages($object->error, $object->errors, 'errors');
			$action = '';
			return 1;
		}
		if (!empty($object->array_options['options_lmdb_scheduled_started_at'])) {
			setEventMessages($langs->trans('LmdbScheduledMailingDateLocked'), null, 'errors');
			$action = '';
			return 1;
		}
		$object->oldcopy = dol_clone($object, 2);
		$result = $extrafields->setOptionalsFromPost(null, $object, self::SCHEDULED_SEND_ATTRIBUTE);
		if ($result < 0) {
			setEventMessages($extrafields->error, $object->errors, 'errors');
			$action = 'edit_extras';
			return 1;
		}

		$result = $object->updateExtraField(self::SCHEDULED_SEND_ATTRIBUTE, 'MAILING_MODIFY', $user);
		if ($result <= 0) {
			setEventMessages($object->error, $object->errors, 'errors');
			$action = 'edit_extras';
			return 1;
		}

		setEventMessages($langs->trans('LmdbScheduledMailingDateSaved'), null, 'mesgs');
		$action = '';
		return 1;
	}

	/**
	 * Keep the scheduling field in the native inline extrafield editor.
	 *
	 * The v20 mailing full-edit layout renders its extrafields outside the main
	 * form. Hiding this one from that layout avoids presenting a control that
	 * cannot be submitted, while the native inline editor remains available on
	 * the campaign card. The edit icon is also removed after delivery starts.
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param object              $object     Hook object
	 * @param string              $action     Current action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int 0 to continue native rendering
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $extrafields;

		if (!is_object($object) || !($object instanceof Mailing) || !is_object($extrafields)) {
			return 0;
		}
		if (!isset($extrafields->attributes['mailing']['list'][self::SCHEDULED_SEND_ATTRIBUTE])) {
			return 0;
		}

		if ($action === 'edit') {
			$extrafields->attributes['mailing']['list'][self::SCHEDULED_SEND_ATTRIBUTE] = '5';
		}
		if (!in_array((int) $object->status, array(Mailing::STATUS_DRAFT, Mailing::STATUS_VALIDATED), true)
			|| !empty($object->array_options['options_lmdb_scheduled_started_at'])) {
			$extrafields->attributes['mailing']['alwayseditable'][self::SCHEDULED_SEND_ATTRIBUTE] = 0;
		}

		return 0;
	}
}
