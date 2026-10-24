<?php
/* Copyright (C) 2026 Pierre Ardoin. SPDX-License-Identifier: GPL-3.0-or-later */
/** Conservative installation of document, event, email and sharing declarations. */
class LmdbAttestationInstaller
{
	/** @return int 1 on success, -1 on failure; caller surfaces database errors. */
	public static function install($db, $entity)
	{
		$defaults = array('LMDB_ATTESTATION_ADDON'=>'mod_lmdbattestation_standard','LMDB_ATTESTATION_MASK'=>'ATLF-{yyyy}-{0000}', 'LMDB_ATTESTATION_ADDON_PDF'=>'loifinance');
		foreach ($defaults as $key=>$value) {
			$res = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."const WHERE name='".$db->escape($key)."' AND entity=".(int)$entity);
			if (!$res) { return -1; }
			if (!$db->num_rows($res) && dolibarr_set_const($db,$key,$value,'chaine',0,'',(int)$entity) <= 0) { return -1; }
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."document_model (nom,type,libelle,entity) SELECT 'loifinance','lmdbattestation','LmdbAttestation',".(int)$entity." WHERE NOT EXISTS (SELECT rowid FROM ".MAIN_DB_PREFIX."document_model WHERE nom='loifinance' AND type='lmdbattestation' AND entity=".(int)$entity.")";
		if (!$db->query($sql)) { return -1; }
		foreach (array('CREATE','UPDATE','DELETE') as $crud) {
			$code = 'LMDB_ATTESTATION_'.$crud;
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."c_action_trigger (elementtype,code,label,description,rang) SELECT 'lmdbattestation@lmdb','".$code."','".$code."','Individual attestation CRUD event; detail in object context',450050 WHERE NOT EXISTS (SELECT rowid FROM ".MAIN_DB_PREFIX."c_action_trigger WHERE code='".$code."')";
			if (!$db->query($sql)) { return -1; }
		}
		$templates = array(
			'fr_FR'=>array('Attestation Loi Finance','Votre attestation de conformité __LMDB_ATTESTATION_REF__','Bonjour,<br><br>Veuillez trouver en pièce jointe votre attestation de conformité pour Dolibarr __LMDB_ATTESTATION_VERSION__, datée du __LMDB_ATTESTATION_DATE__.<br>Merci de compléter et signer le volet 2, puis de nous retourner les deux volets.<br><br>Cordialement.'),
			'en_US'=>array('Finance Act attestation','Your conformity attestation __LMDB_ATTESTATION_REF__','Hello,<br><br>Please find attached your French conformity attestation for Dolibarr __LMDB_ATTESTATION_VERSION__, issued on __LMDB_ATTESTATION_DATE__.<br>Please complete and sign part 2 and return both parts.<br><br>Kind regards.'),
		);
		foreach ($templates as $lang=>$template) {
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."c_email_templates (entity,module,type_template,lang,label,position,defaultfortype,active,topic,content,joinfiles) SELECT ".(int)$entity.",'lmdb','lmdbattestation_send','".$lang."','".$db->escape($template[0])."',10,1,1,'".$db->escape($template[1])."','".$db->escape($template[2])."','1' WHERE NOT EXISTS (SELECT rowid FROM ".MAIN_DB_PREFIX."c_email_templates WHERE entity=".(int)$entity." AND type_template='lmdbattestation_send' AND lang='".$lang."')";
			if (!$db->query($sql)) { return -1; }
		}
		require_once __DIR__.'/actions_lmdb.class.php';
		$res = $db->query("SELECT value FROM ".MAIN_DB_PREFIX."const WHERE name='MULTICOMPANY_EXTERNAL_MODULES_SHARING' AND entity=".(int)$entity);
		if (!$res) { return -1; }
		$row = $db->fetch_object($res);
		$sharing = is_object($row) ? json_decode($row->value, true) : array();
		if (!is_array($sharing)) { return -1; }
		// Existing administrator options win over supplied defaults.
		$sharing = array_replace_recursive(ActionsLmdb::getMulticompanySharingDefinition(), $sharing);
		return dolibarr_set_const($db,'MULTICOMPANY_EXTERNAL_MODULES_SHARING',json_encode($sharing),'chaine',0,'',(int)$entity) > 0 ? 1 : -1;
	}
}
