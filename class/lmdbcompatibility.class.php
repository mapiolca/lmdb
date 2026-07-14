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
 * \file       htdocs/custom/lmdb/class/lmdbcompatibility.class.php
 * \ingroup    lmdb
 * \brief      Compatibility checks for LMDB module.
 */

/**
 * Centralized compatibility checks.
 *
 * @phpstan-type CompatibilityFeature array{
 *     code:string,
 *     label:string,
 *     description:string,
 *     min_dolibarr:string,
 *     core_available_from:string,
 *     module_available_from:string,
 *     min_php:string,
 *     compatibility_check:string,
 *     available:bool,
 *     reasons:array<int,string>
 * }
 */
class LmdbCompatibility
{
	/**
	 * Check Dolibarr version.
	 *
	 * @param string $version Minimal version
	 * @return bool
	 */
	public static function isDolibarrVersionAtLeast($version)
	{
		return defined('DOL_VERSION') && version_compare(DOL_VERSION, $version, '>=');
	}

	/**
	 * Check PHP version.
	 *
	 * @param string $version Minimal version
	 * @return bool
	 */
	public static function isPhpVersionAtLeast($version)
	{
		return version_compare(PHP_VERSION, $version, '>=');
	}

	/**
	 * Check whether a feature is available.
	 *
	 * @param string $code Feature code
	 * @return bool
	 */
	public static function isFeatureAvailable($code)
	{
		$features = self::getFeatures();

		return !empty($features[$code]['available']);
	}

	/**
	 * Return unavailable features.
	 *
	 * @return array<string,CompatibilityFeature>
	 */
	public static function getUnavailableFeatures()
	{
		$unavailable = array();
		foreach (self::getFeatures() as $code => $feature) {
			if (empty($feature['available'])) {
				$unavailable[$code] = $feature;
			}
		}

		return $unavailable;
	}

	/**
	 * Return all declared features with compatibility status.
	 *
	 * @return array<string,CompatibilityFeature>
	 */
	public static function getFeatures()
	{
		$features = array();

		$reasons = array();
		if (!self::isPhpVersionAtLeast('8.0.0')) {
			$reasons[] = 'RequiresPhp80';
		}
		if (!self::isDolibarrVersionAtLeast('20.0.0')) {
			$reasons[] = 'RequiresDolibarr20';
		}
		if (!isModEnabled('invoice')) {
			$reasons[] = 'RequiresInvoiceModule';
		}
		if (!self::hasCoreSpongeModel()) {
			$reasons[] = 'RequiresSpongeModel';
		}

		$features['invoice_pdf_lmdbsponge'] = array(
			'code' => 'invoice_pdf_lmdbsponge',
			'label' => 'LmdbFeatureInvoicePdfModel',
			'description' => 'LmdbFeatureInvoicePdfModelDescription',
			'min_dolibarr' => '20.0.0',
			'core_available_from' => '20.0.0',
			'module_available_from' => '20.0.0',
			'min_php' => '8.0.0',
			'compatibility_check' => "DOL_VERSION >= 20.0.0; PHP_VERSION >= 8.0.0; isModEnabled('invoice'); pdf_sponge.modules.php present",
			'available' => empty($reasons),
			'reasons' => $reasons,
		);

		$autoSendReasons = array();
		if (!self::isPhpVersionAtLeast('8.0.0')) {
			$autoSendReasons[] = 'RequiresPhp80';
		}
		if (!self::isDolibarrVersionAtLeast('20.0.0')) {
			$autoSendReasons[] = 'RequiresDolibarr20';
		}
		if (!isModEnabled('invoice')) {
			$autoSendReasons[] = 'RequiresInvoiceModule';
		}
		if (!isModEnabled('cron')) {
			$autoSendReasons[] = 'RequiresCronModule';
		}

		$features['recurring_invoice_auto_send'] = array(
			'code' => 'recurring_invoice_auto_send',
			'label' => 'LmdbFeatureRecurringInvoiceAutoSend',
			'description' => 'LmdbFeatureRecurringInvoiceAutoSendDescription',
			'min_dolibarr' => '20.0.0',
			'core_available_from' => '20.0.0',
			'module_available_from' => '20.0.0',
			'min_php' => '8.0.0',
			'compatibility_check' => "DOL_VERSION >= 20.0.0; PHP_VERSION >= 8.0.0; isModEnabled('invoice'); isModEnabled('cron')",
			'available' => empty($autoSendReasons),
			'reasons' => $autoSendReasons,
		);

		$customerRefReasons = array();
		if (!self::isPhpVersionAtLeast('8.0.0')) {
			$customerRefReasons[] = 'RequiresPhp80';
		}
		if (!self::isDolibarrVersionAtLeast('20.0.0')) {
			$customerRefReasons[] = 'RequiresDolibarr20';
		}
		if (!isModEnabled('invoice')) {
			$customerRefReasons[] = 'RequiresInvoiceModule';
		}
		if (isModEnabled('capinvoicereffromrec')) {
			$customerRefReasons[] = 'RequiresCapInvoiceRefFromRecDisabled';
		}

		$features['recurring_invoice_customer_ref'] = array(
			'code' => 'recurring_invoice_customer_ref',
			'label' => 'LmdbFeatureRecurringInvoiceCustomerRef',
			'description' => 'LmdbFeatureRecurringInvoiceCustomerRefDescription',
			'min_dolibarr' => '20.0.0',
			'core_available_from' => '20.0.0',
			'module_available_from' => '20.0.0',
			'min_php' => '8.0.0',
			'compatibility_check' => "DOL_VERSION >= 20.0.0; PHP_VERSION >= 8.0.0; isModEnabled('invoice'); !isModEnabled('capinvoicereffromrec')",
			'available' => empty($customerRefReasons),
			'reasons' => $customerRefReasons,
		);

		$counterReasons = array();
		if (!self::isDolibarrVersionAtLeast('23.0.0')) {
			$counterReasons[] = 'RequiresDolibarr23InvoiceEmailCounter';
		}
		$features['invoice_email_sent_counter'] = array(
			'code' => 'invoice_email_sent_counter',
			'label' => 'LmdbFeatureInvoiceEmailCounter',
			'description' => 'LmdbFeatureInvoiceEmailCounterDescription',
			'min_dolibarr' => '23.0.0',
			'core_available_from' => '23.0.0',
			'module_available_from' => '23.0.0',
			'min_php' => '8.0.0',
			'compatibility_check' => 'DOL_VERSION >= 23.0.0',
			'available' => empty($counterReasons),
			'reasons' => $counterReasons,
		);

		return $features;
	}

	/**
	 * Check whether the core sponge model exists.
	 *
	 * @return bool
	 */
	private static function hasCoreSpongeModel()
	{
		return defined('DOL_DOCUMENT_ROOT') && file_exists(DOL_DOCUMENT_ROOT.'/core/modules/facture/doc/pdf_sponge.modules.php');
	}
}
