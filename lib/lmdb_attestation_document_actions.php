<?php
/* Copyright (C) 2026 Pierre Ardoin. SPDX-License-Identifier: GPL-3.0-or-later */
/** @var DoliDB $db */
/** @var Conf $conf */
/** @var User $user */
/** @var Translate $langs */
/** @var LmdbAttestation $object */
/** @var HookManager $hookmanager */
/** @var string $action */
global $db, $conf, $user, $langs, $object, $hookmanager, $action;
// Native document controller, restricted to the loaded object's directory/links.
/** @var string $upload_dir */
/** @var bool $permissiontoadd */
/** @var bool $candelete */
global $upload_dir, $permissiontoadd, $candelete;
$linkid = GETPOSTINT('linkid');
$documentmutation = GETPOST('linkit','alpha') || $action === 'renamefile' || $action === 'confirm_updateline' || ($action === 'confirm_deletefile' && $linkid);
if ($documentmutation || in_array($action,array('editfile','update','deletelink'),true)) {
	if (!$permissiontoadd || (($action === 'deletelink' || $action === 'confirm_deletefile') && !$candelete)) { accessforbidden(); }
	if ($linkid) {
		require_once DOL_DOCUMENT_ROOT.'/core/class/link.class.php';
		$checkedlink = new Link($db);
		if ($checkedlink->fetch($linkid) <= 0 || $checkedlink->objecttype !== $object->element || (int)$checkedlink->objectid !== (int)$object->id) { accessforbidden(); }
	}
	if (GETPOST('linkit','alpha') && (GETPOSTINT('objectid') !== (int)$object->id || GETPOST('objecttype','aZ09') !== $object->element)) { accessforbidden(); }
	if ($action === 'renamefile') {
		$from = GETPOST('renamefilefrom','alphanohtml'); $to = GETPOST('renamefileto','alphanohtml');
		if (!is_file($upload_dir.'/'.$from) || $from !== basename($from) || $to !== basename($to) || !lmdbAttestationFileBelongsToObject($object,$upload_dir.'/'.$from) || !lmdbAttestationFileBelongsToObject($object,$upload_dir.'/'.$to) || strtolower(pathinfo($to,PATHINFO_EXTENSION)) !== 'pdf' || ($from === $object->ref.'.pdf' && $to !== $from)) { accessforbidden(); }
		if (GETPOSTINT('ecmfileid')) {
			require_once DOL_DOCUMENT_ROOT.'/ecm/class/ecmfiles.class.php';
			$checkedfile = new EcmFiles($db);
			if ($checkedfile->fetch(GETPOSTINT('ecmfileid')) <= 0 || realpath(DOL_DATA_ROOT.'/'.$checkedfile->filepath.'/'.$checkedfile->filename) !== realpath($upload_dir.'/'.$from)) { accessforbidden(); }
		}
	}
	if ($documentmutation) {
		$confirm = GETPOST('confirm','alpha'); $modulepart = 'lmdb';
		include DOL_DOCUMENT_ROOT.'/core/actions_linkedfiles.inc.php';
		if (empty($error)) { header('Location: '.$_SERVER['PHP_SELF'].'?id='.(int)$object->id); exit; }
	}
}
