<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       htdocs/custom/lmdb/class/lmdbinvoiceautosend.class.php
 * \ingroup    lmdb
 * \brief      Automatic sending of invoices generated from recurring templates.
 */

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formmail.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

/**
 * Invoice object with the transient mail properties used by Dolibarr triggers.
 *
 * Dolibarr v20 sets these properties dynamically from actions_sendmails.inc.php.
 * Declaring them here keeps the external module statically analysable without
 * changing or copying the core Facture class.
 */
class LmdbMailFacture extends Facture
{
	/** @var string */
	public $trackid = '';

	/** @var string */
	public $elementtype = '';

	/** @var array<int,string> */
	public $attachedfiles = array();

	/** @var string */
	public $email_msgid = '';

	/** @var string */
	public $email_from = '';

	/** @var string */
	public $email_subject = '';

	/** @var string */
	public $email_to = '';

	/** @var string */
	public $email_tocc = '';

	/** @var string */
	public $email_tobcc = '';
}

/**
 * Automatic recurring invoice email service.
 */
class LmdbInvoiceAutoSend
{
	const STATUS_PROCESSING = 0;
	const STATUS_SENT = 1;
	const STATUS_ERROR = 2;
	const STATUS_REVIEW = 3;
	const PROCESSING_STALE_DELAY = 21600;

	const ORIGIN_AUTOMATIC = 'automatic';
	const ORIGIN_MANUAL = 'manual';

	/** @var DoliDB */
	public $db;

	/** @var string */
	public $output = '';

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
	 * Run the native scheduled job.
	 *
	 * @return int 0 on success, positive error count on failure
	 */
	public function run()
	{
		global $conf, $langs, $user;

		$this->output = '';
		$this->error = '';
		$this->errors = array();

		$langs->loadLangs(array('bills', 'errors', 'mails', 'lmdb@lmdb'));

		if (!isModEnabled('lmdb') || !isModEnabled('invoice')) {
			$this->error = $langs->trans('LmdbAutoInvoiceSendModulesUnavailable');
			return 1;
		}

		$entity = (int) $conf->entity;
		if (self::hasActiveDelegationCron($this->db, $entity)) {
			$this->error = $langs->trans('LmdbDelegationCronConflict');
			dol_syslog(__METHOD__.': '.$this->error, LOG_ERR);
			return 1;
		}

		$minimumInvoiceId = getDolGlobalInt('LMDB_AUTO_INVOICE_SEND_MIN_ID');
		if ($minimumInvoiceId <= 0) {
			$minimumInvoiceId = $this->initializeMinimumInvoiceId($entity);
			if ($minimumInvoiceId <= 0) {
				$this->error = $langs->trans('LmdbAutoInvoiceSendStartMarkerError');
				return 1;
			}
			$this->output = $langs->trans('LmdbAutoInvoiceSendStartMarkerInitialized', $minimumInvoiceId);
			return 0;
		}

		$maxPerRun = getDolGlobalInt('LMDB_AUTO_INVOICE_SEND_MAX_PER_RUN', 100);
		if (!in_array($maxPerRun, array(25, 50, 100, 250), true)) {
			$maxPerRun = 100;
		}

		$movedToReview = $this->markStaleProcessingForReview($entity);
		if ($movedToReview < 0) {
			$this->errors[] = $this->db->lasterror();
		}

		$candidates = $this->fetchCandidateInvoices($entity, $minimumInvoiceId, $maxPerRun);
		if ($candidates === null) {
			$this->error = $this->db->lasterror();
			return 1;
		}

		$analysed = 0;
		$sent = 0;
		$skipped = 0;
		$failed = 0;

		foreach ($candidates as $candidate) {
			$analysed++;
			$invoiceId = (int) $candidate['id'];
			$templateId = (int) $candidate['template_id'];

			$claim = $this->claimInvoice($entity, $invoiceId, $templateId, (int) $user->id);
			if ($claim === 0) {
				$skipped++;
				continue;
			}
			if ($claim < 0) {
				$failed++;
				$this->errors[] = $langs->trans('LmdbAutoInvoiceSendClaimError', $invoiceId);
				continue;
			}

			try {
				$result = $this->sendInvoice($invoiceId, $templateId, $entity, $user, $langs);
			} catch (Throwable $exception) {
				dol_syslog(__METHOD__.': unexpected '.get_class($exception).' for invoice id='.$invoiceId, LOG_ERR);
				$result = $this->recordFailure($entity, $invoiceId, $langs->trans('LmdbAutoInvoiceSendUnexpectedError', $invoiceId));
			}
			if ($result > 0) {
				$sent++;
			} elseif ($result === 0) {
				$skipped++;
			} else {
				$failed++;
			}
		}

		$reviewCount = $this->countLedgerRows($entity, self::STATUS_REVIEW);
		if ($reviewCount < 0) {
			$this->errors[] = $this->db->lasterror();
			$reviewCount = 0;
		}

		$this->output = $langs->trans('LmdbAutoInvoiceSendCronResult', $analysed, $sent, $skipped, $failed, $reviewCount);
		if (!empty($this->errors)) {
			$this->error = implode(' | ', $this->errors);
		}

		dol_syslog(__METHOD__.': '.$this->output, $failed > 0 ? LOG_WARNING : LOG_INFO);

		return $failed > 0 || !empty($this->errors) ? max(1, $failed) : 0;
	}

