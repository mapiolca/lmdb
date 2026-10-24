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

// Shared page initialization, not a permission proxy. Included in each page flow.
$res = 0;
foreach (array(__DIR__.'/../../main.inc.php', __DIR__.'/../../../main.inc.php', __DIR__.'/../../../../main.inc.php') as $mainfile) {
	if (is_file($mainfile)) { $res = require $mainfile; break; }
}
if (!$res) { die('Include of main fails'); }
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formactions.class.php';
require_once __DIR__.'/../class/lmdbattestation.class.php';
require_once __DIR__.'/lmdb_attestation.lib.php';
$langs->loadLangs(array('lmdb@lmdb','companies','other','agenda','mails'));
if (!isModEnabled('lmdb') || !isModEnabled('societe') || $user->socid > 0) { accessforbidden(); }
$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$permissiontoread = $user->hasRight('lmdb', 'lmdbattestation', 'read');
if (empty($lmdbAdminPage) && !$permissiontoread) { accessforbidden(); }
$object = new LmdbAttestation($db);
if (empty($lmdbAdminPage) && $id > 0 && $object->fetch($id) <= 0) { accessforbidden(); }
if (empty($lmdbAdminPage) && $id > 0 && $object->fetch_thirdparty() <= 0) { accessforbidden(); }
$form = new Form($db);
$formfile = new FormFile($db);
$formactions = new FormActions($db);
$hookmanager->initHooks(array('lmdbattestationcard','globalcard'));
