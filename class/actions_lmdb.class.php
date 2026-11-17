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

	/** @var array<int,string> Dates for the current native mailing result page. */
	private $mailingListDates = array();

	/** @var int|null Insertion position before the native status column. */
	private $mailingListColumnIndex;

	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var array<int,string> */
	public $errors = array();

	/** @var array<int,string> Native HookManager resets warnings before each call. */
	public $warnings = array();

	/** @var array<string,mixed> */
	public $results = array();

	/** @var string|null */
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

	/** Restrict the native contact form's company selector to its fixed beneficiary.
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param object|null $object Native selector object
	 * @param string $action Current action
	 * @param HookManager $hookmanager Native manager
	 * @return int
	 */
	public function selectCompaniesForNewContactListWhere($parameters, &$object, &$action, $hookmanager)
	{
		global $lmdbAttestationContact, $user;
		if (!($lmdbAttestationContact instanceof LmdbAttestation)) { return 0; }
		$this->resprints = $user->hasRight('lmdb','lmdbattestation','write') ? ' AND s.rowid='.(int)$lmdbAttestationContact->fk_soc : ' AND 1=0';
		return 1;
	}

	/** Same business scope as contact mutation, applied before native user selection.
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param object|null $object Native selector object
	 * @param string $action Current action
	 * @param HookManager $hookmanager Native manager
	 * @return int
	 */
	public function addSQLWhereFilterOnSelectUsers($parameters, &$object, &$action, $hookmanager)
	{
		global $lmdbAttestationContact, $user;
		if (!($lmdbAttestationContact instanceof LmdbAttestation)) { return 0; }
		$ids = $user->hasRight('lmdb','lmdbattestation','write') ? $lmdbAttestationContact->accessibleContactIds('internal') : array();
		$this->resprints = $ids ? ' AND u.rowid IN ('.implode(',',array_keys($ids)).')' : ' AND 1=0';
		return 1;
	}

	/**
	 * Repair invoice PDF translations before the native model renders content.
	 *
	 * Recurring invoice generation can call the core sponge model directly from
	 * the model stored on the recurring invoice. The pdfgeneration hook keeps
	 * automatic and manual rendering aligned without overriding that native
	 * model choice.
	 *
	 * @param array<string,mixed> $parameters  Hook parameters
	 * @param CommonObject|null   $object      Generated business object
	 * @param string              $action      Current action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int 0 to continue native PDF generation
	 */
	public function beforePDFCreation($parameters, &$object, &$action, $hookmanager)
	{
		if (!is_object($object) || (string) $object->element !== 'facture') {
			return 0;
		}

		$outputlangs = isset($parameters['outputlangs']) && is_object($parameters['outputlangs'])
			? $parameters['outputlangs']
			: null;
		if (!($outputlangs instanceof Translate)) {
			return 0;
		}

		require_once __DIR__.'/../lib/lmdb_translation.lib.php';
		require_once __DIR__.'/../lib/lmdb_pdf.lib.php';
		lmdbLoadInvoiceTranslations($outputlangs);
		lmdbPdfApplyTranslationFallbacks($outputlangs);

		return 0;
	}

	/**
	 * Only enrich the native mailing lists, including the project's mailing tab.
	 * Permissions remain explicit in each entry point.
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject|null $object Native list object
	 * @return bool
	 */
	private function isMailingListContext($parameters, $object)
	{
		$context = isset($parameters['context']) && is_string($parameters['context']) ? $parameters['context'] : '';
		return $object instanceof Mailing && isModEnabled('lmdb') && isModEnabled('mailing')
			&& (in_array('mailinglist', explode(':', $context), true)
				|| in_array('projectmailing', explode(':', $context), true));
	}

	/**
	 * Read dates in one query joined to the native filtered and paginated query.
	 * Native mailing lists v20-v24 expose no per-row rendering hook.
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject|null $object Native list object
	 * @param string $action Current action
	 * @param HookManager $hookmanager Native manager
	 * @return int
	 */
	public function printFieldListTitle($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs, $sql;
		$this->mailingListDates = array();
		$this->mailingListColumnIndex = null;
		$this->resprints = '';
		if (!$this->isMailingListContext($parameters, $object) || !$user->hasRight('mailing', 'lire')
			|| empty($parameters['arrayfields'][self::SCHEDULED_SEND_FIELD]['checked'])
			|| !isset($parameters['totalarray']['nbfield'])) {
			return 0;
		}

		if (!is_string($sql) || $sql === '') {
			return 0;
		}
		// Reuse the native query: recipient/project filters, rights and pagination
		// stay authoritative. Do not consume or replace the native result cursor.
		$scheduleSql = "SELECT ms.fk_mailing, ms.scheduled_send_at FROM ".MAIN_DB_PREFIX."lmdb_mailing_schedule AS ms";
		$scheduleSql .= " INNER JOIN (".$sql.") AS lmdb_page ON lmdb_page.rowid = ms.fk_mailing";
		$scheduleSql .= " INNER JOIN ".MAIN_DB_PREFIX."mailing AS m ON m.rowid = ms.fk_mailing AND m.entity = ms.entity";
		$scheduleSql .= " WHERE ms.entity = ".((int) $conf->entity)." AND m.messtype = 'email'";
		$schedules = $this->db->query($scheduleSql);
		if (!$schedules) {
			$langs->load('lmdb@lmdb');
			$this->error = $langs->trans('LmdbScheduledMailingStorageError');
			dol_syslog(__METHOD__.': failed to read scheduled mailing dates', LOG_ERR);
			return -1;
		}
		while (is_object($schedule = $this->db->fetch_object($schedules))) {
			if ($schedule->scheduled_send_at !== null && $schedule->scheduled_send_at !== '') {
				$this->mailingListDates[(int) $schedule->fk_mailing] = dol_print_date($this->db->jdate($schedule->scheduled_send_at), 'dayhour');
			}
		}
		$this->db->free($schedules);
		$this->mailingListColumnIndex = (int) $parameters['totalarray']['nbfield'];
		return 0;
	}

	/**
	 * Add display-only cells to the native table; never change its pagination.
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject|null $object Native list object
	 * @param string $action Current action
	 * @param HookManager $hookmanager Native manager
	 * @return int
	 */
	public function printFieldListFooter($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $langs;
		$this->resprints = '';
		if (!$this->isMailingListContext($parameters, $object) || !$user->hasRight('mailing', 'lire')
			|| $this->mailingListColumnIndex === null) {
			return 0;
		}
		$langs->load('lmdb@lmdb');
		$data = json_encode(array(
			'index' => $this->mailingListColumnIndex,
			'label' => $langs->transnoentities('LmdbScheduledMailingSendAt'),
			'dates' => (object) $this->mailingListDates,
		), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		$this->resprints = '<script type="application/json" id="lmdb-mailing-list-dates">'.$data.'</script>';
		$this->resprints .= '<script src="'.dol_buildpath('/lmdb/js/mailing_schedule_list.js', 1).'?v='.filemtime(__DIR__.'/../js/mailing_schedule_list.js').'"></script>';
		$this->mailingListColumnIndex = null;
		$this->mailingListDates = array();
		return 0;
	}

	/**
	 * Persist the scheduled send date from the native mailing card.
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject|null   $object     Hook object
	 * @param string              $action     Current action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int 0 when not handled, 1 when handled, -1 on hook error
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs, $user;
		$langs->load('lmdb@lmdb');

		// v20-v24 have no completeArrayFields hook on the mailing list.
		if ($this->isMailingListContext($parameters, $object) && $user->hasRight('mailing', 'lire')) {
			global $arrayfields;
			if (!is_array($arrayfields)) {
				$arrayfields = array();
			}
			global $contextpage;
			$preferenceKey = 'MAIN_SELECTEDFIELDS_'.(string) $contextpage;
			// The native selector treats an empty saved list as default selection.
			// Here this is the only optional column: an explicit empty value hides it.
			$checked = 1;
			if ((isset($user->conf) && is_object($user->conf)
				&& isset($user->conf->{$preferenceKey}) && $user->conf->{$preferenceKey} === '')
				|| (GETPOST('formfilteraction', 'alphanohtml') === 'listafterchangingselectedfields'
					&& GETPOST('selectedfields', 'alphanohtml') === '')) {
				$checked = 0;
			}
			$arrayfields[self::SCHEDULED_SEND_FIELD] = array(
				'label' => 'LmdbScheduledMailingSendAt', 'checked' => $checked,
				'enabled' => 1, 'position' => 1000,
			);
		}

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

		/** @var int|string|false $scheduledAtInput Native invalid dates can return false despite the core PHPDoc. */
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
	 * @param CommonObject|null   $object     Hook object
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

	/** @return array<string,array<string,array<string,mixed>>> Native Multicompany sharing defaults. */
	public static function getMulticompanySharingDefinition()
	{
		$elements = array();
		foreach (array('lmdbattestation','lmdbattestationnumber') as $element) {
			$elements[$element] = array('type'=>$element === 'lmdbattestation' ? 'element' : 'objectnumber', 'icon'=>'fa-certificate_fa_blue', 'lang'=>'lmdb@lmdb', 'tooltip'=>'LmdbAttestationSharingInfo', 'enable'=>'isModEnabled("lmdb")', 'input'=>array('global'=>array('showhide'=>true,'hide'=>true,'del'=>true)));
		}
		return array('lmdb'=>array('sharingelements'=>$elements,'sharingmodulename'=>array('lmdbattestation'=>'lmdb','lmdbattestationnumber'=>'lmdb'),'dictionary'=>array()));
	}
	public function multicompanyExternalModulesSharing($parameters, &$object, &$action, $hookmanager)
	{
		$this->results = self::getMulticompanySharingDefinition(); return 0;
	}
	public function multicompanyExternalModuleSharing($parameters, &$object, &$action, $hookmanager)
	{
		$this->results = self::getMulticompanySharingDefinition(); return 0;
	}
	public function multicompanySharingOptions($parameters, &$object, &$action, $hookmanager)
	{
		$this->results = self::getMulticompanySharingDefinition(); return 0;
	}
	public function notifsupported($parameters, &$object, &$action, $hookmanager)
	{
		$this->results = array('arrayofnotifsupported'=>array('LMDB_ATTESTATION_CREATE','LMDB_ATTESTATION_UPDATE','LMDB_ATTESTATION_DELETE')); return 0;
	}
	public function emailElementlist($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;
		$langs->load('lmdb@lmdb');
		$this->results = array('lmdbattestation_send'=>$langs->trans('LmdbAttestation')); return 0;
	}
	/** Resolve the same object identifier for Agenda, native Ajax and linked objects. */
	public function getElementProperties($parameters, &$object, &$action, $hookmanager)
	{
		global $conf;
		$type = isset($parameters['elementType']) ? $parameters['elementType'] : '';
		if (!in_array($type, array('lmdbattestation','lmdbattestation@lmdb','lmdbattestation_send'), true)) { return 0; }
		$this->results = array('module'=>'lmdb','element'=>'lmdbattestation','table_element'=>'lmdb_attestation','subelement'=>'lmdbattestation','classpath'=>'lmdb/class','classfile'=>'lmdbattestation','classname'=>'LmdbAttestation','dir_output'=>isset($conf->lmdb->multidir_output[$conf->entity]) ? $conf->lmdb->multidir_output[$conf->entity] : '', 'dir_temp'=>'');
		return 0;
	}
	/** Replace the attestation banner icon using the native photo/preview renderer.
	 * @param array<string,mixed> $parameters Native banner parameters, by-reference values
	 * @param CommonObject $object Banner object
	 * @param string $action Page action
	 * @param HookManager $hookmanager Hook manager
	 * @return int Native banner continues normally
	 */
	public function formDolBanner($parameters, &$object, &$action, $hookmanager)
	{
		global $user;
		if (!($object instanceof LmdbAttestation) || !$user->hasRight('lmdb', 'lmdbattestation', 'read')) { return 0; }
		$data = $object->getDataToShowPhoto('lmdb', 'small');
		if ($data['file'] === '' || !array_key_exists('morehtmlleft', $parameters)) { return 0; }
		$form = new Form($this->db);
		$photo = $form->showphoto('lmdb', $object, 0, 80, 0, 'photowithmargin photoref', 'small', 1, filemtime($data['dir'].'/'.$data['file']), '', 1);
		if ($photo !== '') { $parameters['morehtmlleft'] = '<div class="floatleft inline-block valignmiddle divphotoref">'.$photo.'</div>'; }
		return 0;
	}
	/** Called before native file writes: checks permission, owner and actual PDF MIME. */
	public function overrideUploadOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $conf;
		if (!is_object($object) || !($object instanceof LmdbAttestation)) { return 0; }
		if (!$user->hasRight('lmdb','lmdbattestation','write') || (int)$object->entity !== (int)$conf->entity || (int)$object->status !== LmdbAttestation::STATUS_VALIDATED) { accessforbidden(); }
		$dir = $object->documentDirectory(true);
		if ($dir === false || !LmdbCompatibility::isFeatureAvailable('attestation_pdf_upload') || $_SERVER['REQUEST_METHOD'] !== 'POST') { accessforbidden(); }
		$hasfile=false;
		foreach ($_FILES as $file) {
			$temps = is_array($file['tmp_name']) ? $file['tmp_name'] : array($file['tmp_name']);
			$names = is_array($file['name']) ? $file['name'] : array($file['name']);
			foreach ($temps as $key=>$temp) {
				if ($temp === '') { continue; }
				$hasfile=true;
				if (!is_uploaded_file($temp) || !isset($names[$key]) || strtolower(pathinfo($names[$key],PATHINFO_EXTENSION)) !== 'pdf' || (new finfo(FILEINFO_MIME_TYPE))->file($temp) !== 'application/pdf') { accessforbidden(); }
			}
		}
		if (!$hasfile) { accessforbidden(); } // Raw streamed uploads cannot bypass MIME inspection.
		// Native collision checks precede mask application: retain the checked filename.
		$parameters['options']['saving_doc_mask'] = '';
		$parameters['options']['upload_dir'] = $dir.'/';
		$parameters['options']['upload_url'] = DOL_URL_ROOT.'/document.php?modulepart=lmdb&entity='.(int)$object->entity.'&file='.rawurlencode($object->ref).'/';
		$parameters['options']['accept_file_types'] = '/\.pdf$/i';
		return 0;
	}
	/**
	 * Native generic document access grants module-level rights only. Narrow it
	 * to the exact object, commercial scope and owner directory here. Returning
	 * accessallowed=0 is ignored by the core contract, so refusals must terminate.
	 */
	public function checkSecureAccess($parameters, &$object, &$action, $hookmanager)
	{
		if (!isset($parameters['modulepart']) || $parameters['modulepart'] !== 'lmdb') { return 0; }
		global $user;
		require_once __DIR__.'/lmdbattestation.class.php';
		require_once __DIR__.'/../lib/lmdb_attestation.lib.php';
		$path = isset($parameters['original_file']) ? $parameters['original_file'] : '';
		if (strpos($path, '/attestation_settings/') !== false) { accessforbidden(); }
		global $conf;
		$entity = isset($parameters['entity']) ? (int)$parameters['entity'] : 0;
		$root = isset($conf->lmdb->multidir_output[$entity]) ? rtrim($conf->lmdb->multidir_output[$entity], '/') : '';
		if (!$root || strpos($path, $root.'/') !== 0) { accessforbidden(); }
		$relative = substr($path, strlen($root)+1);
		if (!preg_match('~^([^/]+)/.+$~', $relative, $match)) { accessforbidden(); }
		if ($match[1] === 'temp') { return 0; }
		$actor = isset($parameters['fuser']) && $parameters['fuser'] instanceof User ? $parameters['fuser'] : $user;
		$attestation = new LmdbAttestation($this->db);
		if (!$actor->hasRight('lmdb','lmdbattestation','read') || $actor->socid > 0 || $attestation->fetch(0,$match[1]) <= 0 || (int)$attestation->entity !== (int)$parameters['entity'] || !lmdbAttestationFileBelongsToObject($attestation,$path)) { accessforbidden(); }
		if (isset($parameters['mode']) && $parameters['mode'] === 'write' && (!$actor->hasRight('lmdb','lmdbattestation','delete') || (int)$attestation->entity !== (int)$conf->entity)) { accessforbidden(); }
		$this->results = array('original_file'=>$path,'accessallowed'=>1);
		return 1;
	}
}
