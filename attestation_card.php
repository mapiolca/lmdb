<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 * SPDX-License-Identifier: GPL-3.0-or-later */
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
$permissiontoadd = $user->hasRight('lmdb','lmdbattestation','write');
$permissiontovalidate = $user->hasRight('lmdb','lmdbattestation','validate');
$permissiontodelete = $user->hasRight('lmdb','lmdbattestation','delete');
$permissiontocancel = $user->hasRight('lmdb','lmdbattestation','cancel');
$permissiontosend = $user->hasRight('lmdb','lmdbattestation','send');
$permissiontogeneratedocument = $user->hasRight('lmdb','lmdbattestation','generate');
$owner = $id > 0 && (int)$object->entity === (int)$conf->entity;
$cardurl = dol_buildpath('/lmdb/attestation_card.php',1);
if ($id > 0) { require __DIR__.'/lib/lmdb_attestation_upload.php'; }
$parameters = array('id'=>$id);
$reshook = $hookmanager->executeHooks('doActions',$parameters,$object,$action);
if ($reshook < 0) { setEventMessages($hookmanager->error,$hookmanager->errors,'errors'); }
if (!$reshook && in_array($action,array('add','save','confirm_validate','confirm_delete','confirm_cancel','builddoc'),true)) {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { accessforbidden(); }
	$result = -1;
	if ($action === 'add' || $action === 'save') {
		if (!$permissiontoadd || ($action === 'save' && (!$owner || (int)$object->status !== 0))) { accessforbidden(); }
		$object->fk_soc = GETPOSTINT('fk_soc');
		$object->fk_version = GETPOSTINT('fk_version');
		foreach (array('date_attestation','date_acquisition','date_start') as $key) { $object->{$key} = GETPOSTDATE($key); }
		$object->client_representative = GETPOST('client_representative','alphanohtml');
		$object->license_number = GETPOST('license_number','alphanohtml');
		$result = $action === 'add' ? $object->create($user) : $object->update($user);
	} elseif ($action === 'confirm_validate' && $permissiontovalidate && $owner) { $result=$object->validate($user);
	} elseif ($action === 'confirm_cancel' && $permissiontocancel && $owner) { $result=$object->cancel($user);
	} elseif ($action === 'confirm_delete' && $permissiontodelete && $owner) { $result=$object->delete($user);
	} elseif ($action === 'builddoc' && $permissiontogeneratedocument && $owner) { $result=$object->generateDocument(GETPOST('model','aZ09'),$langs); }
	if ($result > 0) {
		setEventMessages($langs->trans('RecordSaved'),null,'mesgs');
		header('Location: '.($action === 'confirm_delete' ? dol_buildpath('/lmdb/attestation_list.php',1) : $cardurl.'?id='.(int)$object->id)); exit;
	}
	setEventMessages($langs->trans($object->error ?: 'ErrorBadParameters'),$object->errors,'errors');
	if ($action === 'add' || $action === 'save') { $action = $id > 0 ? 'edit' : 'create'; }
}

