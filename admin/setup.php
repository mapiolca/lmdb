<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file       htdocs/custom/lmdb/admin/setup.php
 * \ingroup    lmdb
 * \brief      Setup page for LMDB module.
 */

$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
	$res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'].'/main.inc.php';
}
if (!$res && !empty($_SERVER['DOCUMENT_ROOT'])) {
	$res = @include $_SERVER['DOCUMENT_ROOT'].'/main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once dol_buildpath('/lmdb/lib/lmdb.lib.php', 0);
require_once dol_buildpath('/lmdb/class/lmdbcompatibility.class.php', 0);

$langs->loadLangs(array('admin', 'bills', 'lmdb@lmdb'));

$action = GETPOST('action', 'aZ09');
$pageurl = dol_buildpath('/lmdb/admin/setup.php', 1);
$requestmethod = empty($_SERVER['REQUEST_METHOD']) ? '' : $_SERVER['REQUEST_METHOD'];

if (empty($user->admin)) {
	accessforbidden();
}

if (!isModEnabled('lmdb')) {
	accessforbidden();
}

if ($action == 'setdefaultpdf') {
	if ($requestmethod != 'POST') {
		accessforbidden();
	}
	if (LmdbCompatibility::isFeatureAvailable('invoice_pdf_lmdbsponge') && lmdbIsInvoiceDocumentModelRegistered($db, (int) $conf->entity)) {
		$result = dolibarr_set_const($db, 'FACTURE_ADDON_PDF', LMDB_INVOICE_PDF_MODEL, 'chaine', 0, '', (int) $conf->entity);
		if ($result > 0) {
			setEventMessages($langs->trans('LmdbDefaultInvoicePdfModelSaved'), null, 'mesgs');
		} else {
			setEventMessages($langs->trans('ErrorFailedToSaveDefaultInvoicePdfModel'), null, 'errors');
		}
	} else {
		setEventMessages($langs->trans('LmdbInvoicePdfModelUnavailable'), null, 'errors');
	}
	header('Location: '.$pageurl);
	exit;
}

if ($action == 'registermodel') {
	if ($requestmethod != 'POST') {
		accessforbidden();
	}
	$result = lmdbRegisterInvoiceDocumentModel($db, (int) $conf->entity);
	if ($result > 0) {
		setEventMessages($langs->trans('LmdbInvoicePdfModelRegistered'), null, 'mesgs');
	} else {
		setEventMessages($db->lasterror(), null, 'errors');
	}
	header('Location: '.$pageurl);
	exit;
}

$form = new Form($db);
$currentmodel = getDolGlobalString('FACTURE_ADDON_PDF');
$modelregistered = lmdbIsInvoiceDocumentModelRegistered($db, (int) $conf->entity);
$featureavailable = LmdbCompatibility::isFeatureAvailable('invoice_pdf_lmdbsponge');

llxHeader('', $langs->trans('LmdbSetup'));

print load_fiche_titre($langs->trans('LmdbSetup'), lmdbGetBackToModuleListLink(), 'title_setup');

$head = lmdbAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', '', -1);

print '<span class="opacitymedium">'.$langs->trans('LmdbSetupIntro').'</span>';
print '<br><br>';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('LmdbInvoicePdfModel').'</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">'.$langs->trans('DocumentModelCode').'</td>';
print '<td><span class="badge">'.$form->textwithpicto(LMDB_INVOICE_PDF_MODEL, $langs->trans('LmdbInvoicePdfModelTooltip')).'</span></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('Status').'</td>';
print '<td>';
if ($featureavailable && $modelregistered) {
	print img_picto($langs->trans('Available'), 'tick').' '.$langs->trans('Available');
} elseif (!$modelregistered) {
	print img_picto($langs->trans('Warning'), 'warning').' '.$langs->trans('LmdbInvoicePdfModelNotRegistered');
} else {
	print img_picto($langs->trans('Warning'), 'warning').' '.$langs->trans('Unavailable');
}
print '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('CurrentDefaultInvoicePdfModel').'</td>';
print '<td>'.dol_escape_htmltag($currentmodel ? $currentmodel : $langs->trans('None')).'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('Actions').'</td>';
print '<td>';

if (!$modelregistered) {
	print '<form method="POST" action="'.dol_escape_htmltag($pageurl).'" class="inline-block">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="registermodel">';
	print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('RegisterDocumentModel')).'">';
	print '</form>';
}

if ($featureavailable && $modelregistered && $currentmodel != LMDB_INVOICE_PDF_MODEL) {
	print '<form method="POST" action="'.dol_escape_htmltag($pageurl).'" class="inline-block">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="setdefaultpdf">';
	print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('SetAsDefaultInvoicePdfModel')).'">';
	print '</form>';
} elseif ($currentmodel == LMDB_INVOICE_PDF_MODEL) {
	print img_picto($langs->trans('Selected'), 'tick').' '.$langs->trans('AlreadySelectedAsDefaultInvoicePdfModel');
}

print '</td>';
print '</tr>';
print '</table>';

print dol_get_fiche_end();

llxFooter();
$db->close();
