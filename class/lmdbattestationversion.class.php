<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/** Release catalogue. Administrators explicitly approve the attested scope. */
class LmdbAttestationVersion extends CommonObject
{
	public $module = 'lmdb';
	public $element = 'lmdbattestationversion';
	public $table_element = 'lmdb_attestation_version';
	public $ismultientitymanaged = 1;
	public $version = '';
	public $date_release;
	public $covered_scope = '';
	public $excluded_scope = '';
	public $active = 0;
	protected $childtables = array('lmdb_attestation' => array('name'=>'LmdbAttestation', 'fk_element'=>'fk_version', 'parent'=>'lmdb_attestation_version', 'parentkey'=>'fk_version'));
	public $fields = array(
		'rowid' => array('type'=>'integer', 'label'=>'ID', 'enabled'=>1, 'visible'=>0, 'position'=>1),
		'entity' => array('type'=>'integer', 'label'=>'Entity', 'enabled'=>1, 'visible'=>0, 'position'=>2),
		'version' => array('type'=>'varchar(128)', 'label'=>'LmdbAttestedVersion', 'enabled'=>1, 'visible'=>1, 'position'=>10, 'notnull'=>1),
		'date_release' => array('type'=>'date', 'label'=>'LmdbReleaseDate', 'enabled'=>1, 'visible'=>1, 'position'=>20, 'notnull'=>1),
		'covered_scope' => array('type'=>'text', 'label'=>'LmdbCoveredScope', 'enabled'=>1, 'visible'=>1, 'position'=>30, 'notnull'=>1),
		'excluded_scope' => array('type'=>'text', 'label'=>'LmdbExcludedScope', 'enabled'=>1, 'visible'=>1, 'position'=>40, 'notnull'=>0),
		'active' => array('type'=>'integer', 'label'=>'Active', 'enabled'=>1, 'visible'=>1, 'position'=>50, 'notnull'=>1, 'default'=>0),
	);

	public function __construct($db) { $this->db = $db; }

	/** @return int Positive when found, <=0 otherwise. Catalogue is entity-private. */
	public function fetch($id, $ref = null)
	{
		global $conf;
		return $this->fetchCommon((int) $id, null, ' AND t.entity = '.((int) $conf->entity));
	}

	/** Persist a catalogue entry without a separate business event. @return int */
	public function save(User $user)
	{
		global $conf, $langs;
		if (!$user->admin || !$user->hasRight('lmdb', 'setup', 'configure')) {
			$this->error = 'NotEnoughPermissions';
			return -1;
		}
		$this->error = '';
		$this->errors = array();
		$validationerrors = array();
		foreach ($this->fields as $key => $field) {
			if ($field['visible'] && !$this->validateField($this->fields, $key, (string) $this->{$key})) {
				$message = $langs->trans($field['label']).' : '.$this->getFieldError($key);
				$this->setFieldError($key, $message);
				$validationerrors[] = $message;
			}
		}
		if ($validationerrors) { $this->error = ''; $this->errors = $validationerrors; return -1; }
		if (!$this->date_release || !in_array((int) $this->active, array(0, 1), true)) {
			$this->error = 'ErrorBadParameters';
			return -1;
		}
		$this->entity = (int) $conf->entity;
		return $this->id > 0 ? $this->updateCommon($user, 1) : $this->createCommon($user, 1);
	}

	/** Change only the activation state; never overwrite catalogue content. @return int */
	public function setActive(User $user, $active)
	{
		global $conf, $langs;
		if (!isModEnabled('lmdb') || !$user->admin || $user->socid > 0 || !$user->hasRight('lmdb', 'setup', 'configure')) { $this->error = 'NotEnoughPermissions'; return -1; }
		if (!in_array($active, array(0, 1), true) || $this->id <= 0 || $this->fetch($this->id) <= 0) { $this->error = 'ErrorBadParameters'; return -1; }
		if ($active === 1 && !$this->validateField($this->fields, 'covered_scope', $this->covered_scope)) { $this->error = $langs->trans('LmdbCoveredScope').' : '.$this->getFieldError('covered_scope'); return -1; }
		if (!$this->db->query('UPDATE '.MAIN_DB_PREFIX.$this->table_element.' SET active='.$active.' WHERE rowid='.(int)$this->id.' AND entity='.(int)$conf->entity)) { $this->error = $this->db->lasterror(); return -1; }
		$this->active = $active;
		return 1;
	}

	/** Delete only an unused version, including draft/cancelled attestation links. @return int */
	public function delete(User $user, $notrigger = 0)
	{
		global $conf;
		if (!isModEnabled('lmdb') || !$user->admin || $user->socid > 0 || !$user->hasRight('lmdb', 'setup', 'configure')) { $this->error = 'NotEnoughPermissions'; return -1; }
		if ($this->id <= 0) { $this->error = 'ErrorBadParameters'; return -1; }
		if (!$this->db->begin()) { $this->error = $this->db->lasterror(); return -1; }
		$res = $this->db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.$this->table_element.' WHERE rowid='.(int)$this->id.' AND entity='.(int)$conf->entity.' FOR UPDATE');
		if (!$res || !$this->db->num_rows($res)) { $this->error = 'ErrorRecordNotFound'; $this->db->rollback(); return -1; }
		// Native child-table detection filters the catalogue owner. Even an
		// anomalous cross-entity attestation link blocks deletion without disclosure.
		$used = $this->isObjectUsed($this->id, (int)$conf->entity);
		if ($used !== 0) { $this->error = $used > 0 ? 'LmdbAttestedVersionInUse' : $this->db->lasterror(); if ($used > 0) { $this->errors = array(); } $this->db->rollback(); return -1; }
		// Catalogue entries own no documents/ECM/links. deleteCommon() would run
		// that unrelated lifecycle and remove the module directory for an empty ref.
		if (!$this->db->query('DELETE FROM '.MAIN_DB_PREFIX.$this->table_element.' WHERE rowid='.(int)$this->id.' AND entity='.(int)$conf->entity)) { $this->error = $this->db->lasterror(); $this->db->rollback(); return -1; }
		if (!$this->db->commit()) { $this->error = $this->db->lasterror(); $this->db->rollback(); return -1; }
		return 1;
	}

	/** @return array<int,string>|false Active versions, or the currently selected inactive version. */
	public static function choices($db, $entity, $selected = 0)
	{
		$sql = 'SELECT rowid, version FROM '.MAIN_DB_PREFIX.'lmdb_attestation_version WHERE entity = '.((int) $entity);
		$sql .= ' AND (active = 1 OR rowid = '.((int) $selected).') ORDER BY version DESC';
		$res = $db->query($sql);
		if (!$res) { return false; }
		$choices = array();
		while (is_object($row = $db->fetch_object($res))) { $choices[(int) $row->rowid] = (string) $row->version; }
		return $choices;
	}
}
