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
 * \file       htdocs/custom/lmdb/lib/lmdb.lib.php
 * \ingroup    lmdb
 * \brief      Common helpers for LMDB module.
 */

if (!defined('LMDB_INVOICE_PDF_MODEL')) {
	define('LMDB_INVOICE_PDF_MODEL', 'lmdbsponge');
}

/**
 * Prepare admin tabs.
 *
 * @return array<int,array{0:string,1:string,2:string}>
 */
function lmdbAdminPrepareHead()
{
	global $langs;

	$langs->loadLangs(array('admin', 'lmdb@lmdb'));

	$head = array();
	$h = 0;

	$head[$h][0] = dol_buildpath('/lmdb/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath('/lmdb/admin/compatibility.php', 1);
	$head[$h][1] = $langs->trans('Compatibility');
	$head[$h][2] = 'compatibility';
	$h++;

	$head[$h][0] = dol_buildpath('/lmdb/admin/about.php', 1);
	$head[$h][1] = $langs->trans('About');
	$head[$h][2] = 'about';
	$h++;

	return $head;
}

/**
 * Return link back to module list.
 *
 * @return string
 */
function lmdbGetBackToModuleListLink()
{
	global $langs;

	return '<a href="'.DOL_URL_ROOT.'/admin/modules.php?search_keyword='.urlencode('lmdb').'">'.$langs->trans('BackToModuleList').'</a>';
}

/**
 * Register the LMDB invoice PDF model for one entity.
 *
 * @param DoliDB $db     Database handler
 * @param int    $entity Entity id
 * @return int 1 if OK, <0 if KO
 */
function lmdbRegisterInvoiceDocumentModel($db, $entity)
{
	$model = LMDB_INVOICE_PDF_MODEL;
	$entity = (int) $entity;

	if (lmdbIsInvoiceDocumentModelRegistered($db, $entity)) {
		return 1;
	}

	$sql = "INSERT INTO ".MAIN_DB_PREFIX."document_model (nom, type, libelle, entity)";
	$sql .= " VALUES ('".$db->escape($model)."', 'invoice', '".$db->escape('LMDB Sponge')."', ".$entity.")";

	if (!$db->query($sql)) {
		return -1;
	}

	return 1;
}

/**
 * Check whether the LMDB invoice PDF model is registered for current entity.
 *
 * @param DoliDB $db     Database handler
 * @param int    $entity Entity id
 * @return bool
 */
function lmdbIsInvoiceDocumentModelRegistered($db, $entity)
{
	$model = LMDB_INVOICE_PDF_MODEL;
	$entity = (int) $entity;

	$sql = "SELECT rowid";
	$sql .= " FROM ".MAIN_DB_PREFIX."document_model";
	$sql .= " WHERE nom = '".$db->escape($model)."'";
	$sql .= " AND type = 'invoice'";
	$sql .= " AND entity IN (0, ".$entity.")";

	$resql = $db->query($sql);
	if (!$resql) {
		return false;
	}

	return $db->num_rows($resql) > 0;
}
