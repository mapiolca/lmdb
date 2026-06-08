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
 * \file       htdocs/custom/lmdb/lib/lmdb_pdf.lib.php
 * \ingroup    lmdb
 * \brief      PDF helpers for LMDB document models.
 */

/**
 * Return PDF translation fallbacks for one output language.
 *
 * @param string $lang Language code
 * @return array<string,string>
 */
function lmdbPdfGetTranslationFallbacks($lang)
{
	$lang = strtolower((string) $lang);

	if (strpos($lang, 'fr') === 0) {
		return array(
			'DateFromTo' => 'Du %s au %s',
			'DateFrom' => 'À partir du %s',
			'DateUntil' => "Jusqu'au %s",
			'RefCustomer' => 'Réf. client',
			'Project' => 'Projet',
			'DateDue' => "Date d'échéance",
			'AmountInCurrency' => 'Montants exprimés en %s',
			'TotalHTBeforeDiscount' => 'Total HT avant remise',
			'TotalDiscount' => 'Remise totale',
			'TotalHTShort' => 'Total HT',
			'Designation' => 'Désignation',
		);
	}

	return array(
		'DateFromTo' => 'From %s to %s',
		'DateFrom' => 'From %s',
		'DateUntil' => 'Until %s',
		'RefCustomer' => 'Customer ref.',
		'Project' => 'Project',
		'DateDue' => 'Due date',
		'AmountInCurrency' => 'Amounts in %s',
		'TotalHTBeforeDiscount' => 'Total before discount (excl. tax)',
		'TotalDiscount' => 'Total discount',
		'TotalHTShort' => 'Total excl. tax',
		'Designation' => 'Description',
	);
}

/**
 * Load language domains used by the invoice PDF model.
 *
 * @param Translate|null $outputlangs Output language object
 * @return void
 */
function lmdbPdfLoadInvoiceTranslationDomains($outputlangs)
{
	if (!is_object($outputlangs) || !method_exists($outputlangs, 'loadLangs')) {
		return;
	}

	$outputlangs->loadLangs(array(
		'main',
		'bills',
		'products',
		'dict',
		'companies',
		'compta',
		'projects',
		'other',
		'lmdb@lmdb',
	));
}

/**
 * Inject LMDB PDF translation fallbacks into a Dolibarr Translate object.
 *
 * The model keeps user/core translations when available, except for the
 * historical unaccented French DateFrom value.
 *
 * @param Translate|null $outputlangs Output language object
 * @return void
 */
function lmdbPdfApplyTranslationFallbacks($outputlangs)
{
	if (!is_object($outputlangs) || !property_exists($outputlangs, 'tab_translate')) {
		return;
	}

	$lang = empty($outputlangs->defaultlang) ? 'en_US' : $outputlangs->defaultlang;
	$fallbacks = lmdbPdfGetTranslationFallbacks($lang);

	foreach ($fallbacks as $key => $value) {
		$current = isset($outputlangs->tab_translate[$key]) ? $outputlangs->tab_translate[$key] : '';
		if ($current === '' || $current === $key || ($key === 'DateFrom' && $current === 'A partir du %s')) {
			$outputlangs->tab_translate[$key] = $value;
		}
	}
}

/**
 * Ensure generated recurring invoices expose service dates before PDF rendering.
 *
 * Dolibarr normally writes these dates during invoice creation. This defensive
 * pass only fills missing values from the linked recurring invoice template.
 *
 * @param Facture $object Invoice object
 * @return void
 */
function lmdbPdfEnsureRecurringServiceDates($object)
{
	global $db;

	if (!is_object($object) || empty($object->fk_fac_rec_source) || empty($object->lines) || !is_array($object->lines)) {
		return;
	}

	$needsdates = false;
	foreach ($object->lines as $line) {
		if (is_object($line) && (empty($line->date_start) || empty($line->date_end))) {
			$needsdates = true;
			break;
		}
	}
	if (!$needsdates) {
		return;
	}

	$dbtouse = !empty($object->db) ? $object->db : $db;
	if (!is_object($dbtouse)) {
		return;
	}

	require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture-rec.class.php';

	$facturerec = new FactureRec($dbtouse);
	if ($facturerec->fetch((int) $object->fk_fac_rec_source) <= 0 || empty($facturerec->lines)) {
		return;
	}

	$anchor = !empty($object->date) ? $object->date : 0;
	if (!is_numeric($anchor) && method_exists($dbtouse, 'jdate')) {
		$anchor = $dbtouse->jdate($anchor);
	}
	if (empty($anchor)) {
		return;
	}

	$period = lmdbPdfGetRecurringServicePeriod($anchor, $facturerec);
	if (empty($period['date_start']) && empty($period['date_end'])) {
		return;
	}

	$invoiceLines = array_values($object->lines);
	$templateLines = array_values($facturerec->lines);

	foreach ($invoiceLines as $i => $line) {
		if (!is_object($line) || empty($templateLines[$i]) || !is_object($templateLines[$i])) {
			continue;
		}

		$templateLine = $templateLines[$i];
		if (empty($line->date_start) && !empty($templateLine->date_start_fill) && !empty($period['date_start'])) {
			$line->date_start = $period['date_start'];
		}
		if (empty($line->date_end) && !empty($templateLine->date_end_fill) && !empty($period['date_end'])) {
			$line->date_end = $period['date_end'];
		}
	}
}

/**
 * Compute the service period generated from a recurring invoice template.
 *
 * @param int|string $anchor      Invoice date used by recurring generation
 * @param FactureRec $facturerec  Recurring invoice template
 * @return array{date_start:int|string,date_end:int|string}
 */
function lmdbPdfGetRecurringServicePeriod($anchor, $facturerec)
{
	$dateStart = $anchor;
	$dateEnd = 0;

	if (empty($facturerec->frequency) || empty($facturerec->unit_frequency)) {
		return array('date_start' => $dateStart, 'date_end' => $dateEnd);
	}

	if (!empty($facturerec->rule_for_lines_dates) && $facturerec->rule_for_lines_dates == 'postpaid') {
		$dateStart = dol_time_plus_duree($anchor, -((int) $facturerec->frequency), $facturerec->unit_frequency);
		$dateEnd = dol_time_plus_duree($anchor, -1, 'd');
	} else {
		$nextDate = dol_time_plus_duree($anchor, (int) $facturerec->frequency, $facturerec->unit_frequency);
		$dateEnd = dol_time_plus_duree($nextDate, -1, 'd');
	}

	return array('date_start' => $dateStart, 'date_end' => $dateEnd);
}
