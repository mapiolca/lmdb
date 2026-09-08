<?php
/* Copyright (C) 2024 Eric Seigne <eric.seigne@cap-rel.fr>
 * Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       htdocs/custom/lmdb/core/substitutions/functions_lmdb.lib.php
 * \ingroup    lmdb
 * \brief      LMDB substitutions for invoices and generated documents.
 */

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once __DIR__.'/../../class/lmdbinvoicecustomerref.class.php';

/**
 * Add LMDB invoice-period substitutions to native Dolibarr substitutions.
 *
 * Dolibarr calls this function from complete_substitutions_array(), including
 * during PDF note rendering and email/document substitution workflows.
 *
 * @param array<string,mixed> $substitutionarray Substitution values
 * @param Translate           $outputlangs       Output language
 * @param mixed               $object            Current business object
 * @param mixed               $parameters        Optional caller parameters
 * @return void
 */
function lmdb_completesubstitutionarray(&$substitutionarray, $outputlangs, $object = null, $parameters = null)
{
	if (!is_array($substitutionarray) || !($object instanceof Facture) || !($outputlangs instanceof Translate)) {
		return;
	}

	try {
		$periodSubstitutions = LmdbInvoiceCustomerRef::getPeriodSubstitutions($object, $outputlangs);
	} catch (RuntimeException $exception) {
		// The generic substitution hook must not abort unrelated PDF/email flows.
		// Reference persistence performs its own strict check in apply().
		dol_syslog(__FUNCTION__.': '.$exception->getMessage(), LOG_ERR);
		return;
	}
	foreach ($periodSubstitutions as $key => $value) {
		$substitutionarray[$key] = $value;
	}
}