// The individual documentary mail uses the native mail controller and SMTP.
// Its UPDATE event records delivery; no SENTBYMAIL custom trigger is introduced.
if (GETPOST('modelselected','alpha') && $id > 0) { $action='presend'; }
if ($id > 0 && in_array($action,array('presend','send'),true)) {
	if (!$permissiontosend || !$owner || (int)$object->status !== 1) { accessforbidden(); }
	require_once __DIR__.'/class/lmdbattestationmail.class.php';
	$allowedtemplates = LmdbAttestationMail::templateIds($db,$user);
	$templateid = GETPOSTINT('modelmailselected');
	if ($allowedtemplates === false || !$allowedtemplates || ($templateid && !in_array($templateid,$allowedtemplates,true))) {
		setEventMessages($langs->trans('LmdbAttestationTemplateUnavailable'),null,'errors'); $action='';
	} else {
		if (!$templateid) { $templateid=$allowedtemplates[0]; }
		$trackid='lmdbattestation'.$id;
		if (GETPOST('trackid','aZ09') && GETPOST('trackid','aZ09') !== $trackid) { accessforbidden(); }
		// Core removeAll physically deletes attachments, including the official PDF.
		if (GETPOST('removeAll','alpha')) { accessforbidden(); }
		$issend = $action === 'send' && !GETPOST('addfile','alpha') && !GETPOST('removedfile','alpha') && !GETPOST('cancel','alpha') && !GETPOST('modelselected','alpha');
		if ($issend) {
			$dir=$object->documentDirectory(false);
			$pdf=$dir !== false ? $dir.'/'.$object->ref.'.pdf' : '';
			if (!$pdf || !lmdbAttestationFileBelongsToObject($object,$pdf) || !is_readable($pdf)) { accessforbidden(); }
			$attachmentform=new FormMail($db); $attachmentform->trackid=$trackid;
			$attachments=$attachmentform->get_attached_files();
			if (!in_array($pdf,$attachments['paths'],true)) { accessforbidden(); }
			$temp=realpath($conf->user->dir_output.'/'.$user->id.'/temp');
			foreach ($attachments['paths'] as $path) {
				$real=realpath($path);
				if (!$real || (!lmdbAttestationFileBelongsToObject($object,$path) && (!$temp || strpos($real,$temp.DIRECTORY_SEPARATOR)!==0))) { accessforbidden(); }
			}
			$submission = GETPOST('lmdb_submission','aZ09');
			$expected = isset($_SESSION['lmdb_attestation_submission'][$id]) ? $_SESSION['lmdb_attestation_submission'][$id] : '';
			if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$expected || !hash_equals($expected,$submission)) { accessforbidden(); }
			unset($_SESSION['lmdb_attestation_submission'][$id]);
			$mailid = LmdbAttestationMail::reserve($db,$object,$user,$submission);
			if ($mailid <= 0) { setEventMessages($langs->trans('LmdbAttestationSendPending'),null,'errors'); $action='presend'; }
			else { $object->context['lmdb_attestation_mail_id']=$mailid; $object->context['trigger_reason']='email'; }
		}
		if ($action === 'send' && GETPOST('cancel','alpha')) { $action=''; }
		if ($action === 'send') {
			$triggersendname='LMDB_ATTESTATION_UPDATE'; $autocopy=''; $error=0;
			include DOL_DOCUMENT_ROOT.'/core/actions_sendmails.inc.php';
			// The native success path redirects and exits. A returned failure is safe to correct manually.
			if (isset($mailid) && $mailid>0) { $db->query('UPDATE '.MAIN_DB_PREFIX."lmdb_attestation_email SET status='failed' WHERE rowid=".(int)$mailid." AND status='pending' AND entity=".(int)$object->entity); }
		}
	}
}

