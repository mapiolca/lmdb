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
require_once dol_buildpath('/lmdb/class/lmdbinvoiceautosend.class.php', 0);

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
	 * @return int <0 on error, 0 when not handled, >0 when handled
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('lmdb') || $action !== 'BILL_SENTBYMAIL' || !($object instanceof Facture)) {
			return 0;
		}

		$origin = !empty($object->context['lmdb_auto_invoice_send']) ? LmdbInvoiceAutoSend::ORIGIN_AUTOMATIC : LmdbInvoiceAutoSend::ORIGIN_MANUAL;
		$result = LmdbInvoiceAutoSend::markInvoiceSentFromTrigger($this->db, $object, $user, $origin);
		if ($result < 0) {
			$this->error = $langs->trans('LmdbAutoInvoiceSendLedgerError', $object->ref);
			$this->errors[] = $this->error;
			dol_syslog(__METHOD__.': unable to update ledger for invoice id='.(int) $object->id, LOG_ERR);
			return -1;
		}

		return $result;
	}
}
