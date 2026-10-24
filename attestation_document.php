<?php
/* Copyright (C) 2026 Pierre Ardoin. SPDX-License-Identifier: GPL-3.0-or-later */
/** @var DoliDB $db */
/** @var Conf $conf */
/** @var User $user */
/** @var Translate $langs */
/** @var HookManager $hookmanager */
/** @var LmdbAttestation $object */
/** @var Form $form */
/** @var FormFile $formfile */
/** @var FormActions $formactions */
global $db, $conf, $user, $langs, $hookmanager, $object, $form, $formfile, $formactions;
/** @var int $id */
/** @var string $action */
global $id, $action;

require __DIR__.'/lib/lmdb_attestation_bootstrap.php';
if ($id<=0) { accessforbidden(); }
$upload_dir = $object->documentDirectory(false);
if ($upload_dir === false) { accessforbidden(); }
$permissiontoadd = (int)$object->entity === (int)$conf->entity && $user->hasRight('lmdb','lmdbattestation','write');
$candelete = (int)$object->entity === (int)$conf->entity && $user->hasRight('lmdb','lmdbattestation','delete');
require __DIR__.'/lib/lmdb_attestation_document_actions.php';
if (!GETPOSTINT('linkid')) { require __DIR__.'/lib/lmdb_attestation_upload.php'; }
if ($action==='builddoc') {
	if ($_SERVER['REQUEST_METHOD']!=='POST' || !$user->hasRight('lmdb','lmdbattestation','generate') || (int)$object->entity!==(int)$conf->entity) { accessforbidden(); }
	if ($object->generateDocument(GETPOST('model','aZ09'),$langs)<=0) { setEventMessages($langs->trans($object->error),$object->errors,'errors'); }
	else { header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id); exit; }
}

$sortfield = GETPOST('sortfield', 'aZ09');
if (!in_array($sortfield, array('name','size','date','position_name','label','share'), true)) { $sortfield = 'name'; }
$sortorder = GETPOST('sortorder', 'aZ09') === 'desc' ? 'desc' : 'asc';
$filearray = dol_dir_list($upload_dir, 'files', 0, '', '(\\.meta|_preview.*\\.png)$', $sortfield, $sortorder === 'desc' ? SORT_DESC : SORT_ASC, 1);
$totalsize = 0;
foreach ($filearray as $file) { $totalsize += $file['size']; }
lmdbAttestationHeader($object,'document');
print '<div class="fichecenter"><div class="underbanner clearboth"></div><table class="border tableforfield centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('NbOfAttachedFiles').'</td><td>'.count($filearray).'</td></tr>';
print '<tr><td>'.$langs->trans('TotalSizeOfAttachedFiles').'</td><td>'.dol_print_size($totalsize,1,1).'</td></tr></table></div>';
print dol_get_fiche_end();
if ($action === 'deletefile' || $action === 'deletelink') {
	$questions = $action === 'deletelink' ? array(array('type'=>'hidden','name'=>'linkid','value'=>GETPOSTINT('linkid'))) : array(array('type'=>'hidden','name'=>'urlfile','value'=>GETPOST('urlfile','alpha')));
	print $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$id,$langs->trans('DeleteFile'),$langs->trans('ConfirmDeleteFile',dol_escape_htmltag(basename(GETPOST('urlfile','alpha')))),'confirm_deletefile',$questions,0,1);
}
$canGenerate = (int)$object->entity === (int)$conf->entity && (int)$object->status === 1 && $user->hasRight('lmdb','lmdbattestation','generate');
if ($canGenerate) {
	require_once __DIR__.'/core/modules/lmdb/modules_lmdbattestation.php';
	$models = ModelePDFLmdbattestation::liste_modeles($db);
	if (is_array($models) && $models) {
		print '<form method="post" action="'.$_SERVER['PHP_SELF'].'?id='.$id.'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="builddoc"><input type="hidden" name="id" value="'.$id.'"><div class="center formdoc">';
		print $langs->trans('Model').' '.$form->selectarray('model',$models,$object->model_pdf,0,0,0,'',0,0,0,'','maxwidth300');
		print ajax_combobox('model').'<input class="button buttongen reposition" type="submit" value="'.$langs->trans('Generate').'"></div></form>';
	}
}
$canUpload = $permissiontoadd && (int)$object->status === 1 && LmdbCompatibility::isFeatureAvailable('attestation_pdf_upload');

$embeddedforms = LmdbCompatibility::isFeatureAvailable('attestation_document_embedded_forms');
$param = '&id='.$id.'&entity='.(int)$object->entity;
print '<div id="lmdb_attestation_documents">';
if ($embeddedforms) {
	$forms = $formfile->form_attach_new_file($_SERVER['PHP_SELF'].'?id='.$id, '', 0, 0, $canUpload ? 1 : 0, 50, $object, '', 1, $object->ref.'-__file__', 1, 'formuserfile', '.pdf', '', 0, 0, 0, 2);
	$formfile->list_of_documents($filearray,$object,'lmdb',$param,0,$object->ref.'/', $candelete ? 1 : 0,0,'',0,'','',0,$permissiontoadd ? 1 : 0,$upload_dir,$sortfield,$sortorder,1,0,1,'',array('afteruploadtitle'=>is_array($forms) ? $forms['formToUploadAFile'] : '', 'showhideaddbutton'=>$canUpload && !getDolGlobalInt('MAIN_DOCUMENTS_SHOW_FILE_ATTACHMENT_FORM') ? 1 : 0));
	$linkforms = $formfile->form_attach_new_file($_SERVER['PHP_SELF'].'?id='.$id, '', 0, 0, $permissiontoadd ? 1 : 0, 50, $object, '', 1, '', 1, 'formlinkfile', '', '', 0, 0, 0, 2);
	$formfile->listOfLinks($object,$candelete ? 1 : 0,$action,(string)GETPOSTINT('linkid'),$param,'formaddlink',array('afterlinktitle'=>is_array($linkforms) ? $linkforms['formToAddALink'] : '', 'showhideaddbutton'=>$permissiontoadd && $candelete && !getDolGlobalInt('MAIN_DOCUMENTS_SHOW_FILE_LINKING_FORM') ? 1 : 0));
} else {
	// Dolibarr 20 has the classic native forms, without embedded title buttons.
	$formfile->form_attach_new_file($_SERVER['PHP_SELF'].'?id='.$id, '', 0, 0, $canUpload ? 1 : 0, 50, $object, '', 1, $object->ref.'-__file__', 1, 'formuserfile', '.pdf');
	$formfile->list_of_documents($filearray,$object,'lmdb',$param,0,$object->ref.'/', $candelete ? 1 : 0,0,'',0,'','',0,$permissiontoadd ? 1 : 0,$upload_dir,$sortfield,$sortorder,1,0,1);
	$formfile->listOfLinks($object,$candelete ? 1 : 0,$action,(string)GETPOSTINT('linkid'),$param);
}
print '</div>';
llxFooter(); $db->close();