	/**
	 * Return diagnostics used by the setup page.
	 *
	 * @param DoliDB $db     Database handler
	 * @param int    $entity Entity id
	 * @return array{legacy_cron_active:bool,lmdb_cron_registered:bool,lmdb_cron_active:bool,error_count:int,review_count:int}
	 */
	public static function getDiagnostics($db, $entity)
	{
		$diagnostics = array(
			'legacy_cron_active' => self::hasActiveDelegationCron($db, $entity),
			'lmdb_cron_registered' => false,
			'lmdb_cron_active' => false,
			'error_count' => 0,
			'review_count' => 0,
		);

		$sql = "SELECT status FROM ".MAIN_DB_PREFIX."cronjob";
		$sql .= " WHERE module_name = 'lmdb'";
		$sql .= " AND methodename = 'run'";
		$sql .= " AND objectname = 'LmdbInvoiceAutoSend'";
		$sql .= " AND entity = ".((int) $entity);
		$sql .= " ORDER BY rowid DESC";
		$sql .= $db->plimit(1);
		$resql = $db->query($sql);
		if ($resql && is_object($obj = $db->fetch_object($resql))) {
			$diagnostics['lmdb_cron_registered'] = true;
			$diagnostics['lmdb_cron_active'] = ((int) $obj->status === 1);
		}

		$sql = "SELECT status, COUNT(rowid) AS nb FROM ".MAIN_DB_PREFIX."lmdb_invoice_email";
		$sql .= " WHERE entity = ".((int) $entity);
		$sql .= " AND status IN (".self::STATUS_ERROR.", ".self::STATUS_REVIEW.")";
		$sql .= " GROUP BY status";
		$resql = $db->query($sql);
		if ($resql) {
			while (is_object($obj = $db->fetch_object($resql))) {
				if ((int) $obj->status === self::STATUS_ERROR) {
					$diagnostics['error_count'] = (int) $obj->nb;
				} elseif ((int) $obj->status === self::STATUS_REVIEW) {
					$diagnostics['review_count'] = (int) $obj->nb;
				}
			}
		}

		return $diagnostics;
	}

