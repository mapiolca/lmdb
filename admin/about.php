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
 * \file       htdocs/custom/lmdb/admin/about.php
 * \ingroup    lmdb
 * \brief      About page for LMDB module.
 */

/** @var DoliDB $db */
/** @var Translate $langs */
/** @var User $user */
global $db, $langs, $user;
$lmdbAdminPage=true;
require __DIR__.'/../lib/lmdb_attestation_bootstrap.php';

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once dol_buildpath('/lmdb/lib/lmdb.lib.php', 0);
require_once dol_buildpath('/lmdb/core/modules/modLmdb.class.php', 0);

$langs->loadLangs(array('admin', 'lmdb@lmdb'));

if (empty($user->admin)) {
	accessforbidden();
}

if (!isModEnabled('lmdb')) {
	accessforbidden();
}

$module = new modLmdb($db);

llxHeader('', $langs->trans('LmdbAbout'));

print load_fiche_titre($langs->trans('LmdbAbout'), lmdbGetBackToModuleListLink(), 'title_setup');

$head = lmdbAdminPrepareHead();
print dol_get_fiche_head($head, 'about', '', -1);

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('ModuleInformation').'</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('ModuleName').'</td><td>'.$langs->trans('LmdbModuleName').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Version').'</td><td>'.dol_escape_htmltag($module->version).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Publisher').'</td><td>'.dol_escape_htmltag($module->editor_name).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Description').'</td><td>'.$langs->trans('LmdbModuleDescriptionLong').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Compatibility').'</td><td>'.dol_escape_htmltag('Dolibarr '.implode('.', $module->need_dolibarr_version).'+ / PHP '.implode('.', $module->phpmin).'+').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Dependencies').'</td><td>'.dol_escape_htmltag(implode(', ', $module->depends)).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('MainFeatures').'</td><td>'.$langs->trans('LmdbMainFeaturesSummary').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('UsefulLinks').'</td><td><a href="https://lesmetiersdubatiment.fr" target="_blank" rel="noopener noreferrer">lesmetiersdubatiment.fr</a></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('License').'</td><td>'.dol_escape_htmltag($module->license).'</td></tr>';
print '</table>';
print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
