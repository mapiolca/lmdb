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
 * \file       htdocs/custom/lmdb/class/lmdbinvoicecustomerref.class.php
 * \ingroup    lmdb
 * \brief      Customer reference propagation for recurring invoices.
 */

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture-rec.class.php';

/**
 * Propagate and resolve the customer reference defined on a recurring invoice.
 */
class LmdbInvoiceCustomerRef
{
	public const EXTRAFIELD_CODE = 'capinvoicereffromrec_ref';
	public const EXTRAFIELD_KEY = 'options_capinvoicereffromrec_ref';

	/** @var DoliDB */
	private $db;

	/** @var string */
	public $error = '';

	/** @var array<int,string> */
	public $errors = array();

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Apply the recurring invoice customer reference to a generated invoice.
	 *
	 * This method runs inside the invoice creation transaction. The native
	 * setter is called without a second trigger so BILL_CREATE remains the
	 * single business event for the creation.
	 *
	 * @param Facture   $invoice Generated invoice
	 * @param Translate $langs   Translation handler
	 * @return int -1 on error, 0 when nothing must be done, 1 when updated
	 */
	public function apply(Facture $invoice, Translate $langs)
	{
		$langs->load('lmdb@lmdb');

		$sourceId = !empty($invoice->fk_fac_rec_source) ? (int) $invoice->fk_fac_rec_source : (int) $invoice->fac_rec;
		if ($sourceId <= 0) {
			return 0;
		}

		$template = '';
		$invoiceOptions = is_array($invoice->array_options) ? $invoice->array_options : array();
		if (array_key_exists(self::EXTRAFIELD_KEY, $invoiceOptions)) {
			$template = trim((string) $invoiceOptions[self::EXTRAFIELD_KEY]);
		} else {
			$recurringInvoice = new FactureRec($this->db);
			$result = $recurringInvoice->fetch($sourceId, '', '', 0, 1);
			if ($result <= 0) {
				$this->error = $langs->trans('LmdbRecurringInvoiceCustomerRefSourceError', $sourceId);
				$this->errors[] = $this->error;
				return -1;
			}

			if ((int) $recurringInvoice->entity !== (int) $invoice->entity) {
				$this->error = $langs->trans('LmdbRecurringInvoiceCustomerRefEntityError', $invoice->ref);
				$this->errors[] = $this->error;
				return -1;
			}

			$template = isset($recurringInvoice->array_options[self::EXTRAFIELD_KEY])
				? trim((string) $recurringInvoice->array_options[self::EXTRAFIELD_KEY])
				: '';
		}
		if ($template === '') {
			return 0;
		}

		$customerReference = self::resolve($template, $invoice, $langs);
		if (dol_strlen($customerReference) > 255) {
			$this->error = $langs->trans('LmdbRecurringInvoiceCustomerRefTooLong', $invoice->ref);
			$this->errors[] = $this->error;
			return -1;
		}
		$result = $invoice->set_ref_client($customerReference, 1);
		if ($result <= 0) {
			$this->error = $invoice->error !== ''
				? $invoice->error
				: $langs->trans('LmdbRecurringInvoiceCustomerRefUpdateError', $invoice->ref);
			$this->errors = !empty($invoice->errors) ? $invoice->errors : array($this->error);
			return -1;
		}

		$invoice->context['lmdb_recurring_invoice_customer_ref'] = true;
		dol_syslog(__METHOD__.': customer reference propagated to invoice id='.(int) $invoice->id, LOG_DEBUG);

		return 1;
	}

	/**
	 * Resolve native and invoice-period substitutions in a reference template.
	 *
	 * @param string    $template Reference template
	 * @param Facture   $invoice  Generated invoice
	 * @param Translate $langs    Translation handler
	 * @return string Resolved customer reference
	 */
	public static function resolve($template, Facture $invoice, Translate $langs)
	{
		$langs->loadLangs(array('main', 'bills', 'companies', 'other', 'lmdb@lmdb'));

		/** @var array<string,mixed> $substitutions */
		$substitutions = getCommonSubstitutionArray($langs, 0, null, $invoice);
		foreach (self::getPeriodSubstitutions($invoice, $langs) as $key => $value) {
			$substitutions[$key] = $value;
		}

		complete_substitutions_array($substitutions, $langs, $invoice);
		self::alignInvoiceYearWithRelativeMonth($template, $substitutions);

		return make_substitutions($template, $substitutions, $langs);
	}

