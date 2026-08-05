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
 * Return the LMDB translation key used as fallback for each PDF core key.
 *
 * Dedicated keys avoid hardcoded language values and still repair a core key
 * that was previously loaded as its own untranslated name.
 *
 * @return array<string,string>
 */
function lmdbPdfGetTranslationFallbackKeys()
{
	return array(
		'DateFromTo' => 'LmdbPdfDateFromTo',
		'DateFrom' => 'LmdbPdfDateFrom',
		'DateUntil' => 'LmdbPdfDateUntil',
		'RefCustomer' => 'LmdbPdfRefCustomer',
		'Project' => 'LmdbPdfProject',
		'DateDue' => 'LmdbPdfDateDue',
		'AmountInCurrency' => 'LmdbPdfAmountInCurrency',
		'CurrencyEUR' => 'LmdbPdfCurrencyEUR',
		'PriceUHT' => 'LmdbPdfPriceUHT',
		'Qty' => 'LmdbPdfQty',
		'Offered' => 'LmdbPdfOffered',
		'TotalHTBeforeDiscount' => 'LmdbPdfTotalHTBeforeDiscount',
		'TotalDiscount' => 'LmdbPdfTotalDiscount',
		'TotalHTShort' => 'LmdbPdfTotalHTShort',
		'TotalHT' => 'LmdbPdfTotalHT',
		'TotalTTC' => 'LmdbPdfTotalTTC',
		'Designation' => 'LmdbPdfDesignation',
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
 * The model keeps user/core translations when available. It replaces only a
 * missing/raw key and the known technical values handled by the fallback map.
 *
 * @param Translate|null $outputlangs Output language object
 * @return void
 */
function lmdbPdfApplyTranslationFallbacks($outputlangs)
{
	if (!is_object($outputlangs)
		|| !property_exists($outputlangs, 'tab_translate')
		|| !method_exists($outputlangs, 'transnoentitiesnoconv')) {
		return;
	}

	foreach (lmdbPdfGetTranslationFallbackKeys() as $key => $fallbackKey) {
		$current = isset($outputlangs->tab_translate[$key]) ? $outputlangs->tab_translate[$key] : '';
		$isUntranslated = $current === '' || $current === $key;
		$isKnownTechnicalValue = ($key === 'DateFrom' && $current === 'A partir du %s')
			|| ($key === 'CurrencyEUR' && $current === 'Euro Member Countries');
		if (!$isUntranslated && !$isKnownTechnicalValue) {
			continue;
		}

		$fallback = $outputlangs->transnoentitiesnoconv($fallbackKey);
		if ($fallback !== '' && $fallback !== $fallbackKey) {
			$outputlangs->tab_translate[$key] = $fallback;
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
