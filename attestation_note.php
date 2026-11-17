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
$permissionnote=$user->hasRight('lmdb','lmdbattestation','write') && (int)$object->entity===(int)$conf->entity && (int)$object->status===0;
if (in_array($action,array('setnote_public','setnote_private'),true)) {
	if (!$permissionnote || $_SERVER['REQUEST_METHOD']!=='POST') { accessforbidden(); }
	$key=$action==='setnote_public'?'note_public':'note_private';
	$object->{$key}=GETPOST($key,'restricthtml');
	if ($object->update($user)>0) { header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id); exit; }
	setEventMessages($object->error,$object->errors,'errors');
}
lmdbAttestationHeader($object,'note');
$cssclass='titlefield';
require DOL_DOCUMENT_ROOT.'/core/tpl/notes.tpl.php';
print dol_get_fiche_end(); llxFooter(); $db->close();
