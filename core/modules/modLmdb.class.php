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
 * \file       htdocs/custom/lmdb/core/modules/modLmdb.class.php
 * \ingroup    lmdb
 * \brief      Descriptor for LMDB module.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Module descriptor.
 */
class modLmdb extends DolibarrModules
{
	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;
		$this->numero = 450050;
		$this->rights_class = 'lmdb';
		$this->family = 'other';
		$this->module_position = 90;
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'LmdbModuleDescription';
		$this->descriptionlong = 'LmdbModuleDescriptionLong';
		$this->editor_name = 'Les Métiers du Bâtiment';
		$this->editor_url = 'https://lesmetiersdubatiment.fr';
		$this->version = '1.0.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'generic';

		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 1,
			'printing' => 0,
			'theme' => 0,
			'css' => array(),
			'js' => array(),
			'hooks' => array(),
			'moduleforexternal' => 0,
			'websitetemplates' => 0,
			'captcha' => 0,
		);

		$this->dirs = array('/lmdb/temp');
		$this->config_page_url = array('setup.php@lmdb');

		$this->hidden = 0;
		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('lmdb@lmdb');

		$this->phpmin = array(8, 0);
		$this->need_dolibarr_version = array(20, 0, 0);
		$this->need_javascript_ajax = 0;

		$this->const = array();
		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array();
		$this->rights = array();
		$this->menu = array();

		$r = 0;
		$this->rights[$r][0] = $this->numero + 1;
		$this->rights[$r][1] = 'Configure LMDB module';
		$this->rights[$r][4] = 'setup';
		$this->rights[$r][5] = 'configure';
		$r++;

		if (!isModEnabled('lmdb')) {
			$conf->lmdb = new stdClass();
			$conf->lmdb->enabled = 0;
		}
	}

	/**
	 * Function called when module is enabled.
	 *
	 * @param string $options Options when enabling module
	 * @return int 1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		global $conf;

		$this->remove($options);

		$model = 'lmdbsponge';
		$sql = array(
			"DELETE FROM ".MAIN_DB_PREFIX."document_model WHERE nom = '".$this->db->escape($model)."' AND type = 'invoice' AND entity = ".((int) $conf->entity),
			"INSERT INTO ".MAIN_DB_PREFIX."document_model (nom, type, libelle, entity) VALUES ('".$this->db->escape($model)."', 'invoice', '".$this->db->escape('LMDB Sponge')."', ".((int) $conf->entity).")",
		);

		return $this->_init($sql, $options);
	}

	/**
	 * Function called when module is disabled.
	 *
	 * User preferences such as FACTURE_ADDON_PDF are intentionally kept.
	 *
	 * @param string $options Options when disabling module
	 * @return int 1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		global $conf;

		$model = 'lmdbsponge';
		$sql = array(
			"DELETE FROM ".MAIN_DB_PREFIX."document_model WHERE nom = '".$this->db->escape($model)."' AND type = 'invoice' AND entity = ".((int) $conf->entity),
		);

		return $this->_remove($sql, $options);
	}
}
