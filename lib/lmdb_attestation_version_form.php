<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 * SPDX-License-Identifier: GPL-3.0-or-later */
/** @var Conf $conf */
/** @var Translate $langs */
/** @var Form $form */
/** @var LmdbAttestationVersion $version */
global $conf, $langs, $form, $version;

$versiontitle = $langs->trans($version->id > 0 ? 'Modify' : 'Add').' — '.$langs->trans('LmdbAttestedVersion');
print '<div id="lmdb-version-dialog" title="'.dol_escape_htmltag($versiontitle).'"'.(!empty($conf->use_javascript_ajax) ? ' style="display:none"' : '').'>';
print '<form id="lmdb-version-form" method="post" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="saveversion"><input type="hidden" name="versionid" value="'.(int)$version->id.'">';
print '<div class="div-table-responsive-no-min"><table class="border centpercent">';
foreach ($version->fields as $key => $field) {
	if (!$field['visible'] || $key === 'active') { continue; }
	print '<tr><td class="titlefield'.(!empty($field['notnull']) ? ' fieldrequired' : '').'"><label for="'.$key.'">'.$langs->trans($field['label']).'</label></td><td>';
	if ($key === 'date_release') { print $form->selectDate($version->date_release ?: '', 'date_release', 0, 0, 1, '', 1, 0); }
	elseif ($field['type'] === 'text') { print '<textarea class="flat centpercent" id="'.$key.'" name="'.$key.'" rows="3">'.dol_escape_htmltag($version->{$key}).'</textarea>'; }
	else { print $version->showInputField($field, $key, $version->{$key}); }
	if ($version->getFieldError($key)) { print '<span class="error">'.dol_escape_htmltag($version->getFieldError($key)).'</span>'; }
	print '</td></tr>';
}
print '</table></div>';
if (!$version->id) { print '<p class="opacitymedium">'.$langs->trans('LmdbAttestedVersionInitiallyInactive').'</p>'; }
print '<div class="center"><input type="submit" class="button" value="'.$langs->trans('Save').'"><a class="button button-cancel" href="'.$_SERVER['PHP_SELF'].'">'.$langs->trans('Cancel').'</a></div></form></div>';
if (!empty($conf->use_javascript_ajax)) {
	// Core jQuery UI dialog around a POST form, preserving long catalogue texts,
	// native dates and field errors without serializing them into a GET URL.
	print '<script nonce="'.getNonce().'">jQuery(function($) { var panel = $("#lmdb-version-dialog"); if (!panel.hasClass("ui-dialog-content")) { panel.dialog({modal:true,resizable:false,width:Math.min(800,$(window).width()-30),maxHeight:$(window).height()-40,close:function(){window.location.href='.json_encode($_SERVER['PHP_SELF'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).';}}); } });</script>';
}
