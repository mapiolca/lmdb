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
	$result = $object->changeContact($operation, $contactid, GETPOSTINT('typecontact'), $source, $action === 'swapstatut' ? GETPOSTINT('ligne') : GETPOSTINT('lineid'));
	if ($result >= 0) { setEventMessages($langs->trans($result === 0 ? 'ErrorThisContactIsAlreadyDefinedAsThisType' : 'RecordSaved'), null, $result === 0 ? 'warnings' : 'mesgs'); header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id); exit; }
	setEventMessages($langs->trans($object->error), $object->errors, 'errors');
}
lmdbAttestationHeader($object,'contact');
print dol_get_fiche_end();
$formcompany = new FormCompany($db);
// Native selectors; the client relationship is fixed, so no second company selector.
if ($permissiontoadd) {
	print '<div class="div-table-responsive-no-min"><div class="tagtable tableforcontact centpercent noborder">';
	foreach (array('internal','external') as $source) {
		if ($source === 'external' && !$user->hasRight('societe','contact','lire')) { continue; }
		print '<form class="tagtr oddeven nohover" method="post" action="'.$_SERVER['PHP_SELF'].'?id='.$id.'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="addcontact"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="source" value="'.$source.'">';
		print '<div class="tagtd">'.($source === 'internal' ? $langs->trans('Users') : $object->thirdparty->getNomUrl(1)).'</div><div class="tagtd">';
		if ($source === 'internal') { print $form->select_dolusers('', 'userid', 1, null, 0, '', '', 'default'); }
		else { print $form->selectcontacts($object->fk_soc, '', 'contactid', 1, '', '', 1, 'minwidth200'); }
		print '</div><div class="tagtd">';
		$formcompany->selectTypeContact($object, '', 'typecontact', $source);
		print '</div><div class="tagtd center"><input class="button small" type="submit" value="'.$langs->trans('Add').'"></div></form>';
	}
	print '</div></div>';
}
$permission = $permissiontoadd;
$hideaddcontactforuser = 1; $hideaddcontactforthirdparty = 1;
require DOL_DOCUMENT_ROOT.'/core/tpl/contacts.tpl.php';
llxFooter(); $db->close();