	/**
	 * Detect the legacy Delegation scheduled job that can send duplicates.
	 *
	 * @param DoliDB $db     Database handler
	 * @param int    $entity Entity id
	 * @return bool
	 */
	public static function hasActiveDelegationCron($db, $entity)
	{
		if (!isModEnabled('delegation')) {
			return false;
		}

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."cronjob";
		$sql .= " WHERE module_name = 'delegation'";
		$sql .= " AND entity = ".((int) $entity);
		$sql .= " AND status = 1";
		$sql .= " AND (methodename = 'sendEmailsNotificationOnInvoiceDate'";
		$sql .= " OR classesname LIKE '%delegation/class/facture.class.php')";
		$sql .= $db->plimit(1);
		$resql = $db->query($sql);

		return $resql && $db->num_rows($resql) > 0;
	}

	/**
	 * Mark a native manual invoice email as sent in the LMDB ledger.
	 *
	 * @param DoliDB  $db      Database handler
	 * @param Facture $invoice Invoice object
	 * @param User    $user    User responsible for the send
	 * @param string  $origin  Send origin
	 * @return int 0 when not applicable, 1 when recorded, -1 on error
	 */
	public static function markInvoiceSentFromTrigger($db, $invoice, $user, $origin = self::ORIGIN_MANUAL)
	{
		$invoiceId = (int) $invoice->id;
		$entity = !empty($invoice->entity) ? (int) $invoice->entity : 0;
		if ($invoiceId <= 0 || $entity <= 0) {
			return 0;
		}

		$minimumInvoiceId = self::getEntityConstantInt($db, 'LMDB_AUTO_INVOICE_SEND_MIN_ID', $entity);
		if ($minimumInvoiceId <= 0 || $invoiceId < $minimumInvoiceId) {
			return 0;
		}

		$sql = "SELECT fe.lmdb_envoi_auto, fe.lmdb_template";
		$sql .= " FROM ".MAIN_DB_PREFIX."facture AS f";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facture_extrafields AS fe ON fe.fk_object = f.rowid";
		$sql .= " WHERE f.rowid = ".$invoiceId;
		$sql .= " AND f.entity = ".$entity;
		$sql .= " AND f.fk_fac_rec_source > 0";
		$resql = $db->query($sql);
		if (!$resql || !is_object($obj = $db->fetch_object($resql)) || (int) $obj->lmdb_envoi_auto !== 1) {
			return $resql ? 0 : -1;
		}

		$invoiceValues = get_object_vars($invoice);
		$messageId = isset($invoiceValues['email_msgid']) ? (string) $invoiceValues['email_msgid'] : '';
		if ($messageId === '' && isset($invoice->context['email_msgid'])) {
			$messageId = (string) $invoice->context['email_msgid'];
		}
		$templateId = (int) $obj->lmdb_template;
		$now = dol_now();

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."lmdb_invoice_email";
		$sql .= " (entity, fk_facture, fk_email_template, status, origin, attempts, date_creation, date_attempt, date_sent, fk_user, message_id)";
		$sql .= " VALUES (".$entity.", ".$invoiceId.", ".($templateId > 0 ? $templateId : 'NULL').", ".self::STATUS_SENT;
		$sql .= ", '".$db->escape($origin)."', 1, '".$db->idate($now)."', '".$db->idate($now)."', '".$db->idate($now)."'";
		$sql .= ", ".((int) $user->id).", ".($messageId !== '' ? "'".$db->escape($messageId)."'" : 'NULL').")";
		$sql .= " ON DUPLICATE KEY UPDATE origin = IF(date_sent IS NULL, VALUES(origin), origin)";
		$sql .= ", status = ".self::STATUS_SENT;
		$sql .= ", date_sent = COALESCE(date_sent, VALUES(date_sent))";
		$sql .= ", fk_user = VALUES(fk_user)";
		$sql .= ", message_id = COALESCE(VALUES(message_id), message_id)";
		$sql .= ", last_error = NULL";

		return $db->query($sql) ? 1 : -1;
	}

