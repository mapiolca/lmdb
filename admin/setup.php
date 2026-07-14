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
require_once dol_buildpath('/lmdb/class/lmdbinvoiceautosend.class.php', 0);

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

if ($action == 'saveautosend') {
	if ($requestmethod != 'POST') {
		accessforbidden();
	}
	$maxperrun = GETPOSTINT('lmdb_auto_invoice_send_max_per_run');
	if (!in_array($maxperrun, array(25, 50, 100, 250), true)) {
		setEventMessages($langs->trans('ErrorBadValueForParameter', 'lmdb_auto_invoice_send_max_per_run'), null, 'errors');
	} else {
		$result = dolibarr_set_const($db, 'LMDB_AUTO_INVOICE_SEND_MAX_PER_RUN', (string) $maxperrun, 'chaine', 0, '', (int) $conf->entity);
		if ($result > 0) {
			setEventMessages($langs->trans('LmdbAutoInvoiceSendSettingsSaved'), null, 'mesgs');
		} else {
			setEventMessages($db->lasterror(), null, 'errors');
		}
	}
	header('Location: '.$pageurl);
	exit;
}

$form = new Form($db);
$currentmodel = getDolGlobalString('FACTURE_ADDON_PDF');
$modelregistered = lmdbIsInvoiceDocumentModelRegistered($db, (int) $conf->entity);
$featureavailable = LmdbCompatibility::isFeatureAvailable('invoice_pdf_lmdbsponge');
$autosenddiagnostics = LmdbInvoiceAutoSend::getDiagnostics($db, (int) $conf->entity);
$maxperrun = getDolGlobalInt('LMDB_AUTO_INVOICE_SEND_MAX_PER_RUN', 100);
$minimuminvoiceid = getDolGlobalInt('LMDB_AUTO_INVOICE_SEND_MIN_ID');

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

print '<br>';

print '<form method="POST" action="'.dol_escape_htmltag($pageurl).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="saveautosend">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('LmdbAutoInvoiceSendTitle').'</td></tr>';

print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('LmdbAutoInvoiceSendCronStatus').'</td><td>';
if ($autosenddiagnostics['lmdb_cron_registered'] && $autosenddiagnostics['lmdb_cron_active']) {
	print img_picto($langs->trans('Available'), 'tick').' '.$langs->trans('LmdbAutoInvoiceSendCronActive');
} elseif ($autosenddiagnostics['lmdb_cron_registered']) {
	print img_picto($langs->trans('Warning'), 'warning').' '.$langs->trans('LmdbAutoInvoiceSendCronInactive');
} else {
	print img_picto($langs->trans('Warning'), 'warning').' '.$langs->trans('LmdbAutoInvoiceSendCronMissing');
}
print ' - <a href="'.DOL_URL_ROOT.'/cron/list.php">'.$langs->trans('LmdbOpenScheduledJobs').'</a>';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('LmdbDelegationCronStatus').'</td><td>';
if ($autosenddiagnostics['legacy_cron_active']) {
	print img_picto($langs->trans('Warning'), 'warning').' <strong>'.$langs->trans('LmdbDelegationCronConflict').'</strong>';
} else {
	print img_picto($langs->trans('Available'), 'tick').' '.$langs->trans('LmdbDelegationCronNotActive');
}
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('LmdbAutoInvoiceSendStartMarker').'</td><td>'.((int) $minimuminvoiceid > 0 ? (int) $minimuminvoiceid : $langs->trans('NotDefined')).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbAutoInvoiceSendErrorCount').'</td><td>'.((int) $autosenddiagnostics['error_count']).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbAutoInvoiceSendReviewCount').'</td><td>'.((int) $autosenddiagnostics['review_count']).'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('LmdbAutoInvoiceSendGlobalSender').'</td><td>';
if (getDolGlobalString('MAIN_MAIL_EMAIL_FROM') !== '') {
	print img_picto($langs->trans('Available'), 'tick').' '.dol_escape_htmltag(getDolGlobalString('MAIN_MAIL_EMAIL_FROM'));
} else {
	print img_picto($langs->trans('Warning'), 'warning').' '.$langs->trans('LmdbAutoInvoiceSendTemplateSenderFallback');
}
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('LmdbAutoInvoiceSendMaxPerRun').'</td><td>';
print $form->selectarray('lmdb_auto_invoice_send_max_per_run', array(25 => '25', 50 => '50', 100 => '100', 250 => '250'), $maxperrun, 0, 0, 0, '', 0, 0, 0, '', 'minwidth100', 1);
print '</td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div>';
print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
