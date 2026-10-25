<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 * SPDX-License-Identifier: GPL-3.0-or-later */
/** @var Conf $conf */
/** @var Translate $langs */
/** @var Form $form */
global $conf, $langs, $form;
// The catalogue object is inherited from the including page scope.
if (!isset($catalogueversion) || !($catalogueversion instanceof LmdbAttestationVersion)) {
	throw new LogicException('Catalogue form requires a loaded catalogue object');
}

$versiontitle = $langs->trans($catalogueversion->id > 0 ? 'Modify' : 'Add').' — '.$langs->trans('LmdbAttestedVersion');
print '<div id="lmdb-version-dialog" title="'.dol_escape_htmltag($versiontitle).'"'.(!empty($conf->use_javascript_ajax) ? ' style="display:none"' : '').'>';
print '<form id="lmdb-version-form" method="post" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="saveversion"><input type="hidden" name="versionid" value="'.(int)$catalogueversion->id.'">';
print '<div class="div-table-responsive-no-min"><table class="border centpercent">';
foreach ($catalogueversion->fields as $key => $field) {
	if (!$field['visible'] || $key === 'active') { continue; }
	print '<tr><td class="titlefield'.(!empty($field['notnull']) ? ' fieldrequired' : '').'"><label for="'.$key.'">'.$langs->trans($field['label']).'</label></td><td>';
	if ($key === 'date_release') { print $form->selectDate($catalogueversion->date_release ?: '', 'date_release', 0, 0, 1, '', 1, 0); }
	elseif ($field['type'] === 'text') { print '<textarea class="flat centpercent" id="'.$key.'" name="'.$key.'" rows="3">'.dol_escape_htmltag($catalogueversion->{$key}, 0, 1).'</textarea>'; }
	else { print $catalogueversion->showInputField($field, $key, $catalogueversion->{$key}); }
	if ($catalogueversion->getFieldError($key)) { print '<span class="error">'.dol_escape_htmltag($catalogueversion->getFieldError($key)).'</span>'; }
	print '</td></tr>';
}
print '</table></div>';
if (!$catalogueversion->id) { print '<p class="opacitymedium">'.$langs->trans('LmdbAttestedVersionInitiallyInactive').'</p>'; }
if (empty($conf->use_javascript_ajax)) {
	print '<div class="center"><input type="submit" class="button" value="'.$langs->trans('Save').'"><a class="button button-cancel" href="'.$_SERVER['PHP_SELF'].'">'.$langs->trans('Cancel').'</a></div>';
}
print '</form></div>';
if (!empty($conf->use_javascript_ajax)) {
	// Native dialog button pane submits the POST form, retaining field validation.
	$dialoglabels = json_encode(array($langs->trans('Save'), $langs->trans('Cancel'), $_SERVER['PHP_SELF']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
	print '<script nonce="'.getNonce().'">jQuery(function($) {
		var panel = $("#lmdb-version-dialog"), labels = '.$dialoglabels.';
		if (!panel.hasClass("ui-dialog-content")) {
			panel.dialog({modal:true,resizable:false,width:Math.min(800,$(window).width()-30),maxHeight:$(window).height()-40,
				buttons:[
					{text:labels[0],click:function(){document.getElementById("lmdb-version-form").requestSubmit();}},
					{text:labels[1],click:function(){panel.dialog("close");}}
				],
				close:function(){window.location.href=labels[2];}
			});
		}
	});</script>';
}