	/**
	 * Fetch eligible candidates.
	 *
	 * @param int $entity          Entity id
	 * @param int $minimumInvoiceId First allowed invoice id
	 * @param int $limit           Maximum candidates
	 * @return array<int,array{id:int,template_id:int}>|null
	 */
	private function fetchCandidateInvoices($entity, $minimumInvoiceId, $limit)
	{
		$candidates = array();
		$sql = "SELECT f.rowid AS id, fe.lmdb_template AS template_id";
		$sql .= " FROM ".MAIN_DB_PREFIX."facture AS f";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facture_extrafields AS fe ON fe.fk_object = f.rowid";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."lmdb_invoice_email AS lie";
		$sql .= " ON lie.entity = f.entity AND lie.fk_facture = f.rowid";
		$sql .= " WHERE f.entity = ".((int) $entity);
		$sql .= " AND f.rowid >= ".((int) $minimumInvoiceId);
		$sql .= " AND f.fk_fac_rec_source > 0";
		$sql .= " AND f.paye = 0";
		$sql .= " AND f.fk_statut = ".Facture::STATUS_VALIDATED;
		$sql .= " AND fe.lmdb_envoi_auto = 1";
		$sql .= " AND (lie.rowid IS NULL OR lie.status = ".self::STATUS_ERROR.")";
		$sql .= " ORDER BY CASE WHEN lie.status = ".self::STATUS_ERROR." THEN 0 ELSE 1 END, f.rowid ASC";
		$sql .= $this->db->plimit((int) $limit);

		$resql = $this->db->query($sql);
		if (!$resql) {
			return null;
		}

		while (is_object($obj = $this->db->fetch_object($resql))) {
			$candidates[] = array('id' => (int) $obj->id, 'template_id' => (int) $obj->template_id);
		}

		return $candidates;
	}

	/**
	 * Atomically claim one invoice.
	 *
	 * @param int $entity     Entity id
	 * @param int $invoiceId  Invoice id
	 * @param int $templateId Email template id
	 * @param int $userId     User id
	 * @return int 1 claimed, 0 already handled, -1 on error
	 */
	private function claimInvoice($entity, $invoiceId, $templateId, $userId)
	{
		$this->db->begin();
		$sql = "SELECT rowid, status FROM ".MAIN_DB_PREFIX."lmdb_invoice_email";
		$sql .= " WHERE entity = ".((int) $entity)." AND fk_facture = ".((int) $invoiceId)." FOR UPDATE";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->db->rollback();
			return -1;
		}

