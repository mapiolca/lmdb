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
 * \file       htdocs/custom/lmdb/admin/compatibility.php
 * \ingroup    lmdb
 * \brief      Compatibility page for LMDB module.
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
require_once dol_buildpath('/lmdb/lib/lmdb.lib.php', 0);
require_once dol_buildpath('/lmdb/class/lmdbcompatibility.class.php', 0);

$langs->loadLangs(array('admin', 'lmdb@lmdb'));

if (empty($user->admin)) {
	accessforbidden();
}

if (!isModEnabled('lmdb')) {
	accessforbidden();
}

llxHeader('', $langs->trans('LmdbCompatibility'));

print load_fiche_titre($langs->trans('LmdbCompatibility'), lmdbGetBackToModuleListLink(), 'title_setup');

$head = lmdbAdminPrepareHead();
print dol_get_fiche_head($head, 'compatibility', '', -1);

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('Environment').'</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('DetectedPhpVersion').'</td><td>'.dol_escape_htmltag(PHP_VERSION).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DetectedDolibarrVersion').'</td><td>'.(defined('DOL_VERSION') ? dol_escape_htmltag(DOL_VERSION) : $langs->trans('Unknown')).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('MinimumPhpVersion').'</td><td>8.0</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('MinimumDolibarrVersion').'</td><td>20.0</td></tr>';
print '</table>';

print '<br>';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Feature').'</td>';
print '<td>'.$langs->trans('Description').'</td>';
print '<td>'.$langs->trans('MinimumDolibarrVersion').'</td>';
print '<td>'.$langs->trans('MinimumPhpVersion').'</td>';
print '<td>'.$langs->trans('Status').'</td>';
print '<td>'.$langs->trans('Reason').'</td>';
print '</tr>';

foreach (LmdbCompatibility::getFeatures() as $feature) {
	print '<tr class="oddeven">';
	print '<td>'.dol_escape_htmltag($langs->trans($feature['label'])).'</td>';
	print '<td>'.dol_escape_htmltag($langs->trans($feature['description'])).'</td>';
	print '<td>'.dol_escape_htmltag($feature['min_dolibarr']).'</td>';
	print '<td>'.dol_escape_htmltag($feature['min_php']).'</td>';
	print '<td>';
	if (!empty($feature['available'])) {
		print img_picto($langs->trans('Available'), 'tick').' '.$langs->trans('Available');
	} else {
		print img_picto($langs->trans('Unavailable'), 'warning').' '.$langs->trans('Unavailable');
	}
	print '</td>';
	print '<td>';
	if (empty($feature['reasons'])) {
		print $langs->trans('FeatureAvailable');
	} else {
		$translatedreasons = array();
		foreach ($feature['reasons'] as $reason) {
			$translatedreasons[] = $langs->trans($reason);
		}
		print dol_escape_htmltag(implode(', ', $translatedreasons));
	}
	print '</td>';
	print '</tr>';
}

print '</table>';

print dol_get_fiche_end();

llxFooter();
$db->close();
