<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       htdocs/custom/lmdb/core/triggers/interface_99_modLmdb_LmdbTriggers.class.php
 * \ingroup    lmdb
 * \brief      LMDB trigger interface.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/comm/mailing/class/mailing.class.php';
require_once dol_buildpath('/lmdb/class/lmdbinvoiceautosend.class.php', 0);
require_once dol_buildpath('/lmdb/class/lmdbinvoicecustomerref.class.php', 0);

/**
 * LMDB triggers.
 */
class InterfaceLmdbTriggers extends DolibarrTriggers
{
	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->family = 'Les Métiers du Bâtiment';
		$this->description = 'LMDB triggers';
		$this->version = self::VERSIONS['dev'];
		$this->picto = 'lmdb@lmdb';
	}

	/**
	 * Handle Dolibarr business events.
	 *
	 * @param string       $action Trigger code
	 * @param CommonObject $object Business object
	 * @param User         $user   User responsible for the event
	 * @param Translate    $langs  Translation handler
	 * @param Conf         $conf   Dolibarr configuration
	 * @return int Negative on error, zero on success
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('lmdb')) {
			return 0;
		}

		if ($action === 'MAILING_DELETE' && $object instanceof Mailing) {
			$sql = "DELETE FROM ".MAIN_DB_PREFIX."lmdb_mailing_schedule";
			$sql .= " WHERE entity = ".((int) $object->entity);
			$sql .= " AND fk_mailing = ".((int) $object->id);
			if (!$this->db->query($sql)) {
				$this->error = $langs->trans('LmdbScheduledMailingStorageError');
				$this->errors[] = $this->error;
				dol_syslog(__METHOD__.': unable to delete schedule for mailing id='.(int) $object->id, LOG_ERR);
				return -1;
			}

			return 0;
		}

		if ($action === 'LMDB_ATTESTATION_UPDATE' && $object instanceof LmdbAttestation && isset($object->context['lmdb_attestation_mail_id'])) {
			if (!$user->hasRight('lmdb','lmdbattestation','send')) { return -1; }
			$sql = 'UPDATE '.MAIN_DB_PREFIX."lmdb_attestation_email SET status='sent',date_sent='".$this->db->idate(dol_now())."' WHERE rowid=".(int)$object->context['lmdb_attestation_mail_id'].' AND fk_attestation='.(int)$object->id.' AND entity='.(int)$object->entity." AND status='pending'";
			if (!$this->db->query($sql)) { $this->error=$this->db->lasterror(); return -1; }
			return 0;
		}
		if (!($object instanceof Facture)) {
			return 0;
		}

		if ($action === 'BILL_CREATE') {
			if (!getDolGlobalInt('LMDB_RECURRING_INVOICE_CUSTOMER_REF') || isModEnabled('capinvoicereffromrec')) {
				return 0;
			}

			$customerRef = new LmdbInvoiceCustomerRef($this->db);
			$result = $customerRef->apply($object, $langs);
			if ($result < 0) {
				$this->error = $customerRef->error;
				$this->errors = $customerRef->errors;
				dol_syslog(__METHOD__.': unable to propagate customer reference for invoice id='.(int) $object->id, LOG_ERR);
				return -1;
			}
		}

		if ($action === 'BILL_SENTBYMAIL') {
			$origin = !empty($object->context['lmdb_auto_invoice_send']) ? LmdbInvoiceAutoSend::ORIGIN_AUTOMATIC : LmdbInvoiceAutoSend::ORIGIN_MANUAL;
			$result = LmdbInvoiceAutoSend::markInvoiceSentFromTrigger($this->db, $object, $user, $origin);
			if ($result < 0) {
				$this->error = $langs->trans('LmdbAutoInvoiceSendLedgerError', $object->ref);
				$this->errors[] = $this->error;
				dol_syslog(__METHOD__.': unable to update ledger for invoice id='.(int) $object->id, LOG_ERR);
				return -1;
			}
		}

		return 0;
	}
}
