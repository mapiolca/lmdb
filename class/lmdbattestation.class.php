<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once __DIR__.'/lmdbattestationversion.class.php';
require_once __DIR__.'/lmdbcompatibility.class.php';

/**
 * Individual attestation. The legal snapshot is immutable after validation.
 * snapshot_data is a legal historical document, never a list of relationships.
 * @phpstan-type AttestationSnapshot array<string,string>
 */
class LmdbAttestation extends CommonObject
{
	const STATUS_DRAFT = 0;
	const STATUS_VALIDATED = 1;
	const STATUS_CANCELED = 9;
	public $module = 'lmdb';
	public $element = 'lmdbattestation';
	public $table_element = 'lmdb_attestation';
	public $TRIGGER_PREFIX = 'LMDB_ATTESTATION';
	public $picto = 'fa-certificate_fa_blue';
	public $ismultientitymanaged = 1;
	public $isextrafieldmanaged = 0;
	public $fk_soc = 0;
	public $socid = 0;
	public $fk_version = 0;
	public $date_attestation;
	public $date_acquisition;
	public $date_start;
	public $client_representative = '';
	public $license_number = '';
	public $snapshot_data = '';
	public $date_validation;
	public $fk_user_valid;
	public $date_creation;
	public $tms;
	// The native mail controller assigns these properties before calling UPDATE.
	public $sendtoid = array();
	public $sendtouserid = array();
	public $attachedfiles = array();
	public $trackid = '';
	public $actiontypecode = '';
	public $actionmsg = '';
	public $actionmsg2 = '';
	public $fk_element;
	public $elementtype;
	public $email_msgid = '';
	public $email_from = '';
	public $email_subject = '';
	public $email_to = '';
	public $email_tocc = '';
	public $email_tobcc = '';
	public $fields = array();

	public function __construct($db)
	{
		$this->db = $db;
		$this->status = self::STATUS_DRAFT;
		$fields = array(
			'rowid'=>array('enabled'=>1,'position'=>1,'type'=>'integer','label'=>'ID','visible'=>0),
			'entity'=>array('enabled'=>1,'position'=>2,'type'=>'integer','label'=>'Entity','visible'=>0),
			'ref'=>array('enabled'=>1,'position'=>3,'type'=>'varchar(128)','label'=>'Ref','visible'=>1,'notnull'=>1,'default'=>'(PROV)'),
			'fk_soc'=>array('enabled'=>1,'position'=>4,'type'=>'integer','label'=>'ThirdParty','visible'=>1,'notnull'=>1),
			'fk_version'=>array('enabled'=>1,'position'=>5,'type'=>'integer','label'=>'LmdbAttestedVersion','visible'=>1,'notnull'=>1),
			'date_attestation'=>array('enabled'=>1,'position'=>6,'type'=>'date','label'=>'LmdbAttestationDate','visible'=>1,'notnull'=>1),
			'date_acquisition'=>array('enabled'=>1,'position'=>7,'type'=>'date','label'=>'LmdbAcquisitionDate','visible'=>1,'notnull'=>0),
			'date_start'=>array('enabled'=>1,'position'=>8,'type'=>'date','label'=>'LmdbUseStartDate','visible'=>1,'notnull'=>0),
			'client_representative'=>array('enabled'=>1,'position'=>9,'type'=>'varchar(255)','label'=>'LmdbClientRepresentative','visible'=>1,'notnull'=>0),
			'license_number'=>array('enabled'=>1,'position'=>10,'type'=>'varchar(128)','label'=>'LmdbLicenseNumber','visible'=>1,'notnull'=>0),
			'status'=>array('enabled'=>1,'position'=>11,'type'=>'integer','label'=>'Status','visible'=>0),
			'snapshot_data'=>array('enabled'=>1,'position'=>12,'type'=>'text','label'=>'LmdbLegalSnapshot','visible'=>0),
			'model_pdf'=>array('enabled'=>1,'position'=>13,'type'=>'varchar(128)','label'=>'Model','visible'=>0),
			'last_main_doc'=>array('enabled'=>1,'position'=>14,'type'=>'varchar(255)','label'=>'Document','visible'=>0),
			'date_creation'=>array('enabled'=>1,'position'=>15,'type'=>'datetime','label'=>'DateCreation','visible'=>0),
			'date_validation'=>array('enabled'=>1,'position'=>16,'type'=>'datetime','label'=>'DateValidation','visible'=>0),
			'tms'=>array('enabled'=>1,'position'=>17,'type'=>'timestamp','label'=>'DateModification','visible'=>0),
			'fk_user_creat'=>array('enabled'=>1,'position'=>18,'type'=>'integer','label'=>'Author','visible'=>0),
			'fk_user_modif'=>array('enabled'=>1,'position'=>19,'type'=>'integer','label'=>'UserModif','visible'=>0),
			'fk_user_valid'=>array('enabled'=>1,'position'=>20,'type'=>'integer','label'=>'User','visible'=>0),
			'note_public'=>array('enabled'=>1,'position'=>21,'type'=>'text','label'=>'NotePublic','visible'=>0),
			'note_private'=>array('enabled'=>1,'position'=>22,'type'=>'text','label'=>'NotePrivate','visible'=>0),
			'import_key'=>array('enabled'=>1,'position'=>23,'type'=>'varchar(14)','label'=>'ImportId','visible'=>0),
		);

		$this->fields = $fields;
	}