	/**
	 * Align the generic invoice year when it is combined with a relative month.
	 *
	 * The adjustment is deliberately contextual: __INVOICE_YEAR__ keeps the
	 * generated invoice year when used alone or with the current month. When a
	 * template uses the previous or next month, the year follows that month only
	 * at the January/December boundary. Explicit PREVIOUS_YEAR and NEXT_YEAR
	 * substitutions remain unchanged.
	 *
	 * @param string              $template      Reference template
	 * @param array<string,mixed> $substitutions Substitution values
	 * @return void
	 */
	private static function alignInvoiceYearWithRelativeMonth($template, &$substitutions)
	{
		if (strpos($template, '__INVOICE_YEAR__') === false) {
			return;
		}

		$usesPreviousMonth = strpos($template, '__INVOICE_PREVIOUS_MONTH__') !== false
			|| strpos($template, '__INVOICE_PREVIOUS_MONTH_TEXT__') !== false;
		$usesNextMonth = strpos($template, '__INVOICE_NEXT_MONTH__') !== false
			|| strpos($template, '__INVOICE_NEXT_MONTH_TEXT__') !== false;

		// A single generic year cannot describe both relative directions safely.
		if ($usesPreviousMonth === $usesNextMonth) {
			return;
		}

		if ($usesNextMonth
			&& isset($substitutions['__INVOICE_NEXT_MONTH__'], $substitutions['__INVOICE_NEXT_YEAR__'])
			&& (string) $substitutions['__INVOICE_NEXT_MONTH__'] === '01') {
			$substitutions['__INVOICE_YEAR__'] = (string) $substitutions['__INVOICE_NEXT_YEAR__'];
		}

		if ($usesPreviousMonth
			&& isset($substitutions['__INVOICE_PREVIOUS_MONTH__'], $substitutions['__INVOICE_PREVIOUS_YEAR__'])
			&& (string) $substitutions['__INVOICE_PREVIOUS_MONTH__'] === '12') {
			$substitutions['__INVOICE_YEAR__'] = (string) $substitutions['__INVOICE_PREVIOUS_YEAR__'];
		}
	}

	/**
	 * Build invoice-period substitutions using the generated invoice date.
	 *
	 * @param Facture   $invoice Invoice object
	 * @param Translate $langs   Output language
	 * @return array<string,string> Period substitutions
	 */
	public static function getPeriodSubstitutions(Facture $invoice, Translate $langs)
	{
		$langs->loadLangs(array('main', 'bills', 'lmdb@lmdb'));

		$invoiceDate = !empty($invoice->date) ? (int) $invoice->date : dol_now();
		$previousMonthDate = dol_time_plus_duree($invoiceDate, -1, 'm');
		$nextMonthDate = dol_time_plus_duree($invoiceDate, 1, 'm');
		$previousYearDate = dol_time_plus_duree($invoiceDate, -1, 'y');
		$nextYearDate = dol_time_plus_duree($invoiceDate, 1, 'y');

		return array(
			'__INVOICE_PREVIOUS_MONTH__' => dol_print_date($previousMonthDate, '%m', 'tzserver', $langs),
			'__INVOICE_MONTH__' => dol_print_date($invoiceDate, '%m', 'tzserver', $langs),
			'__INVOICE_NEXT_MONTH__' => dol_print_date($nextMonthDate, '%m', 'tzserver', $langs),
			'__INVOICE_PREVIOUS_MONTH_TEXT__' => dol_print_date($previousMonthDate, '%B', 'tzserver', $langs),
			'__INVOICE_MONTH_TEXT__' => dol_print_date($invoiceDate, '%B', 'tzserver', $langs),
			'__INVOICE_NEXT_MONTH_TEXT__' => dol_print_date($nextMonthDate, '%B', 'tzserver', $langs),
			'__INVOICE_PREVIOUS_YEAR__' => dol_print_date($previousYearDate, '%Y', 'tzserver', $langs),
			'__INVOICE_YEAR__' => dol_print_date($invoiceDate, '%Y', 'tzserver', $langs),
			'__INVOICE_NEXT_YEAR__' => dol_print_date($nextYearDate, '%Y', 'tzserver', $langs),
		);
	}
}
