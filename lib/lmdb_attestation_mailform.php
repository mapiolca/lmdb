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
/** @var string $cardurl */
/** @var int $templateid */
global $cardurl, $templateid;

// Included only after card permission, state, ownership and exact template checks.
$dir = $object->documentDirectory(false);
$pdf = $dir !== false ? $dir.'/'.$object->ref.'.pdf' : '';
if (!$pdf || !lmdbAttestationFileBelongsToObject($object,$pdf) || !is_readable($pdf)) {
	setEventMessages($langs->trans('LmdbAttestationGenerateFirst'),null,'warnings');
} else {
	$formmail = new LmdbAttestationMail($db);
	$formmail->trackid='lmdbattestation'.$id;
	$formmail->fromtype='user'; $formmail->fromid=$user->id;
	$formmail->fromname=GETPOSTISSET('fromname')?GETPOST('fromname','alphanohtml'):$user->getFullName($langs);
	$formmail->frommail=GETPOSTISSET('frommail')?GETPOST('frommail','email'):$user->email;
	$formmail->withfrom=1; $formmail->withfromreadonly=0;
	$formmail->withto=array('thirdparty'=>$object->thirdparty->name.' <'.$object->thirdparty->email.'>');
	// Native linked contacts, already filtered by attestation/tier/entity access.
	$linkedcontacts = $object->liste_contact(4, 'external', 0, '', 1);
	if (is_array($linkedcontacts)) {
		foreach ($linkedcontacts as $contact) {
			if (!empty($contact['email'])) { $formmail->withto[(int)$contact['id']] = trim($contact['firstname'].' '.$contact['lastname']).' <'.$contact['email'].'>'; }
		}
	}
	$formmail->withtofree=1; $formmail->withtocc=1; $formmail->withtoccc=1;
	$formmail->withtopic=1; $formmail->withbody=1; $formmail->withfile=2; $formmail->withcancel=1;
	$formmail->substit=getCommonSubstitutionArray($langs,0,null,$object);
	complete_substitutions_array($formmail->substit,$langs,$object);
	if (empty($_SESSION['lmdb_attestation_submission'][$id])) { $_SESSION['lmdb_attestation_submission'][$id]=bin2hex(random_bytes(32)); }
	$formmail->param=array('action'=>'send','id'=>$id,'models'=>'lmdbattestation_send','models_id'=>$templateid,'returnurl'=>$cardurl.'?id='.$id,'fileinit'=>array($pdf),'object_entity'=>$object->entity,'lmdb_submission'=>$_SESSION['lmdb_attestation_submission'][$id]);
	print $formmail->get_form();
}
