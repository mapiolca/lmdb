<?php
/* Copyright (C) 2026 Pierre Ardoin. SPDX-License-Identifier: GPL-3.0-or-later */
require_once DOL_DOCUMENT_ROOT.'/core/class/commonnumrefgenerator.class.php';
/** Native mask numbering; SELECT ... FOR UPDATE on the entity's module constant
 * serializes allocation until the caller commits, also for shared numbering. */
class mod_lmdbattestation_standard extends CommonNumRefGenerator
{
	public $name = 'Standard';
	public $version = 'dolibarr';
	/** @var string Fixed numbering format; Advanced supplies its configured mask. */
	protected $mask = 'ATLF-{yyyy}-{0000}';
	public function info($langs) { return $langs->trans('LmdbAttestationNumbering'); }
	/** Read-only preview: same mask and entity scope as allocation, without a lock. */
	public function getExample()
	{
		global $db, $langs;
		$sample = new LmdbAttestation($db);
		$sample->specimen = 1;
		$sample->date_attestation = dol_now();
		$thirdparty = new Societe($db);
		$thirdparty->initAsSpecimen();
		$value = $this->getNextValue($thirdparty, $sample);
		return $value !== '' ? $value : $langs->trans($sample->error ?: 'NotConfigured');
	}
	public function canBeActivated($object = null) { return true; }
	public function getNextValue($soc, $object)
	{
		global $db;
		$entities = array_unique(array_merge(explode(',', getEntity('lmdbattestation')), explode(',', getEntity('lmdbattestationnumber', 1, $object))));
		$entities = array_filter(array_map('intval', $entities), static function ($id) { return $id > 0; });
		sort($entities, SORT_NUMERIC);
		// A stable installed constant in each numbering entity is our native row lock.
		if (empty($object->specimen)) {
			$res = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."const WHERE name='MAIN_MODULE_LMDB' AND entity IN (".implode(',', $entities).") ORDER BY entity FOR UPDATE");
			if (!$res || !$db->num_rows($res)) { $object->error = 'LmdbNumberingLockUnavailable'; return ''; }
		}
		require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
		$value = get_next_value($db, $this->mask, 'lmdb_attestation', 'ref', '', $soc, $object->date_attestation, 'next', false, null, implode(',', $entities));
		if ($value === '' || preg_match('/^(Error|error)/', $value) || $value !== dol_sanitizeFileName($value)) { $object->error = 'LmdbNumberingModelUnavailable'; return ''; }
		return $value;
	}
}
