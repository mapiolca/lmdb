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

$documentToDelete=GETPOST('urlfile','alpha')?:GETPOST('file','alpha');
if ($action==='deletefile') {
	print $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id,$langs->trans('DeleteFile'),$langs->trans('ConfirmDeleteFile',dol_escape_htmltag(basename($documentToDelete))),'confirm_deletefile',array(array('type'=>'hidden','name'=>'urlfile','value'=>$documentToDelete)),0,1);
}
$dir = $object->documentDirectory(false);
if ($dir === false) { print '<div class="error">'.$langs->trans('LmdbDocumentDirectoryUnavailable').'</div>'; }
else {
	$owner = (int)$object->entity === (int)$conf->entity;
	$canGenerate = $owner && (int)$object->status === 1 && $user->hasRight('lmdb','lmdbattestation','generate');
	$canDelete=$owner && $user->hasRight('lmdb','lmdbattestation','delete');
	$canUpload = $owner && (int)$object->status === 1 && $user->hasRight('lmdb','lmdbattestation','write');
	// genallowed controls the generation form; read is checked by the document hook.
	print '<div id="lmdb_attestation_documents">';
	print $formfile->showdocuments('lmdb:lmdbattestation',$object->ref,$dir,$_SERVER['PHP_SELF'].'?id='.$object->id,$canGenerate ? 1 : 0,$canDelete ? 1 : 0,$object->model_pdf,1,1,0,0,0,'entity='.(int)$object->entity,'','','fr_FR','',$object,0,'deletefile');
	if ($canUpload) {
		$formfile->form_attach_new_file($_SERVER['PHP_SELF'].'?id='.$object->id,$langs->trans('LmdbAttestationSignedReturn'),0,0,1,50,$object,'',1,$object->ref.'-__file__',0,'formuserfile','.pdf');
		if (function_exists('dragAndDropFileUpload')) { print dragAndDropFileUpload('lmdb_attestation_documents'); }
	}
	print '</div>';
}
