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
