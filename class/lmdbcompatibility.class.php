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
	 * @return array<string,array<string,mixed>>
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
	 * @return array<string,array<string,mixed>>
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
		if (!self::isModuleEnabled('facture')) {
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
			'min_php' => '8.0.0',
			'available' => empty($reasons),
			'reasons' => $reasons,
		);

		return $features;
	}

	/**
	 * Check whether a Dolibarr module is enabled.
	 *
	 * @param string $module Module key
	 * @return bool
	 */
	private static function isModuleEnabled($module)
	{
		global $conf;

		if (function_exists('isModEnabled')) {
			return isModEnabled($module);
		}

		return !empty($conf->{$module}->enabled);
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
