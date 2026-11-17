<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 * SPDX-License-Identifier: GPL-3.0-or-later */
require_once __DIR__.'/mod_lmdbattestation_standard.php';
/** Configurable mask with the same allocation and entity guarantees as Standard. */
class mod_lmdbattestation_advanced extends mod_lmdbattestation_standard
{
	public $name = 'Advanced';
	public function __construct()
	{
		$this->mask = getDolGlobalString('LMDB_ATTESTATION_MASK', 'ATLF-{yyyy}-{0000}');
	}
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
}
