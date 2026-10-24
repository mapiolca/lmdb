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
		global $conf;
		if (!$user->admin || !$user->hasRight('lmdb', 'setup', 'configure')) {
			$this->error = 'NotEnoughPermissions';
			return -1;
		}
		foreach ($this->fields as $key => $field) {
			if ($field['visible'] && !$this->validateField($this->fields, $key, (string) $this->{$key})) {
				return -1;
			}
		}
		if (!$this->date_release || !in_array((int) $this->active, array(0, 1), true)) {
			$this->error = 'ErrorBadParameters';
			return -1;
		}
		$this->entity = (int) $conf->entity;
		return $this->id > 0 ? $this->updateCommon($user, 1) : $this->createCommon($user, 1);
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