		$obj = $this->db->fetch_object($resql);
		$now = dol_now();
		if (!is_object($obj)) {
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."lmdb_invoice_email";
			$sql .= " (entity, fk_facture, fk_email_template, status, origin, attempts, date_creation, date_attempt, fk_user)";
			$sql .= " VALUES (".((int) $entity).", ".((int) $invoiceId).", ".($templateId > 0 ? (int) $templateId : 'NULL');
			$sql .= ", ".self::STATUS_PROCESSING.", '".self::ORIGIN_AUTOMATIC."', 1, '".$this->db->idate($now)."'";
			$sql .= ", '".$this->db->idate($now)."', ".((int) $userId).")";
		} elseif ((int) $obj->status === self::STATUS_ERROR) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."lmdb_invoice_email SET status = ".self::STATUS_PROCESSING;
			$sql .= ", fk_email_template = ".($templateId > 0 ? (int) $templateId : 'NULL');
			$sql .= ", origin = '".self::ORIGIN_AUTOMATIC."', attempts = attempts + 1";
			$sql .= ", date_attempt = '".$this->db->idate($now)."', fk_user = ".((int) $userId).", last_error = NULL";
			$sql .= " WHERE rowid = ".((int) $obj->rowid);
		} else {
			$this->db->commit();
			return 0;
		}

		if (!$this->db->query($sql)) {
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();

		return 1;
	}

	/**
	 * Send one invoice.
	 *
	 * @param int       $invoiceId  Invoice id
	 * @param int       $templateId Email template id
	 * @param int       $entity     Entity id
	 * @param User      $user       Cron user
	 * @param Translate $langs      Current translations
	 * @return int 1 on success, -1 on failure
	 */
	private function sendInvoice($invoiceId, $templateId, $entity, $user, $langs)
	{
		global $conf;

		$invoice = new LmdbMailFacture($this->db);
		$result = $invoice->fetch($invoiceId);
		if ($result <= 0 || (int) $invoice->entity !== $entity) {
			return $this->recordFailure($entity, $invoiceId, $langs->trans('LmdbAutoInvoiceSendInvoiceFetchError', $invoiceId));
		}

		if ((int) $invoice->status !== Facture::STATUS_VALIDATED || !empty($invoice->paye) || empty($invoice->fk_fac_rec_source)) {
			return $this->recordReview($entity, $invoiceId, $langs->trans('LmdbAutoInvoiceSendInvoiceNoLongerEligible', $invoice->ref));
		}

		$result = $invoice->fetch_thirdparty();
		if ($result <= 0 || !is_object($invoice->thirdparty)) {
			return $this->recordFailure($entity, $invoiceId, $langs->trans('LmdbAutoInvoiceSendThirdpartyError', $invoice->ref));
		}

		if ($templateId <= 0 || !$this->isValidEmailTemplate($templateId, $entity)) {
			return $this->recordFailure($entity, $invoiceId, $langs->trans('LmdbAutoInvoiceSendTemplateError', $invoice->ref));
		}

		$outputlangs = new Translate('', $conf);
		$defaultLang = !empty($invoice->thirdparty->default_lang) ? $invoice->thirdparty->default_lang : $langs->defaultlang;
		$outputlangs->setDefaultLang($defaultLang);
		$outputlangs->loadLangs(array('main', 'bills', 'products', 'mails', 'lmdb@lmdb'));

		$formmail = new FormMail($this->db);
		$template = $formmail->getEMailTemplate($this->db, 'facture_send', $user, $outputlangs, $templateId, 1, '');
		if (!is_object($template)) {
			return $this->recordFailure($entity, $invoiceId, $langs->trans('LmdbAutoInvoiceSendTemplateError', $invoice->ref));
		}

		$substitutionArray = getCommonSubstitutionArray($outputlangs, 0, '', $invoice);
		complete_substitutions_array($substitutionArray, $outputlangs, $invoice, array('mode' => 'formemail'));

		$subject = make_substitutions(empty($template->topic) ? $outputlangs->transnoentitiesnoconv('InformationMessage') : $template->topic, $substitutionArray, $outputlangs, 1);
		$content = make_substitutions((string) $template->content, $substitutionArray, $outputlangs, 1);
		$templateTo = make_substitutions((string) $template->email_to, $substitutionArray, $outputlangs, 1);
		$templateCc = make_substitutions((string) $template->email_tocc, $substitutionArray, $outputlangs, 1);
		$templateBcc = make_substitutions((string) $template->email_tobcc, $substitutionArray, $outputlangs, 1);

		$recipientData = $this->getInvoiceRecipients($invoice);
		$to = $recipientData['emails'];
		if ($templateTo !== '') {
			$to[] = $templateTo;
		}
		$sendTo = implode(',', array_values(array_unique($to)));
		if ($sendTo === '') {
			return $this->recordFailure($entity, $invoiceId, $langs->trans('LmdbAutoInvoiceSendRecipientError', $invoice->ref));
		}

		$from = !empty($template->email_from) ? make_substitutions((string) $template->email_from, $substitutionArray, $outputlangs, 1) : getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
		if ($from === '') {
			return $this->recordFailure($entity, $invoiceId, $langs->trans('LmdbAutoInvoiceSendSenderError', $invoice->ref));
		}

		$files = array();
		$mimetypes = array();
		$filenames = array();
		if ((int) $template->joinfiles === 1) {
			$attachment = $this->getInvoiceAttachment($invoice, $outputlangs);
			if ($attachment === '') {
				return $this->recordFailure($entity, $invoiceId, $langs->trans('LmdbAutoInvoiceSendAttachmentError', $invoice->ref));
			}
			$files[] = $attachment;
			$mimetypes[] = dol_mimetype($attachment);
			$filenames[] = basename($attachment);
		}

		$errorsTo = getDolGlobalString('MAIN_MAIL_ERRORS_TO');
		$trackId = 'inv'.$invoice->id;
		$mail = new CMailFile($subject, $sendTo, $from, $content, $files, $mimetypes, $filenames, $templateCc, $templateBcc, 0, -1, $errorsTo, '', $trackId, '', 'standard', '');
		if (!empty($mail->error) || !empty($mail->errors)) {
			return $this->recordFailure($entity, $invoiceId, $langs->trans('LmdbAutoInvoiceSendMailError', $invoice->ref));
		}

		if (!$mail->sendfile()) {
			return $this->recordFailure($entity, $invoiceId, $langs->trans('LmdbAutoInvoiceSendMailError', $invoice->ref));
		}

		$invoice->email_msgid = $mail->msgid;
		$invoice->context['email_msgid'] = $mail->msgid;
		if (self::markInvoiceSentFromTrigger($this->db, $invoice, $user, self::ORIGIN_AUTOMATIC) < 0) {
			$this->errors[] = $langs->trans('LmdbAutoInvoiceSendLedgerError', $invoice->ref);
		}

		$invoice->socid = (int) $invoice->socid;
		$invoice->sendtoid = $recipientData['contact_ids'];
		$invoice->actiontypecode = 'AC_OTH_AUTO';
		$invoice->actionmsg = $content;
		$invoice->actionmsg2 = getDolGlobalString('MAIN_MAIL_REPLACE_EVENT_TITLE_BY_EMAIL_SUBJECT')
			? $subject
			: $outputlangs->transnoentities('MailSentByTo', CMailFile::getValidAddress($from, 4, 0, 1), CMailFile::getValidAddress($sendTo, 4, 0, 1));
		$invoice->trackid = $trackId;
		$invoice->fk_element = $invoice->id;
		$invoice->elementtype = $invoice->element;
		$invoice->attachedfiles = $files;
		$invoice->email_from = $from;
		$invoice->email_subject = $subject;
		$invoice->email_to = $sendTo;
		$invoice->email_tocc = $templateCc;
		$invoice->email_tobcc = $templateBcc;
		$invoice->context['lmdb_auto_invoice_send'] = 1;
		$invoice->context['email_from'] = $from;
		$invoice->context['email_subject'] = $subject;
		$invoice->context['email_to'] = $sendTo;
		$invoice->context['email_tocc'] = $templateCc;
		$invoice->context['email_tobcc'] = $templateBcc;

		if (version_compare(DOL_VERSION, '23.0.0', '>=')) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."facture SET email_sent_counter = email_sent_counter + 1";
			$sql .= " WHERE rowid = ".((int) $invoice->id)." AND entity = ".((int) $invoice->entity);
			if (!$this->db->query($sql)) {
				$this->errors[] = $langs->trans('LmdbAutoInvoiceSendCounterWarning', $invoice->ref);
			}
		}

		$result = $invoice->call_trigger('BILL_SENTBYMAIL', $user);
		if ($result < 0) {
			$this->errors[] = $langs->trans('LmdbAutoInvoiceSendTriggerWarning', $invoice->ref);
			dol_syslog(__METHOD__.': BILL_SENTBYMAIL failed for invoice id='.(int) $invoice->id, LOG_WARNING);
		}

		return 1;
	}

	/**
	 * Return invoice recipient addresses.
	 *
	 * @param Facture $invoice Invoice object with third party loaded
	 * @return array{emails:array<int,string>,contact_ids:array<int,int>}
	 */
	private function getInvoiceRecipients($invoice)
	{
		$recipients = array();
		$contactIds = array();
		$contacts = $invoice->liste_contact(-1, 'external', 0, 'BILLING');
		if (is_array($contacts)) {
			foreach ($contacts as $contact) {
				$email = isset($contact['email']) ? trim((string) $contact['email']) : '';
				if ($email !== '' && isValidEmail($email)) {
					$recipients[] = $email;
					if (!empty($contact['id'])) {
						$contactIds[] = (int) $contact['id'];
					}
				}
			}
		}

		if (empty($recipients) && !empty($invoice->thirdparty->email) && isValidEmail($invoice->thirdparty->email)) {
			$recipients[] = trim((string) $invoice->thirdparty->email);
		}

		return array(
			'emails' => array_values(array_unique($recipients)),
			'contact_ids' => array_values(array_unique($contactIds)),
		);
	}

	/**
	 * Validate an email template for the invoice entity.
	 *
	 * @param int $templateId Template id
	 * @param int $entity     Entity id
	 * @return bool
	 */
	private function isValidEmailTemplate($templateId, $entity)
	{
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."c_email_templates";
		$sql .= " WHERE rowid = ".((int) $templateId);
		$sql .= " AND entity = ".((int) $entity);
		$sql .= " AND type_template = 'facture_send' AND active = 1";
		$resql = $this->db->query($sql);

		return $resql && $this->db->num_rows($resql) > 0;
	}

	/**
	 * Return or generate the invoice main document.
	 *
	 * @param Facture   $invoice     Invoice object
	 * @param Translate $outputlangs Output translations
	 * @return string Absolute validated path, or empty string
	 */
	private function getInvoiceAttachment($invoice, $outputlangs)
	{
		$path = !empty($invoice->last_main_doc) ? DOL_DATA_ROOT.'/'.ltrim($invoice->last_main_doc, '/') : '';
		if ($path === '' || !is_file($path)) {
			$result = $invoice->generateDocument('', $outputlangs);
			if ($result <= 0 || $invoice->fetch((int) $invoice->id) <= 0) {
				return '';
			}
			$path = !empty($invoice->last_main_doc) ? DOL_DATA_ROOT.'/'.ltrim($invoice->last_main_doc, '/') : '';
		}

		if ($path === '' || !is_file($path)) {
			return '';
		}

		$uploadDir = getMultidirOutput($invoice, 'facture', 1);
		$realPath = realpath($path);
		$realUploadDir = is_string($uploadDir) ? realpath($uploadDir) : false;
		if ($realPath === false || $realUploadDir === false || strpos($realPath, rtrim($realUploadDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR) !== 0) {
			return '';
		}

		return $realPath;
	}

	/**
	 * Record a retryable send error.
	 *
	 * @param int    $entity    Entity id
	 * @param int    $invoiceId Invoice id
	 * @param string $message   Error message
	 * @return int Always -1
	 */
	private function recordFailure($entity, $invoiceId, $message)
	{
		$message = dol_trunc($message, 2000, 'right', 'UTF-8', 1);
		$sql = "UPDATE ".MAIN_DB_PREFIX."lmdb_invoice_email SET status = ".self::STATUS_ERROR;
		$sql .= ", last_error = '".$this->db->escape($message)."'";
		$sql .= " WHERE entity = ".((int) $entity)." AND fk_facture = ".((int) $invoiceId);
		if (!$this->db->query($sql)) {
			$this->errors[] = $this->db->lasterror();
		}
		$this->errors[] = $message;
		dol_syslog(__METHOD__.': invoice id='.(int) $invoiceId.' '.$message, LOG_WARNING);

		return -1;
	}

	/**
	 * Stop automatic retries when an invoice changed after it was claimed.
	 *
	 * @param int    $entity    Entity id
	 * @param int    $invoiceId Invoice id
	 * @param string $message   Review reason
	 * @return int Always 0
	 */
	private function recordReview($entity, $invoiceId, $message)
	{
		$message = dol_trunc($message, 2000, 'right', 'UTF-8', 1);
		$sql = "UPDATE ".MAIN_DB_PREFIX."lmdb_invoice_email SET status = ".self::STATUS_REVIEW;
		$sql .= ", last_error = '".$this->db->escape($message)."'";
		$sql .= " WHERE entity = ".((int) $entity)." AND fk_facture = ".((int) $invoiceId);
		if (!$this->db->query($sql)) {
			$this->errors[] = $this->db->lasterror();
		}
		dol_syslog(__METHOD__.': invoice id='.(int) $invoiceId.' requires review', LOG_WARNING);

		return 0;
	}

	/**
	 * Move interrupted processing rows to manual review instead of retrying them.
	 *
	 * @param int $entity Entity id
	 * @return int Number updated, or -1
	 */
	private function markStaleProcessingForReview($entity)
	{
		global $langs;

		$reviewMessage = $langs->trans('LmdbAutoInvoiceSendInterruptedReview');
		$sql = "UPDATE ".MAIN_DB_PREFIX."lmdb_invoice_email SET status = ".self::STATUS_REVIEW;
		$sql .= ", last_error = '".$this->db->escape($reviewMessage)."'";
		$sql .= " WHERE entity = ".((int) $entity);
		$sql .= " AND status = ".self::STATUS_PROCESSING;
		$sql .= " AND date_attempt < '".$this->db->idate(dol_now() - self::PROCESSING_STALE_DELAY)."'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			return -1;
		}

		return (int) $this->db->affected_rows($resql);
	}

	/**
	 * Count ledger rows in one status for the current entity.
	 *
	 * @param int $entity Entity id
	 * @param int $status Ledger status
	 * @return int Number of rows, or -1
	 */
	private function countLedgerRows($entity, $status)
	{
		$sql = "SELECT COUNT(rowid) AS nb FROM ".MAIN_DB_PREFIX."lmdb_invoice_email";
		$sql .= " WHERE entity = ".((int) $entity)." AND status = ".((int) $status);
		$resql = $this->db->query($sql);
		if (!$resql || !is_object($obj = $this->db->fetch_object($resql))) {
			return -1;
		}

		return (int) $obj->nb;
	}

	/**
	 * Initialize the no-backlog marker for the current entity.
	 *
	 * @param int $entity Entity id
	 * @return int Marker value, or -1
	 */
	private function initializeMinimumInvoiceId($entity)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		$sql = "SELECT MAX(rowid) AS maxid FROM ".MAIN_DB_PREFIX."facture WHERE entity = ".((int) $entity);
		$resql = $this->db->query($sql);
		if (!$resql || !is_object($obj = $this->db->fetch_object($resql))) {
			return -1;
		}
		$minimumInvoiceId = (int) $obj->maxid + 1;
		$result = dolibarr_set_const($this->db, 'LMDB_AUTO_INVOICE_SEND_MIN_ID', (string) $minimumInvoiceId, 'chaine', 0, '', (int) $entity);

		return $result > 0 ? $minimumInvoiceId : -1;
	}

	/**
	 * Read an integer constant for an explicit entity.
	 *
	 * This adds entity-aware logic that getDolGlobalInt() cannot provide for an object
	 * owned by another accessible entity.
	 *
	 * @param DoliDB $db     Database handler
	 * @param string $name   Constant name
	 * @param int    $entity Entity id
	 * @return int
	 */
	private static function getEntityConstantInt($db, $name, $entity)
	{
		$sql = "SELECT ".$db->decrypt('value')." AS value FROM ".MAIN_DB_PREFIX."const";
		$sql .= " WHERE ".$db->decrypt('name')." = '".$db->escape($name)."'";
		$sql .= " AND entity = ".((int) $entity);
		$sql .= $db->plimit(1);
		$resql = $db->query($sql);
		if (!$resql || !is_object($obj = $db->fetch_object($resql))) {
			return 0;
		}

		return (int) $obj->value;
	}
}