if ($action === 'create' || $action === 'edit') {
	if (!$permissiontoadd || ($action === 'edit' && (!$owner || (int)$object->status !== 0))) { accessforbidden(); }
	llxHeader('',$langs->trans('LmdbNewAttestation'));
	print load_fiche_titre($langs->trans('LmdbNewAttestation'));
	print '<form method="post" action="'.$cardurl.'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="'.($id>0?'save':'add').'"><input type="hidden" name="id" value="'.(int)$id.'">';
	print '<div class="div-table-responsive-no-min"><table class="border centpercent">';
	foreach (array('fk_soc','fk_version','date_attestation','date_acquisition','date_start','client_representative','license_number') as $key) {
		$field=$object->fields[$key];
		print '<tr><td class="titlefield'.(!empty($field['notnull'])?' fieldrequired':'').'">'.$langs->trans($field['label']).'</td><td>';
		if ($key==='fk_soc') { print $form->select_thirdparty_list($object->fk_soc,'fk_soc','',1,0,1);
		} elseif ($key==='fk_version') { $choices=LmdbAttestationVersion::choices($db,$conf->entity); if ($choices===false) { dol_print_error($db); } else { print $form->selectarray('fk_version',$choices,$object->fk_version,1); print ajax_combobox('fk_version'); }
		} elseif ($field['type']==='date') { print $form->selectDate($object->{$key} ?: ($key==='date_attestation'?dol_now():''),$key,0,0,1,'',1,0);
		} else { print $object->showInputField($field,$key,$object->{$key}); }
		if ($object->getFieldError($key)) { print '<span class="error">'.dol_escape_htmltag($object->getFieldError($key)).'</span>'; }
		print '</td></tr>';
	}
	print '</table></div><div class="center"><input class="button" type="submit" value="'.$langs->trans('Save').'"><a class="button button-cancel" href="'.($id?$cardurl.'?id='.$id:dol_buildpath('/lmdb/attestation_list.php',1)).'">'.$langs->trans('Cancel').'</a></div></form>';
} elseif ($id>0) {
	lmdbAttestationHeader($object,'card');
	$version = new LmdbAttestationVersion($db);
	$versionlabel = '';
	if ((int)$object->status===0) { if ($version->fetch($object->fk_version)>0) { $versionlabel=$version->version; } }
	else { try { $snapshot=$object->legalSnapshot(); $versionlabel=$snapshot['version']; } catch (RuntimeException $e) { setEventMessages($langs->trans($e->getMessage()),null,'errors'); } }
	print '<div class="div-table-responsive-no-min"><table class="border centpercent">';
	print '<tr><td class="titlefield">'.$langs->trans('ThirdParty').'</td><td>'.$object->thirdparty->getNomUrl(1).'</td></tr>';
	print '<tr><td>'.$langs->trans('LmdbAttestedVersion').'</td><td>'.dol_escape_htmltag($versionlabel).'</td></tr>';
	foreach (array('date_attestation','date_acquisition','date_start') as $key) { print '<tr><td>'.$langs->trans($object->fields[$key]['label']).'</td><td>'.($object->{$key}?dol_print_date($object->{$key},'day'):'').'</td></tr>'; }
	print '<tr><td>'.$langs->trans('Status').'</td><td>'.$object->getLibStatut(5).'</td></tr></table></div>'.dol_get_fiche_end();
	print '<div class="tabsAction">';
	if ($owner) {
		if ((int)$object->status===0 && $permissiontoadd) { print '<a class="butAction" href="'.$cardurl.'?id='.$id.'&action=edit">'.$langs->trans('Modify').'</a>'; }
		$buttons=array();
		if ((int)$object->status===0 && $permissiontovalidate) { $buttons['confirm_validate']='Validate'; }
		if ((int)$object->status===0 && $permissiontodelete) { $buttons['confirm_delete']='Delete'; }
		if ((int)$object->status===1 && $permissiontocancel) { $buttons['confirm_cancel']='Cancel'; }
		foreach ($buttons as $operation=>$label) { print '<a class="butAction" href="'.$cardurl.'?id='.$id.'&action='.substr($operation,8).'&token='.newToken().'">'.$langs->trans($label).'</a>'; }
		if ((int)$object->status===1 && $permissiontosend) { print '<a class="butAction" href="'.$cardurl.'?id='.$id.'&action=presend&token='.newToken().'">'.$langs->trans('SendMail').'</a>'; }
	}
	print '</div>';
	if (in_array($action,array('validate','delete','cancel'),true)) {
		$labels=array('validate'=>'Validate','delete'=>'Delete','cancel'=>'Cancel');
		print $form->formconfirm($cardurl.'?id='.$id,$langs->trans($labels[$action]),$langs->trans('LmdbAttestationConfirm_'.$action),'confirm_'.$action,'',0,1);
	}
	if ($action==='presend') { require __DIR__.'/lib/lmdb_attestation_mailform.php'; }
	else {
		print '<div class="fichecenter"><div class="fichehalfleft">';
		require __DIR__.'/lib/lmdb_attestation_documents.php';
		$form->showLinkedObjectBlock($object);
		print '</div><div class="fichehalfright">';
		if (isModEnabled('agenda') && ($user->hasRight('agenda','myactions','read') || $user->hasRight('agenda','allactions','read'))) { $formactions->showactions($object,'lmdbattestation@lmdb',$object->fk_soc,1); }
		print '</div></div>';
	}
} else { header('Location: '.dol_buildpath('/lmdb/attestation_list.php',1)); exit; }
llxFooter(); $db->close();
