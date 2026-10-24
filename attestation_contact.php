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
// The legal client representative is a historical document field. This tab
// displays the third party's current contacts through the native component.
$permissiontoadd=0; $permissiontoedit=0; $permissiontodelete=0;
lmdbAttestationHeader($object,'contact');
print '<div class="fichecenter">';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
show_contacts($conf,$langs,$db,$object->thirdparty,$_SERVER['PHP_SELF'].'?id='.$id);
print '</div>'.dol_get_fiche_end(); llxFooter(); $db->close();
