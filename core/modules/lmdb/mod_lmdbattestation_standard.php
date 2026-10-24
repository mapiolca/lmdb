<?php
/* Copyright (C) 2026 Pierre Ardoin. SPDX-License-Identifier: GPL-3.0-or-later */
require_once DOL_DOCUMENT_ROOT.'/core/class/commonnumrefgenerator.class.php';
/** Native mask numbering; SELECT ... FOR UPDATE on the entity's module constant
 * serializes allocation until the caller commits, also for shared numbering. */
class mod_lmdbattestation_standard extends CommonNumRefGenerator
{
	public $name = 'standard';
	public $version = 'dolibarr';
	/** Native mask editor, as in the core's configurable numbering models. */
	public function info($langs)
	{
		global $db;
		$langs->loadLangs(array('admin', 'lmdb@lmdb'));
		$form = new Form($db);
		$help = $langs->trans('GenericMaskCodes', $langs->transnoentities('LmdbAttestation'), $langs->transnoentities('LmdbAttestation'));
		// Older core catalogs include the date codes in GenericMaskCodes itself.
		$datecodes = $langs->trans('GenericMaskCodes1');
		if ($datecodes !== 'GenericMaskCodes1') { $help .= $datecodes; }
		$help .= '<br>'.$langs->trans('GenericMaskCodes2').'<br>'.$langs->trans('GenericMaskCodes3');
		$text = $langs->trans('GenericNumRefModelDesc').'<br>';
		$text .= '<form method="post" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
		$text .= '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="updateMask">';
		$text .= '<table class="nobordernopadding centpercent"><tr><td>'.$langs->trans('Mask').':</td><td class="right nowraponall">';
		$text .= $form->textwithpicto('<input type="text" class="flat minwidth175" name="maskattestation" aria-label="'.dol_escape_htmltag($langs->trans('Mask')).'" maxlength="128" value="'.dol_escape_htmltag(getDolGlobalString('LMDB_ATTESTATION_MASK')).'">', $help, 1, 'help', 'valignmiddle', 0, 3, $this->name);
		$text .= '</td><td class="left"><input type="submit" class="button button-edit reposition smallpaddingimp" value="'.$langs->trans('Save').'"></td></tr></table></form>';
		return $text;
	}
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
		$value = get_next_value($db, getDolGlobalString('LMDB_ATTESTATION_MASK', 'ATLF-{yyyy}-{0000}'), 'lmdb_attestation', 'ref', '', $soc, $object->date_attestation, 'next', false, null, implode(',', $entities));
		if ($value === '' || preg_match('/^(Error|error)/', $value) || $value !== dol_sanitizeFileName($value)) { $object->error = 'LmdbNumberingModelUnavailable'; return ''; }
		return $value;
	}
}