	/**
	 * SQL scope shared by lists, fetch, counters and exports. This is business
	 * access orchestration (entity + related third party + commercial assignment).
	 * Functional permissions are checked directly at every entry point.
	 * @return string SQL condition with trusted internal alias t
	 */
	public static function scopeSql($db, User $user)
	{
		if ($user->socid > 0 || !$user->hasRight('societe', 'lire')) { return '1=0'; }
		$sql = 't.entity IN ('.$db->sanitize(getEntity('lmdbattestation')).')';
		$sql .= ' AND t.fk_soc IN (SELECT s.rowid FROM '.MAIN_DB_PREFIX.'societe s WHERE s.entity IN ('.$db->sanitize(getEntity('societe')).')';
		if (!$user->hasRight('societe', 'client', 'voir')) {
			$sql .= ' AND EXISTS (SELECT sc.fk_soc FROM '.MAIN_DB_PREFIX.'societe_commerciaux sc WHERE sc.fk_soc=s.rowid AND sc.fk_user='.((int) $user->id).')';
		}
		return $sql.')';
	}

	public function fetch($id, $ref = null)
	{
		global $user;
		if (!isModEnabled('lmdb') || !LmdbCompatibility::isAttestationSupported() || !$user->hasRight('lmdb', 'lmdbattestation', 'read')) { return -1; }
		$result=$this->fetchCommon((int) $id, $ref, ' AND '.self::scopeSql($this->db, $user));
		if ($result>0) { $this->socid=(int)$this->fk_soc; }
		return $result;
	}

	/** Validate writable inputs using native field metadata and linked objects. @return bool */
	public function validateInputs(User $user)
	{
		global $conf, $langs;
		$this->error = '';
		$this->errors = array();
		$validationerrors = array();
		foreach ($this->fields as $key => $field) {
			if ($field['visible'] && $key !== 'ref' && !$this->validateField($this->fields, $key, (string) $this->{$key})) {
				$message = $langs->trans($field['label']).' : '.$this->getFieldError($key);
				$this->setFieldError($key, $message);
				$validationerrors[] = $message;
			}
		}
		if ($validationerrors) { $this->error = ''; $this->errors = $validationerrors; return false; }
		if ((int) $this->date_attestation <= 0 || (int) $this->fk_soc <= 0 || (int) $this->fk_version <= 0) {
			$this->error = $langs->trans('ErrorBadParameters'); return false;
		}
		$thirdparty = new Societe($this->db);
		if ($user->socid > 0 || !$user->hasRight('societe', 'lire') || $thirdparty->fetch((int) $this->fk_soc) <= 0
			|| !checkUserAccessToObject($user, array('societe'), $thirdparty, 'societe')) {
			$this->error = $langs->trans('NotEnoughPermissions'); return false;
		}
		$version = new LmdbAttestationVersion($this->db);
		if ($version->fetch((int) $this->fk_version) <= 0 || !(int) $version->active) {
			$this->error = $langs->trans('LmdbVersionUnavailable'); return false;
		}
		if ($this->id > 0 && (int) $this->entity !== (int) $conf->entity) {
			$this->error = $langs->trans('LmdbAttestationOwnerOnly'); return false;
		}
		$this->thirdparty = $thirdparty;
		$this->socid = $this->fk_soc;
		return true;
	}

	/** Raw core Ajax field mutation must not bypass the validated domain lifecycle. */
	public function setValueFrom($field, $value, $table = '', $id = null, $format = '', $id_field = '', $fuser = null, $trigkey = '', $fk_user_field = 'fk_user_modif')
	{
		$this->error = 'LmdbAttestationLocked';
		return -1;
	}
	/** Native note callers go through the same draft validation and transaction. */
	public function update_note($note, $suffix = '', $notrigger = 0)
	{
		global $user;
		if (!in_array($suffix,array('_public','_private'),true)) { return -1; }
		$this->{'note'.$suffix}=$note;
		return $this->update($user,$notrigger);
	}

