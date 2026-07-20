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
		$this->family = 'Les Métiers du Bâtiment';
		$this->module_position = 90;
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'LmdbModuleDescription';
		$this->descriptionlong = 'LmdbModuleDescriptionLong';
		$this->editor_name = 'Les Métiers du Bâtiment';
		$this->editor_url = 'https://lesmetiersdubatiment.fr';
		$this->version = '1.2.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'lmdb@lmdb';

		$this->module_parts = array(
			'triggers' => 1,
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
			'hooks' => array(
				'mailingcard',
			),
			'moduleforexternal' => 0,
			'websitetemplates' => 0,
			'captcha' => 0,
		);

		$this->dirs = array('/lmdb/temp');
		$this->config_page_url = array('setup.php@lmdb');

		$this->hidden = 0;
		$this->depends = array('modFacture');
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
		$this->cronjobs = array(
			0 => array(
				'label' => 'LmdbAutoInvoiceSendCronLabel:lmdb@lmdb',
				'jobtype' => 'method',
				'class' => '/lmdb/class/lmdbinvoiceautosend.class.php',
				'objectname' => 'LmdbInvoiceAutoSend',
				'method' => 'run',
				'parameters' => '',
				'comment' => 'LmdbAutoInvoiceSendCronComment',
				'frequency' => 1,
				'unitfrequency' => 86400,
				'status' => 1,
				'test' => 'isModEnabled("lmdb") && isModEnabled("invoice")',
				'priority' => 60,
			),
			1 => array(
				'label' => 'LmdbScheduledMailingCronLabel:lmdb@lmdb',
				'jobtype' => 'method',
				'class' => '/lmdb/class/lmdbmailingautosend.class.php',
				'objectname' => 'LmdbMailingAutoSend',
				'method' => 'run',
				'parameters' => '',
				'comment' => 'LmdbScheduledMailingCronComment',
				'frequency' => 5,
				'unitfrequency' => 60,
				'status' => 1,
				'test' => 'isModEnabled("lmdb") && isModEnabled("mailing") && isModEnabled("cron")',
				'priority' => 50,
			),
		);
		$this->rights = array();
		$this->menu = array();

		$r = 0;
		$r++;
		$this->rights[$r][0] = $this->numero * 100 + $r;
		$this->rights[$r][1] = 'LmdbPermissionConfigure';
		$this->rights[$r][4] = 'setup';
		$this->rights[$r][5] = 'configure';

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

		$result = $this->_load_tables('/lmdb/sql/');
		if ($result <= 0) {
			$this->error = 'Failed to install LMDB database tables';
			return 0;
		}

		if ($this->installInvoiceAutoSendExtraFields() <= 0) {
			return 0;
		}
		if ($this->installScheduledMailingExtraField() <= 0) {
			return 0;
		}

		if ($this->initializeInvoiceAutoSendConstants((int) $conf->entity) <= 0) {
			return 0;
		}
		if ($this->initializeScheduledMailingConstants((int) $conf->entity) <= 0) {
			return 0;
		}

		require_once dol_buildpath('/lmdb/class/lmdbinvoiceautosend.class.php', 0);
		if (LmdbInvoiceAutoSend::normalizeCronTranslationKeys($this->db, (int) $conf->entity) <= 0) {
			$this->error = $this->db->lasterror();
			return 0;
		}
		require_once dol_buildpath('/lmdb/class/lmdbmailingautosend.class.php', 0);
		if (LmdbMailingAutoSend::normalizeCronTranslationKeys($this->db, (int) $conf->entity) <= 0) {
			$this->error = $this->db->lasterror();
			return 0;
		}

		$model = 'lmdbsponge';
		$sql = array(
			"INSERT INTO ".MAIN_DB_PREFIX."document_model (nom, type, libelle, entity)"
				." SELECT '".$this->db->escape($model)."', 'invoice', '".$this->db->escape('LMDB Sponge')."', ".((int) $conf->entity)
				." WHERE NOT EXISTS (SELECT rowid FROM ".MAIN_DB_PREFIX."document_model"
				." WHERE nom = '".$this->db->escape($model)."' AND type = 'invoice' AND entity = ".((int) $conf->entity).")",
		);

		$result = $this->_init($sql, $options);
		if ($result <= 0) {
			return $result;
		}

		return $this->migrateLegacyPermission((int) $conf->entity);
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
		$cronjobs = $this->cronjobs;
		$this->cronjobs = array();
		$result = $this->_remove(array(), $options);
		$this->cronjobs = $cronjobs;

		return $result;
	}

	/**
	 * Add or take ownership of Delegation invoice auto-send extrafields.
	 *
	 * Existing columns and values are preserved.
	 *
	 * @return int 1 if OK, 0 if KO
	 */
	private function installInvoiceAutoSendExtraFields()
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

		$templateParameters = array(
			'options' => array(
				'c_email_templates:label:rowid::((type_template:=:\'facture_send\') AND (entity:=:$ENTITY$))' => null,
			),
		);
		$entity = (string) ((int) $conf->entity);
		/** @var array<int,array{0:string,1:string,2:string,3:int,4:string,5:string,6:int,7:array<string,mixed>|string,8:int,9:string,10:string}> $definitions */
		$definitions = array(
			array('lmdb_envoi_auto', 'LmdbAutoInvoiceSend', 'boolean', 3, '', 'facture', 0, '', 0, 'LmdbAutoInvoiceSendHelp', '0'),
			array('lmdb_template', 'LmdbAutoInvoiceEmailTemplate', 'sellist', 4, '', 'facture', 0, $templateParameters, 0, 'LmdbAutoInvoiceEmailTemplateHelp', ''),
			array('lmdb_envoi_auto', 'LmdbAutoInvoiceSend', 'boolean', 3, '', 'facture_rec', 1, '', 3, 'LmdbAutoInvoiceSendHelp', '0'),
			array('lmdb_template', 'LmdbAutoInvoiceEmailTemplate', 'sellist', 4, '', 'facture_rec', 1, $templateParameters, 3, 'LmdbAutoInvoiceEmailTemplateHelp', ''),
		);

		foreach ($definitions as $definition) {
			$extrafields = new ExtraFields($this->db);
			$existing = $extrafields->fetch_name_optionals_label($definition[5], true, $definition[0]);
			if (isset($existing[$definition[0]])) {
				$result = $extrafields->updateExtraField(
					$definition[0],
					$definition[1],
					$definition[2],
					$definition[3],
					$definition[4],
					$definition[5],
					0,
					0,
					$definition[10],
					$definition[7],
					$definition[6],
					'',
					(string) $definition[8],
					$definition[9],
					'',
					$entity,
					'lmdb@lmdb',
					'isModEnabled("lmdb")',
					0,
					0,
					array()
				);
			} else {
				$result = $extrafields->addExtraField(
					$definition[0],
					$definition[1],
					$definition[2],
					$definition[3],
					$definition[4],
					$definition[5],
					0,
					0,
					$definition[10],
					$definition[7],
					$definition[6],
					'',
					(string) $definition[8],
					$definition[9],
					'',
					$entity,
					'lmdb@lmdb',
					'isModEnabled("lmdb")',
					0,
					0,
					array()
				);
			}
			if ($result <= 0) {
				$this->error = $extrafields->error;
				return 0;
			}
		}

		return 1;
	}

	/**
	 * Add the scheduled send date to native Dolibarr email campaigns.
	 *
	 * The visibility value 4 keeps the field out of the creation form while
	 * retaining it on view and list screens and allowing native inline editing.
	 * Existing definitions and values are updated conservatively and never
	 * removed on deactivation.
	 *
	 * @return int 1 if OK, 0 if KO
	 */
	private function installScheduledMailingExtraField()
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

		/** @var array<int,array{attrname:string,label:string,pos:int,alwayseditable:int,perms:string,list:string,help:string}> $definitions */
		$definitions = array(
			array(
				'attrname' => 'lmdb_scheduled_send_at',
				'label' => 'LmdbScheduledMailingSendAt',
				'pos' => 100,
				'alwayseditable' => 1,
				'perms' => '$user->admin || $user->hasRight("mailing", "valider")',
				'list' => '4',
				'help' => 'LmdbScheduledMailingSendAtHelp',
			),
			array(
				'attrname' => 'lmdb_scheduled_started_at',
				'label' => 'LmdbScheduledMailingStartedAt',
				'pos' => 101,
				'alwayseditable' => 0,
				'perms' => '0',
				'list' => '0',
				'help' => '',
			),
		);

		foreach ($definitions as $definition) {
			$extrafields = new ExtraFields($this->db);
			$existing = $extrafields->fetch_name_optionals_label('mailing', true, $definition['attrname']);
			if (isset($existing[$definition['attrname']])) {
				$result = $extrafields->updateExtraField(
					$definition['attrname'], $definition['label'], 'datetime', $definition['pos'], '', 'mailing', 0, 0, '', '',
					$definition['alwayseditable'], $definition['perms'], $definition['list'], $definition['help'], '',
					(string) ((int) $conf->entity), 'lmdb@lmdb', 'isModEnabled("lmdb") && isModEnabled("mailing")', 0, 0, array()
				);
			} else {
				$result = $extrafields->addExtraField(
					$definition['attrname'], $definition['label'], 'datetime', $definition['pos'], '', 'mailing', 0, 0, '', '',
					$definition['alwayseditable'], $definition['perms'], $definition['list'], $definition['help'], '',
					(string) ((int) $conf->entity), 'lmdb@lmdb', 'isModEnabled("lmdb") && isModEnabled("mailing")', 0, 0, array()
				);
			}
			if ($result <= 0) {
				$this->error = $extrafields->error;
				return 0;
			}
		}

		return 1;
	}

	/**
	 * Create conservative defaults only when they do not already exist.
	 *
	 * @param int $entity Entity id
	 * @return int 1 if OK, 0 if KO
	 */
	private function initializeInvoiceAutoSendConstants($entity)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		if (getDolGlobalInt('LMDB_AUTO_INVOICE_SEND_MAX_PER_RUN') <= 0) {
			$result = dolibarr_set_const($this->db, 'LMDB_AUTO_INVOICE_SEND_MAX_PER_RUN', '100', 'chaine', 0, '', (int) $entity);
			if ($result <= 0) {
				$this->error = $this->db->lasterror();
				return 0;
			}
		}

		if (getDolGlobalInt('LMDB_AUTO_INVOICE_SEND_MIN_ID') <= 0) {
			$sql = "SELECT MAX(rowid) AS maxid FROM ".MAIN_DB_PREFIX."facture WHERE entity = ".((int) $entity);
			$resql = $this->db->query($sql);
			if (!$resql || !is_object($obj = $this->db->fetch_object($resql))) {
				$this->error = $this->db->lasterror();
				return 0;
			}
			$minimumInvoiceId = (int) $obj->maxid + 1;
			$result = dolibarr_set_const($this->db, 'LMDB_AUTO_INVOICE_SEND_MIN_ID', (string) $minimumInvoiceId, 'chaine', 0, '', (int) $entity);
			if ($result <= 0) {
				$this->error = $this->db->lasterror();
				return 0;
			}
		}

		return 1;
	}

	/**
	 * Create the scheduled mailing processing limit only when absent.
	 *
	 * @param int $entity Entity id
	 * @return int 1 if OK, 0 if KO
	 */
	private function initializeScheduledMailingConstants($entity)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		if (getDolGlobalString('LMDB_SCHEDULED_MAILING_MAX_PER_RUN') === '') {
			$result = dolibarr_set_const($this->db, 'LMDB_SCHEDULED_MAILING_MAX_PER_RUN', '10', 'chaine', 0, '', (int) $entity);
			if ($result <= 0) {
				$this->error = $this->db->lasterror();
				return 0;
			}
		}

		return 1;
	}

	/**
	 * Migrate the former permission id while preserving user and group assignments.
	 * The old definition may already have been removed by a normal module
	 * deactivation, while its user and group assignments are still present.
	 *
	 * @param int $entity Entity id
	 * @return int 1 if OK, 0 if KO
	 */
	private function migrateLegacyPermission($entity)
	{
		$oldPermissionId = 450051;
		$newPermissionId = $this->numero * 100 + 1;

		$sql = "SELECT module FROM ".MAIN_DB_PREFIX."rights_def";
		$sql .= " WHERE entity = ".((int) $entity)." AND id = ".((int) $oldPermissionId);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return 0;
		}
		$oldDefinition = $this->db->fetch_object($resql);
		if (is_object($oldDefinition) && (string) $oldDefinition->module !== 'lmdb') {
			return 1;
		}

		$this->db->begin();

		$sqlQueries = array(
			"INSERT IGNORE INTO ".MAIN_DB_PREFIX."user_rights (entity, fk_user, fk_id)"
				." SELECT entity, fk_user, ".((int) $newPermissionId)." FROM ".MAIN_DB_PREFIX."user_rights"
				." WHERE entity = ".((int) $entity)." AND fk_id = ".((int) $oldPermissionId),
			"INSERT IGNORE INTO ".MAIN_DB_PREFIX."usergroup_rights (entity, fk_usergroup, fk_id)"
				." SELECT entity, fk_usergroup, ".((int) $newPermissionId)." FROM ".MAIN_DB_PREFIX."usergroup_rights"
				." WHERE entity = ".((int) $entity)." AND fk_id = ".((int) $oldPermissionId),
			"DELETE FROM ".MAIN_DB_PREFIX."user_rights WHERE entity = ".((int) $entity)." AND fk_id = ".((int) $oldPermissionId),
			"DELETE FROM ".MAIN_DB_PREFIX."usergroup_rights WHERE entity = ".((int) $entity)." AND fk_id = ".((int) $oldPermissionId),
			"DELETE FROM ".MAIN_DB_PREFIX."rights_def WHERE entity = ".((int) $entity)." AND id = ".((int) $oldPermissionId)." AND module = 'lmdb'",
		);

		foreach ($sqlQueries as $sql) {
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return 0;
			}
		}

		$this->db->commit();
		return 1;
	}
}
