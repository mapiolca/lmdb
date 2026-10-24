<?php
/* Copyright (C) 2026 Pierre Ardoin. SPDX-License-Identifier: GPL-3.0-or-later */
/** Native attestation tabs, completed by the standard module tab mechanism.
 * @return array<int,array{0:string,1:string,2:string}>
 */
function lmdbAttestationPrepareHead($object)
{
	global $langs, $conf, $user;
	$head = array();
	foreach (array('card'=>'Card', 'contact'=>'ContactsAddresses', 'note'=>'Notes', 'document'=>'Documents', 'agenda'=>'LmdbAttestationEvents') as $tab=>$key) {
		if ($tab === 'agenda' && (!isModEnabled('agenda') || (!$user->hasRight('agenda','myactions','read') && !$user->hasRight('agenda','allactions','read')))) { continue; }
		$head[] = array(dol_buildpath('/lmdb/attestation_'.$tab.'.php',1).'?id='.(int)$object->id, $langs->trans($key), $tab);
	}
	$h = count($head);
	complete_head_from_modules($conf, $langs, $object, $head, $h, 'lmdbattestation@lmdb');
	return $head;
}

/** Render native banner and fiche head consistently on all tabs. @return void */
function lmdbAttestationHeader($object, $tab)
{
	global $langs;
	llxHeader('', $langs->trans('LmdbAttestation'));
	print dol_get_fiche_head(lmdbAttestationPrepareHead($object), $tab, $langs->trans('LmdbAttestation'), -1, $object->picto);
	$back = '<a href="'.dol_buildpath('/lmdb/attestation_list.php',1).'">'.$langs->trans('BackToList').'</a>';
	dol_banner_tab($object, 'ref', $back, 1, 'ref', 'ref', '<div class="refidno">'.$object->thirdparty->getNomUrl(1).'</div>');
}

/**
 * Canonical file authorization, also used by the native download/upload hooks.
 * This checks object ownership and traversal; permission checks stay in callers.
 * @return bool
 */
function lmdbAttestationFileBelongsToObject($object, $path)
{
	$dir = $object->documentDirectory(false);
	if ($dir === false || strpos($path, $dir.'/') !== 0 || strpos($path, '..') !== false || strpos($path, '/private/') !== false || is_link($path)) { return false; }
	$parent = realpath(dirname($path));
	$base = realpath($dir);
	return $parent !== false && $base !== false && ($parent === $base || strpos($parent, $base.'/') === 0);
}
