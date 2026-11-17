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

if ($action==='confirm_deletefile' && GETPOST('confirm','alpha')==='yes') {
	if (!$user->hasRight('lmdb','lmdbattestation','delete') || (int)$object->entity!==(int)$conf->entity) { accessforbidden(); }
	$dir=$object->documentDirectory(false);
	$name=GETPOST('urlfile','alpha');
	if ($dir===false || $name==='' || strpos($name,'..')!==false) { accessforbidden(); }
	$file=$dir.'/'.basename($name);
	if (!lmdbAttestationFileBelongsToObject($object,$file)) { accessforbidden(); }
	if (dol_delete_file($file,1,0,0,$object)) {
		if ($object->emitCrud('UPDATE','document',$user)<0) { setEventMessages($object->error,$object->errors,'errors'); }
		else { setEventMessages($langs->trans('FileWasRemoved',basename($name)),null,'mesgs'); }
	} else { setEventMessages($langs->trans('ErrorFailToDeleteFile',basename($name)),null,'errors'); }
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.(int)$object->id); exit;
}

// Classical upload uses the same native validation and indexation as Ajax.
if (GETPOST('sendit','alpha')) {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !LmdbCompatibility::isFeatureAvailable('attestation_pdf_upload') || !$user->hasRight('lmdb','lmdbattestation','write') || (int)$object->entity !== (int)$conf->entity || (int)$object->status !== 1) { accessforbidden(); }
	$dir=$object->documentDirectory(true);
	if ($dir===false) { accessforbidden(); }
	$names = isset($_FILES['userfile']['name']) ? $_FILES['userfile']['name'] : array();
	$temps = isset($_FILES['userfile']['tmp_name']) ? $_FILES['userfile']['tmp_name'] : array();
	if (!is_array($names)) { $names=array($names); $temps=array($temps); }
	$valid=count($names)>0 && class_exists('finfo');
	foreach ($names as $k=>$name) {
		if (!is_string($name) || !isset($temps[$k]) || !is_string($temps[$k]) || $temps[$k]==='' || strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='pdf' || !class_exists('finfo') || (new finfo(FILEINFO_MIME_TYPE))->file($temps[$k])!=='application/pdf') { $valid=false; }
	}
	if (!class_exists('finfo')) { $valid=false; }
	if (!$valid) { setEventMessages($langs->trans('LmdbAttestationPdfOnly'),null,'errors'); }
	else {
		$result=dol_add_file_process($dir,0,1,'userfile',$object->ref.'-__file__',null,'',0,$object);
		if ($result>0 && $object->emitCrud('UPDATE','document',$user)<0) { setEventMessages($object->error,$object->errors,'errors'); }
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.(int)$object->id); exit;
	}
}
