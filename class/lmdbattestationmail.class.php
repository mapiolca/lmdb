<?php
/* Copyright (C) 2026 Pierre Ardoin. SPDX-License-Identifier: GPL-3.0-or-later */
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formmail.class.php';
/** Native email form with an object-specific template boundary. The core also
 * returns generic 'all' templates, which are deliberately excluded here. */
class LmdbAttestationMail extends FormMail
{
	public function fetchAllEMailTemplate($type_template, $user, $outputlangs, $active = 1)
	{
		$result = parent::fetchAllEMailTemplate('lmdbattestation_send', $user, $outputlangs, $active);
		if ($result < 0) { return $result; }
		$allowed = self::templateIds($this->db, $user);
		if ($allowed === false) { $this->lines_model = array(); return -1; }
		$this->lines_model = array_values(array_filter($this->lines_model, static function ($model) use ($allowed) { return in_array((int)$model->id,$allowed,true); }));
		return count($this->lines_model);
	}
	/** @return list<int>|false Exactly typed templates, entity and privacy checked. */
	public static function templateIds($db, User $user)
	{
		$res = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."c_email_templates WHERE type_template='lmdbattestation_send' AND active=1 AND entity IN (".$db->sanitize(getEntity('c_email_templates')).") AND (private=0 OR fk_user=".(int)$user->id.") ORDER BY defaultfortype DESC, position, rowid");
		if (!$res) { return false; }
		$ids = array(); while (is_object($row=$db->fetch_object($res))) { $ids[]=(int)$row->rowid; } return $ids;
	}
	/** Consume a submission before SMTP. A pending send is never retried implicitly. @return int */
	public static function reserve($db, $object, User $user, $submission)
	{
		if (!$db->begin()) { return -1; }
		$res = $db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'lmdb_attestation WHERE rowid='.(int)$object->id.' AND entity='.(int)$object->entity.' AND status=1 FOR UPDATE');
		if (!$res || !$db->num_rows($res)) { $db->rollback(); return -1; }
		$res = $db->query('SELECT rowid FROM '.MAIN_DB_PREFIX."lmdb_attestation_email WHERE fk_attestation=".(int)$object->id.' AND entity='.(int)$object->entity." AND status='pending'");
		if (!$res || $db->num_rows($res)) { $db->rollback(); return -1; }
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX."lmdb_attestation_email (entity,fk_attestation,submission_hash,status,date_creation,fk_user) VALUES (".(int)$object->entity.','.(int)$object->id.",'".$db->escape(hash('sha256',$submission))."','pending','".$db->idate(dol_now())."',".(int)$user->id.')';
		if (!$db->query($sql)) { $db->rollback(); return -1; }
		$id = $db->last_insert_id(MAIN_DB_PREFIX.'lmdb_attestation_email');
		if (!$db->commit()) { $db->rollback(); return -1; }
		return (int)$id;
	}
}
