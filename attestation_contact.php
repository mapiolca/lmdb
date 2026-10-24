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

require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formcompany.class.php';
$permissiontoadd = (int)$object->entity === (int)$conf->entity && $user->hasRight('lmdb','lmdbattestation','write');
if (in_array($action, array('addcontact','swapstatut','deletecontact'), true)) {
	if (!$permissiontoadd || ($action === 'addcontact' && $_SERVER['REQUEST_METHOD'] !== 'POST')) { accessforbidden(); }
	$source = GETPOST('source', 'aZ09');
	$contactid = $source === 'internal' ? GETPOSTINT('userid') : GETPOSTINT('contactid');
	$operation = $action === 'addcontact' ? 'add' : ($action === 'swapstatut' ? 'toggle' : 'unlink');
	$result = $object->changeContact($operation, $contactid, GETPOSTINT($source === 'internal' ? 'type' : 'typecontact'), $source, $action === 'swapstatut' ? GETPOSTINT('ligne') : GETPOSTINT('lineid'));
	if ($result >= 0) { setEventMessages($langs->trans($result === 0 ? 'ErrorThisContactIsAlreadyDefinedAsThisType' : 'RecordSaved'), null, $result === 0 ? 'warnings' : 'mesgs'); header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id); exit; }
	setEventMessages($langs->trans($object->error), $object->errors, 'errors');
}
lmdbAttestationHeader($object,'contact');
print dol_get_fiche_end();
/** @var LmdbAttestation $lmdbAttestationContact */
global $lmdbAttestationContact;
// Render the complete native form and list, with scoped SQL hooks for selectors.
if (GETPOSTISSET('newcompany') && GETPOSTINT('newcompany') !== (int)$object->fk_soc) { accessforbidden(); }
$permission = $permissiontoadd;
$hideaddcontactforthirdparty = !$user->hasRight('societe','contact','lire');
$lmdbAttestationContact = $object;
// The native Ajax company search has no per-object restriction parameter.
// Use its native list mode for this fixed-tier object, then restore the request setting.
$hadcompanysearch = property_exists($conf->global, 'COMPANY_USE_SEARCH_TO_SELECT');
$companysearch = getDolGlobalString('COMPANY_USE_SEARCH_TO_SELECT');
$conf->global->COMPANY_USE_SEARCH_TO_SELECT = '0';
try { require DOL_DOCUMENT_ROOT.'/core/tpl/contacts.tpl.php'; }
finally {
	if ($hadcompanysearch) { $conf->global->COMPANY_USE_SEARCH_TO_SELECT = $companysearch; }
	else { unset($conf->global->COMPANY_USE_SEARCH_TO_SELECT); }
	unset($lmdbAttestationContact);
}
llxFooter(); $db->close();
