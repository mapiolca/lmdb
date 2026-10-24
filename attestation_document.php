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
require __DIR__.'/lib/lmdb_attestation_upload.php';
if ($action==='builddoc') {
	if ($_SERVER['REQUEST_METHOD']!=='POST' || !$user->hasRight('lmdb','lmdbattestation','generate') || (int)$object->entity!==(int)$conf->entity) { accessforbidden(); }
	if ($object->generateDocument(GETPOST('model','aZ09'),$langs)<=0) { setEventMessages($langs->trans($object->error),$object->errors,'errors'); }
	else { header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id); exit; }
}
lmdbAttestationHeader($object,'document');
print dol_get_fiche_end();
require __DIR__.'/lib/lmdb_attestation_documents.php';
llxFooter(); $db->close();