	public function create(User $user, $notrigger = 0)
	{
		global $conf;
		if (!isModEnabled('lmdb') || !LmdbCompatibility::isAttestationSupported() || !$user->hasRight('lmdb', 'lmdbattestation', 'read') || !$user->hasRight('lmdb', 'lmdbattestation', 'write') || !$this->validateInputs($user)) { return -1; }
		$this->entity = (int) $conf->entity;
		$this->ref = '(PROV)';
		$this->status = self::STATUS_DRAFT;
		$this->snapshot_data = '';
		$this->model_pdf = getDolGlobalString('LMDB_ATTESTATION_ADDON_PDF', 'loifinance');
		if (!$this->db->begin()) { $this->error = $this->db->lasterror(); return -1; }
		if (!$this->lockAttestedVersion()) { $this->db->rollback(); return -1; }
		$result = $this->createCommon($user, 1);
		if ($result <= 0 || (!$notrigger && $this->emitCrud('CREATE', 'create', $user) < 0)) { $this->db->rollback(); return -1; }
		if (!$this->db->commit()) { $this->db->rollback(); $this->error = $this->db->lasterror(); return -1; }
		return $this->id;
	}

	/** Serialize reference creation with catalogue deletion/activation changes. @return bool */
	private function lockAttestedVersion()
	{
		global $conf;
		$res = $this->db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'lmdb_attestation_version WHERE rowid='.(int)$this->fk_version.' AND entity='.(int)$conf->entity.' AND active=1 FOR UPDATE');
		if (!$res) { $this->error = $this->db->lasterror(); return false; }
		if (!$this->db->num_rows($res)) { $this->error = 'LmdbVersionUnavailable'; return false; }
		return true;
	}

	/** Lock and reload before checking state: clients cannot forge status or snapshots. @return LmdbAttestation|false */
	private function lockedOriginal(User $user)
	{
		global $conf;
		$res = $this->db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.$this->table_element.' WHERE rowid='.((int) $this->id).' AND entity='.((int) $conf->entity).' FOR UPDATE');
		if (!$res || !$this->db->num_rows($res)) { $this->error = 'ErrorRecordNotFound'; return false; }
		$original = new self($this->db);
		if ($original->fetch($this->id) <= 0) { $this->error = 'NotEnoughPermissions'; return false; }
		return $original;
	}

	public function update(User $user, $notrigger = 0)
	{
		if (!$user->hasRight('lmdb', 'lmdbattestation', 'write') || !$this->validateInputs($user)) { return -1; }
		if (!$this->db->begin()) { $this->error = $this->db->lasterror(); return -1; }
		$old = $this->lockedOriginal($user);
		if (!$old || (int) $old->status !== self::STATUS_DRAFT) { $this->error = 'LmdbAttestationLocked'; $this->db->rollback(); return -1; }
		if (!$this->lockAttestedVersion()) { $this->db->rollback(); return -1; }
		// Only explicitly writable fields are copied to the loaded object.
		foreach (array('fk_soc','fk_version','date_attestation','date_acquisition','date_start','client_representative','license_number','note_public','note_private') as $key) { $old->{$key} = $this->{$key}; }
		$old->oldcopy = new self($this->db);
		$old->oldcopy->fetch($this->id);
		$result = $old->updateCommon($user, 1);
		if ($result <= 0 || (!$notrigger && $old->emitCrud('UPDATE', 'edit', $user) < 0)) { $this->error = $old->error; $this->db->rollback(); return -1; }
		if (!$this->db->commit()) { $this->db->rollback(); $this->error = $this->db->lasterror(); return -1; }
		return $this->fetch($this->id);
	}

	public function validate($user, $notrigger = 0)
	{
		global $conf, $langs, $mysoc;
		if (!$user->hasRight('lmdb', 'lmdbattestation', 'validate')) { return -1; }
		if (!$this->db->begin()) { $this->error = $this->db->lasterror(); return -1; }
		$old = $this->lockedOriginal($user);
		if (!$old || (int) $old->status !== self::STATUS_DRAFT || !$old->validateInputs($user)) { $this->error = $old ? $old->error : $this->error; $this->db->rollback(); return -1; }
		if (!$old->lockAttestedVersion()) { $this->error = $old->error; $this->db->rollback(); return -1; }
		$signer = getDolGlobalString('LMDB_ATTESTATION_SIGNER');
		// Native company data is scoped to the current owner entity.
		$city = (string) $mysoc->town;
		$signature = getDolGlobalString('LMDB_ATTESTATION_SIGNATURE');
		$root = isset($conf->lmdb->multidir_output[$old->entity]) ? $conf->lmdb->multidir_output[$old->entity] : '';
		if (!$signer || !getDolGlobalString('LMDB_ATTESTATION_SIGNER_ROLE') || !$city || !$signature || !$root || $signature !== basename($signature) || !is_file($root.'/attestation_settings/'.$signature)) {
			$this->error = $langs->trans('LmdbAttestationSetupRequired'); $this->db->rollback(); return -1;
		}
		$version = new LmdbAttestationVersion($this->db);
		if ($version->fetch($old->fk_version) <= 0) { $this->db->rollback(); return -1; }
		$old->oldcopy = clone $old;
		$ref = $old->getNextNumRef($old->thirdparty);
		if (!$ref) { $this->error = $old->error; $this->db->rollback(); return -1; }
		$old->ref = $ref;
		$dir = $old->documentDirectory(true);
		if ($dir === false || dol_mkdir($dir.'/private') < 0 || dol_copy($root.'/attestation_settings/'.$signature, $dir.'/private/signature.png', '', 0) <= 0) {
			$this->error = $langs->trans('LmdbDocumentDirectoryUnavailable'); $this->db->rollback(); return -1;
		}
		$snapshot = array(
			'schema'=>'1', 'legal_model'=>'BOI-LETTRE-000242-20260325',
			'issuer_name'=>(string) $mysoc->name, 'issuer_address'=>(string) $mysoc->address,
			'issuer_zip'=>(string) $mysoc->zip, 'issuer_town'=>(string) $mysoc->town,
			'issuer_id'=>(string) $mysoc->idprof1, 'signer'=>$signer,
			'signer_role'=>getDolGlobalString('LMDB_ATTESTATION_SIGNER_ROLE'), 'city'=>$city,
			'client_name'=>(string) $old->thirdparty->name, 'client_address'=>(string) $old->thirdparty->address,
			'client_zip'=>(string) $old->thirdparty->zip, 'client_town'=>(string) $old->thirdparty->town,
			'client_id'=>(string) $old->thirdparty->idprof1, 'version'=>(string) $version->version,
			'date_release'=>(string) $version->date_release, 'covered_scope'=>(string) $version->covered_scope,
			'excluded_scope'=>(string) $version->excluded_scope, 'signature_file'=>'private/signature.png',
			'date_attestation'=>(string) $old->date_attestation, 'date_acquisition'=>(string) $old->date_acquisition,
			'date_start'=>(string) $old->date_start, 'client_representative'=>(string) $old->client_representative,
			'license_number'=>(string) $old->license_number,
		);
		try { $old->snapshot_data = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
		catch (JsonException $e) { $this->error='LmdbInvalidSnapshot'; $this->db->rollback(); dol_delete_file($dir.'/private/signature.png'); return -1; }
		$old->status = self::STATUS_VALIDATED;
		$old->date_validation = dol_now();
		$old->fk_user_valid = $user->id;
		$result = $old->updateCommon($user, 1);
		if ($result <= 0 || (!$notrigger && $old->emitCrud('UPDATE', 'validation', $user) < 0)) { $this->error = $old->error; $this->db->rollback(); dol_delete_file($dir.'/private/signature.png'); return -1; }
		if (!$this->db->commit()) { $this->db->rollback(); $this->error = $this->db->lasterror(); dol_delete_file($dir.'/private/signature.png'); return -1; }
		return $this->fetch($this->id);
	}

	public function cancel(User $user)
	{
		if (!$user->hasRight('lmdb', 'lmdbattestation', 'cancel')) { return -1; }
		if (!$this->db->begin()) { $this->error = $this->db->lasterror(); return -1; }
		$old = $this->lockedOriginal($user);
		if (!$old || (int) $old->status !== self::STATUS_VALIDATED) { $this->error = 'LmdbAttestationLocked'; $this->db->rollback(); return -1; }
		$old->oldcopy = clone $old;
		$old->status = self::STATUS_CANCELED;
		if ($old->updateCommon($user, 1) <= 0 || $old->emitCrud('UPDATE', 'cancellation', $user) < 0) { $this->error = $old->error; $this->db->rollback(); return -1; }
		if (!$this->db->commit()) { $this->db->rollback(); $this->error = $this->db->lasterror(); return -1; }
		return $this->fetch($this->id);
	}

	public function delete(User $user, $notrigger = 0)
	{
		if (!$user->hasRight('lmdb', 'lmdbattestation', 'delete')) { return -1; }
		if (!$this->db->begin()) { $this->error = $this->db->lasterror(); return -1; }
		$old = $this->lockedOriginal($user);
		if (!$old || (int) $old->status !== self::STATUS_DRAFT) { $this->error = 'LmdbAttestationLocked'; $this->db->rollback(); return -1; }
		if ((!$notrigger && $old->emitCrud('DELETE', 'delete', $user) < 0) || $old->delete_linked_contact() < 0 || $old->deleteCommon($user, 1) <= 0) { $this->error = $old->error; $this->db->rollback(); return -1; }
		if (!$this->db->commit()) { $this->db->rollback(); $this->error = $this->db->lasterror(); return -1; }
		return 1;
	}

	/** Allowed native contact IDs, scoped to the owner/thirdparty, without N+1 queries.
	 * @param string $source internal or external
	 * @return array<int,bool>|false
	 */
	public function accessibleContactIds($source)
	{
		global $user;
		if (!$user->hasRight('lmdb','lmdbattestation','read')) { return array(); }
		if ($source === 'external') {
			if (!$user->hasRight('societe', 'contact', 'lire')) { return array(); }
			$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'socpeople WHERE fk_soc='.(int)$this->fk_soc.' AND entity IN ('.$this->db->sanitize(getEntity('contact')).')';
		} elseif ($source === 'internal') {
			// Same entity contract as the native user selector, including transverse mode.
			$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'user WHERE (fk_soc IS NULL OR fk_soc=0) AND ';
			if (isModEnabled('multicompany') && getDolGlobalInt('MULTICOMPANY_TRANSVERSE_MODE')) {
				$sql .= 'rowid IN (SELECT fk_user FROM '.MAIN_DB_PREFIX.'usergroup_user WHERE entity IN ('.$this->db->sanitize(getEntity('usergroup')).'))';
			} else { $sql .= 'entity IN (0,'.$this->db->sanitize(getEntity('user')).')'; }
		} else { return array(); }
		$res = $this->db->query($sql);
		if (!$res) { $this->error = $this->db->lasterror(); return false; }
		$ids = array();
		while (is_object($row = $this->db->fetch_object($res))) { $ids[(int)$row->rowid] = true; }
		return $ids;
	}

	/** Native links filtered before they reach templates, mail recipients or counters.
	 * @return array|int Native contact rows/IDs, or -1 on failure
	 */
	public function liste_contact($statusoflink = -1, $source = 'external', $list = 0, $code = '', $status = -1, $arrayoftcids = array())
	{
		global $user;
		$loaded = clone $this;
		if (!$user->hasRight('lmdb', 'lmdbattestation', 'read') || $loaded->fetch($this->id) <= 0) { return array(); }
		$source = $source === 'thirdparty' ? 'external' : $source;
		$allowed = $loaded->accessibleContactIds($source);
		if ($allowed === false) { $this->error = $loaded->error; return -1; }
		if (!$allowed) { return array(); }
		$rows = parent::liste_contact($statusoflink, $source, $list, $code, $status, $arrayoftcids);
		if (!is_array($rows)) { return -1; }
		return array_values(array_filter($rows, static function ($row) use ($list, $allowed) { return isset($allowed[(int)($list ? $row : $row['id'])]); }));
	}

	/** Change a native element_contact link, preserving the immutable legal snapshot.
	 * @param string $operation add, toggle or unlink
	 * @param int $contactid Contact or user ID (add only)
	 * @param int $typeid Native role ID (add only)
	 * @param string $source internal or external
	 * @param int $linkid Native link ID (toggle/unlink)
	 * @return int >0 on success, 0 for duplicate, <0 on failure
	 */
	public function changeContact($operation, $contactid, $typeid, $source, $linkid = 0)
	{
		global $user;
		if (!$user->hasRight('lmdb', 'lmdbattestation', 'write') || !in_array($operation, array('add','toggle','unlink'), true)) { $this->error = 'NotEnoughPermissions'; return -1; }
		if (!$this->db->begin()) { $this->error = $this->db->lasterror(); return -1; }
		$old = $this->lockedOriginal($user);
		if (!$old) { $this->db->rollback(); return -1; }
		$link = null;
		if ($operation !== 'add') {
			$res = $this->db->query('SELECT ec.fk_socpeople,ec.statut,tc.source,tc.rowid AS typeid FROM '.MAIN_DB_PREFIX.'element_contact ec JOIN '.MAIN_DB_PREFIX."c_type_contact tc ON tc.rowid=ec.fk_c_type_contact WHERE tc.element='lmdbattestation' AND ec.element_id=".(int)$old->id.' AND ec.rowid='.(int)$linkid.' FOR UPDATE');
			$link = $res ? $this->db->fetch_object($res) : null;
			if (!is_object($link)) { $this->error = 'ErrorRecordNotFound'; $this->db->rollback(); return -1; }
			$source = $link->source; $contactid = (int)$link->fk_socpeople; $typeid = (int)$link->typeid;
		}
		$allowed = $old->accessibleContactIds($source);
		$roles = $old->liste_type_contact($source, 'position', 0, $operation === 'add' ? 1 : 0);
		if ($allowed === false || !isset($allowed[(int)$contactid]) || !is_array($roles) || !isset($roles[(int)$typeid])) { $this->error = 'NotEnoughPermissions'; $this->db->rollback(); return -1; }
		$old->oldcopy = clone $old;
		if ($operation === 'add') { $result = $old->add_contact($contactid, $typeid, $source, 1); }
		elseif ($operation === 'unlink') { $result = $old->delete_contact($linkid, 1); }
		else { $result = $old->update_contact($linkid, (int)$link->statut === 4 ? 5 : 4); if ($result >= 0) { $result = 1; } }
		$old->context['contact_operation'] = $operation;
		if ($result < 0 || ($result > 0 && $old->emitCrud('UPDATE', 'contacts', $user) < 0)) { $this->error = $old->error; $this->errors = $old->errors; $this->db->rollback(); return -1; }
		if (!$this->db->commit()) { $this->error = $this->db->lasterror(); $this->db->rollback(); return -1; }
		return $result;
	}

	/** Native numbering, serialized by an entity-level lock in the numbering model. @return string */
	public function getNextNumRef($soc = null)
	{
		$model = getDolGlobalString('LMDB_ATTESTATION_ADDON', 'mod_lmdbattestation_standard');
		if (!in_array($model, array('mod_lmdbattestation_standard', 'mod_lmdbattestation_advanced'), true)) { $this->error = 'LmdbNumberingModelUnavailable'; return ''; }
		require_once __DIR__.'/../core/modules/lmdb/'.$model.'.php';
		$numbering = new $model();
		return $numbering->getNextValue($soc, $this);
	}

	/** @return int Trigger result. Context texts never survive an operation. */
	public function emitCrud($crud, $reason, User $user)
	{
		global $langs;
		$langs->load('lmdb@lmdb');
		$this->context['trigger_reason'] = $reason;
		$this->context['actionmsg2'] = $langs->transnoentities('LmdbAttestationEvent_'.$reason, $this->ref);
		$this->context['actionmsg'] = $this->context['actionmsg2'];
		try { return $this->call_trigger($this->TRIGGER_PREFIX.'_'.$crud, $user); }
		finally { unset($this->context['actionmsg'], $this->context['actionmsg2']); $this->actionmsg = ''; $this->actionmsg2 = ''; }
	}

	/** @return array<string,string> Legal snapshot with explicitly string values. */
	public function legalSnapshot()
	{
		$data = json_decode((string) $this->snapshot_data, true);
		if (!is_array($data) || !isset($data['schema']) || $data['schema'] !== '1') { throw new RuntimeException('LmdbInvalidSnapshot'); }
		foreach ($data as $value) { if (!is_string($value)) { throw new RuntimeException('LmdbInvalidSnapshot'); } }
		return $data;
	}

	/**
	 * Canonical object directory, never another entity's fallback. No symlink is
	 * accepted below the trusted configured root. @return string|false
	 */
	public function documentDirectory($create = false)
	{
		global $conf;
		$entity = (int) $this->entity;
		$root = isset($conf->lmdb->multidir_output[$entity]) ? $conf->lmdb->multidir_output[$entity] : null;
		if ($this->id <= 0 || !is_string($root) || trim($root) === '' || $this->ref === '' || $this->ref !== dol_sanitizeFileName($this->ref)) { $this->error = 'LmdbDocumentDirectoryUnavailable'; return false; }
		$base = getMultidirOutput($this, 'lmdb', 1);
		if (!is_string($base) || $base === '' || strpos($base, 'error-') === 0) { $this->error = 'LmdbDocumentDirectoryUnavailable'; return false; }
		$path = rtrim($base, '/');
		if (basename($path) !== $this->ref) { $this->error = 'LmdbDocumentDirectoryUnavailable'; return false; }
		if (is_link($path) || is_link($path.'/private')) { $this->error = 'LmdbDocumentDirectoryUnavailable'; return false; }
		if ($create && dol_mkdir($path) < 0) { $this->error = 'LmdbDocumentDirectoryUnavailable'; return false; }
		return $path;
	}

	public function generateDocument($modele, $outputlangs, $hidedetails = 0, $hidedesc = 0, $hideref = 0, $moreparams = null)
	{
		global $user, $conf;
		if (!$user->hasRight('lmdb', 'lmdbattestation', 'generate') || $this->fetch($this->id) <= 0 || (int)$this->entity !== (int)$conf->entity || (int) $this->status !== self::STATUS_VALIDATED || $modele !== 'loifinance') { $this->error = 'LmdbAttestationLocked'; return -1; }
		require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
		$models = getListOfModels($this->db, 'lmdbattestation');
		if (!is_array($models) || !isset($models[$modele])) { $this->error = 'LmdbAttestationPdfUnavailable'; return -1; }
		$result=$this->commonGenerateDocument('core/modules/lmdb/doc/', $modele, $outputlangs, $hidedetails, $hidedesc, $hideref, $moreparams);
		if ($result>0 && $this->emitCrud('UPDATE','document',$user)<0) { return -1; }
		return $result;
	}

	/** Native Ajax tooltip, reloaded through the same business access scope.
	 * @param array<string,mixed> $params Native tooltip parameters
	 * @return array<string,string> Escaped HTML consumed by CommonObject
	 */
	public function getTooltipContentArray($params)
	{
		global $user, $langs;
		if (!$user->hasRight('lmdb', 'lmdbattestation', 'read')) { return array(); }
		$loaded = clone $this;
		if ($this->id <= 0 || $loaded->fetch($this->id) <= 0 || $loaded->fetch_thirdparty() <= 0) { return array(); }
		$langs->loadLangs(array('lmdb@lmdb', 'companies', 'other', 'dict'));
		$snapshot = array();
		if ((int)$loaded->status !== self::STATUS_DRAFT) {
			try { $snapshot = $loaded->legalSnapshot(); }
			catch (RuntimeException $e) { return array('error'=>dol_escape_htmltag($langs->trans($e->getMessage()))); }
		}
		$values = array('Ref'=>$loaded->ref, 'ThirdParty'=>$snapshot['client_name'] ?? $loaded->thirdparty->name);
		if ($snapshot) {
			$values['LmdbAttestedVersion'] = $snapshot['version'] ?? '';
			$values['LmdbReleaseDate'] = (int)($snapshot['date_release'] ?? 0) > 0 ? dol_print_date((int)$snapshot['date_release'], 'day') : '';
		} else {
			// A shared draft may expose its attached version, never another catalogue entry.
			$res = $this->db->query('SELECT version,date_release,covered_scope,excluded_scope FROM '.MAIN_DB_PREFIX.'lmdb_attestation_version WHERE rowid='.(int)$loaded->fk_version.' AND entity='.(int)$loaded->entity);
			if (!$res || !is_object($release = $this->db->fetch_object($res))) { return array(); }
			$values['LmdbAttestedVersion'] = $release->version;
			$values['LmdbReleaseDate'] = dol_print_date($this->db->jdate($release->date_release), 'day');
			$snapshot['covered_scope'] = $release->covered_scope;
			$snapshot['excluded_scope'] = $release->excluded_scope;
		}
		foreach (array('date_attestation'=>'LmdbAttestationDate', 'date_acquisition'=>'LmdbAcquisitionDate', 'date_start'=>'LmdbUseStartDate', 'date_creation'=>'DateCreation', 'date_validation'=>'DateValidation') as $key=>$label) {
			$values[$label] = $loaded->{$key} ? dol_print_date($loaded->{$key}, 'day') : '';
		}
		$values['LmdbClientRepresentative'] = $snapshot['client_representative'] ?? $loaded->client_representative;
		$values['LmdbLicenseNumber'] = $snapshot['license_number'] ?? $loaded->license_number;
		if (isset($snapshot['issuer_name'])) {
			$values['LmdbIssuerSettings'] = $snapshot['issuer_name'];
			$values['Address'] = implode(', ', array($snapshot['issuer_address'] ?? '', trim(($snapshot['issuer_zip'] ?? '').' '.($snapshot['issuer_town'] ?? ''))));
			$values['ProfId1'] = $snapshot['issuer_id'] ?? '';
			$values['LmdbIssuerRepresentative'] = $snapshot['signer'] ?? '';
			$values['LmdbSignerRole'] = $snapshot['signer_role'] ?? '';
			$values['Town'] = $snapshot['city'] ?? '';
		}
		$values['LmdbCoveredScope'] = $snapshot['covered_scope'] ?? '';
		$values['LmdbExcludedScope'] = $snapshot['excluded_scope'] ?? '';
		if (isset($snapshot['client_address'])) {
			$values['ThirdParty'] .= "\n".$snapshot['client_address']."\n".trim(($snapshot['client_zip'] ?? '').' '.($snapshot['client_town'] ?? ''))."\n".($snapshot['client_id'] ?? '');
		}
		$values['NotePublic'] = (string)$loaded->note_public;
		$data = array('picto'=>img_picto('', $loaded->picto).' <b>'.dol_escape_htmltag($langs->trans('LmdbAttestation')).'</b> '.$loaded->getLibStatut(5));
		foreach ($values as $label=>$value) {
			$data[$label] = '<br><b>'.dol_escape_htmltag($langs->trans($label)).' :</b> '.($value !== '' ? dol_nl2br(dol_escape_htmltag((string)$value, 0, 1)) : '—');
		}
		return $data;
	}

	/** Native Form::showphoto contract for the main PDF's first-page preview.
	 * @param string $modulepart Document module part
	 * @param string $imagesize Native image size
	 * @return array{dir:string,file:string,originalfile:string,altfile:string,email:string,capture:string}
	 */
	public function getDataToShowPhoto($modulepart, $imagesize)
	{
		global $user;
		$empty = array('dir'=>'', 'file'=>'', 'originalfile'=>'', 'altfile'=>'', 'email'=>'', 'capture'=>'');
		if ($modulepart !== 'lmdb' || !$user->hasRight('lmdb', 'lmdbattestation', 'read') || !LmdbCompatibility::isFeatureAvailable('attestation_pdf_preview')) { return $empty; }
		$loaded = clone $this;
		if ($this->id <= 0 || $loaded->fetch($this->id) <= 0) { return $empty; }
		$directory = $loaded->documentDirectory(false);
		if ($directory === false) { return $empty; }
		$filename = $loaded->ref.'.pdf';
		$pdf = $directory.'/'.$filename;
		$preview = $pdf.'_preview.png';
		if (!is_file($pdf) || is_link($pdf) || is_link($preview)) { return $empty; }
		if (!is_file($preview) || filemtime($preview) < filemtime($pdf)) {
			if (dol_convert_file($pdf, 'png', $preview, '0') <= 0 || !is_file($preview)) { return $empty; }
		}
		return array('dir'=>dirname($directory), 'file'=>$loaded->ref.'/'.$filename.'_preview.png', 'originalfile'=>$loaded->ref.'/'.$filename, 'altfile'=>'', 'email'=>'', 'capture'=>'');
	}

	public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0, $morecss = '', $save_lastsearch_value = -1)
	{
		global $conf, $user, $langs;
		$attributes = '';
		if (!$notooltip && empty($conf->dol_no_mouse_hover) && $user->hasRight('lmdb', 'lmdbattestation', 'read')) {
			if (getDolGlobalInt('MAIN_ENABLE_AJAX_TOOLTIP')) {
				$morecss .= ' classforajaxtooltip';
				$attributes = ' title="tocomplete" data-params="'.dol_escape_htmltag(json_encode(array('id'=>(int)$this->id, 'objecttype'=>'lmdbattestation@lmdb'))).'"';
			} else {
				// No extra per-row queries when native Ajax tooltips are disabled.
				$morecss .= ' classfortooltip';
				$attributes = ' title="'.dol_escape_htmltag($this->ref.' - '.$this->getLibStatut(0)).'"';
			}
		}
		return '<a class="'.dol_escape_htmltag(trim($morecss)).'"'.$attributes.' href="'.dol_buildpath('/lmdb/attestation_card.php', 1).'?id='.(int) $this->id.'">'.($withpicto ? img_picto('', $this->picto).' ' : '').($withpicto == 2 ? '' : dol_escape_htmltag($this->ref)).'</a>';
	}
	public function getLibStatut($mode = 0) { return $this->LibStatut($this->status, $mode); }
	public function LibStatut($status, $mode = 0)
	{
		global $langs;
		$labels = array(0=>'Draft',1=>'Validated',9=>'Canceled');
		$label = $langs->trans(isset($labels[$status]) ? $labels[$status] : 'Unknown');
		return dolGetStatus($label, $label, '', 'status'.((int) $status === 1 ? '4' : ((int) $status === 9 ? '6' : '0')), $mode);
	}
}
