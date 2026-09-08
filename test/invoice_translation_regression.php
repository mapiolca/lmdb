<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Real Translate and language files, real LMDB helper/hook/model/substitutions.
// Business persistence and PDF rendering are isolated test boundaries.
// Usage: php test/invoice_translation_regression.php /path/to/dolibarr/htdocs
error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('UTC');
set_error_handler(function ($severity, $message, $file, $line) {
	if (error_reporting() & $severity) {
		throw new ErrorException($message, 0, $severity, $file, $line);
	}
	return false;
});
$core = $argv[1] ?? '';
if (!is_file($core.'/core/class/translate.class.php')) {
	fwrite(STDERR, "Provide the native Dolibarr htdocs directory.\n");
	exit(2);
}
$temporary = sys_get_temp_dir().'/lmdb-regression-'.bin2hex(random_bytes(6));
foreach (array('compta/facture/class/facture.class.php', 'compta/facture/class/facture-rec.class.php', 'comm/mailing/class/mailing.class.php', 'core/class/html.form.class.php', 'core/modules/facture/doc/pdf_sponge.modules.php') as $file) {
	$path = $temporary.'/'.$file;
	if (!is_dir(dirname($path))) { mkdir(dirname($path), 0700, true); }
	file_put_contents($path, "<?php // Test boundary; classes defined by the harness.\n");
}
define('DOL_DOCUMENT_ROOT', $temporary);
$conf = (object) array('entity' => 29, 'file' => (object) array('dol_document_root' => array($core, dirname(__DIR__, 2))));
$db = null;
require $core.'/core/class/translate.class.php';
require __DIR__.'/../lib/lmdb_pdf.lib.php';
require __DIR__.'/../class/lmdbinvoicecustomerref.class.php';
require __DIR__.'/../class/actions_lmdb.class.php';
require __DIR__.'/../core/modules/facture/doc/pdf_lmdbsponge.modules.php';
require __DIR__.'/../core/substitutions/functions_lmdb.lib.php';

function check($condition, $message) {
	if (!$condition) { throw new RuntimeException($message); }
}
function getDolGlobalString($key, $default = '') { return $default; }
function getDolGlobalInt($key, $default = 0) { return $default; }
function isModEnabled($module) { return false; }
function dol_osencode($value) { return $value; }
function dol_syslog($message, $level = 0) {}
function dol_buildpath($path, $mode) { return dirname(__DIR__).substr($path, strlen('/lmdb')); }
function dol_now() { return strtotime('2026-09-15'); }
function dol_strlen($text) { return mb_strlen($text); }
function dol_time_plus_duree($date, $quantity, $unit) { return strtotime(($quantity >= 0 ? '+' : '').$quantity.($unit === 'm' ? ' months' : ' years'), $date); }
function dol_print_date($date, $format, $timezone, $langs) { return gmdate(str_replace(array('%m', '%Y'), array('m', 'Y'), $format), $date); }
function getCommonSubstitutionArray($langs, $mode, $extra, $invoice) { return array('__INVOICE_REF__' => $invoice->ref); }
function make_substitutions($template, $substitutions, $langs) { return strtr($template, $substitutions); }
function complete_substitutions_array(&$substitutions, $langs, $invoice) { lmdb_completesubstitutionarray($substitutions, $langs, $invoice); }

class Facture {
	public $id = 1;
	public $entity = 29;
	public $element = 'facture';
	public $ref = 'FC-TEST';
	public $date;
	public $fk_fac_rec_source = 0;
	public $fac_rec = 0;
	public $array_options = array();
	public $context = array();
	public $error = '';
	public $ref_client = '';
	public $saves = 0;
	public function __construct() { $this->date = strtotime('2026-09-15'); }
	public function set_ref_client($value, $notrigger) { $this->ref_client = $value; $this->saves++; return 1; }
}
class pdf_sponge {
	public $name;
	public $description;
	public $rendered = array();
	public function __construct($db) {}
	public function write_file($object, $outputlangs, $source = '', $details = 0, $description = 0, $reference = 0, $more = null) {
		$this->rendered = array($outputlangs->transnoentitiesnoconv('Invoice'), $outputlangs->transnoentitiesnoconv('Month09'), $details, $description, $reference);
		return 1;
	}
}
class MissingMonthTranslate extends Translate {
	public function transnoentitiesnoconv($key, $p1 = '', $p2 = '', $p3 = '', $p4 = '', $p5 = '') {
		return preg_match('/^(Lmdb)?Month[0-9]{2}$/', $key) ? $key : parent::transnoentitiesnoconv($key, $p1, $p2, $p3, $p4, $p5);
	}
}

try {
	foreach (array('fr_FR' => array('Facture', 'Septembre'), 'en_US' => array('Invoice', 'September')) as $language => $expected) {
		foreach (array(false, true) as $moduleAlreadyLoaded) {
			$langs = new Translate('', $conf);
			$langs->setDefaultLang($language);
			$langs->loadLangs(array('main', 'bills'));
			if ($moduleAlreadyLoaded) { $langs->load('lmdb@lmdb'); }
			$langs->tab_translate = array();
			$langs->tab_translate['RefCustomer'] = 'Custom customer reference';
			$langs->tab_translate['Invoice'] = 'Invoice';
			$langs->charset_output = 'ISO-8859-1';
			$invoice = new Facture();
			$action = '';
			$hook = new ActionsLmdb(null);
			$hook->beforePDFCreation(array('outputlangs' => $langs), $invoice, $action, null);
			check($langs->transnoentitiesnoconv('Invoice') === $expected[0], 'Native invoice title was not restored');
			check($langs->transnoentitiesnoconv('Month09') === $expected[1], 'Native month was not restored');
			check($langs->tab_translate['RefCustomer'] === 'Custom customer reference', 'Runtime translation override lost');
			check($langs->charset_output === 'ISO-8859-1' && $langs->getDefaultLang() === $language, 'Output settings changed');
			// The LMDB model must also work without a hook being registered.
			$langs->tab_translate = array();
			$model = new pdf_lmdbsponge(null);
			check($model->write_file($invoice, $langs, '', 1, 1, 1) === 1, 'PDF model failed');
			check($model->rendered === array($expected[0], $expected[1], 1, 1, 1), 'Model language or native render options changed');
			$langs->tab_translate = array();
			check(LmdbInvoiceCustomerRef::resolve('BASE - __INVOICE_MONTH_TEXT__ __INVOICE_YEAR__', $invoice, $langs) === 'BASE - '.$expected[1].' 2026', 'Recurring reference contains an untranslated month');
			$invoice->fk_fac_rec_source = 1;
			$invoice->array_options[LmdbInvoiceCustomerRef::EXTRAFIELD_KEY] = '__INVOICE_MONTH_TEXT__';
			$customerRef = new LmdbInvoiceCustomerRef(null);
			check($customerRef->apply($invoice, $langs) === 1 && $invoice->ref_client === $expected[1], 'Resolved reference was not persisted');
		}
	}
	$langs = new Translate('', $conf);
	$langs->setDefaultLang('fr_FR');
	$invoice = new Facture();
	$invoice->date = strtotime('2026-12-15');
	check(LmdbInvoiceCustomerRef::resolve('__INVOICE_NEXT_MONTH_TEXT__ __INVOICE_YEAR__', $invoice, $langs) === 'Janvier 2027', 'December rollover failed');
	$invoice->date = strtotime('2026-01-15');
	check(LmdbInvoiceCustomerRef::resolve('__INVOICE_PREVIOUS_MONTH_TEXT__ __INVOICE_YEAR__', $invoice, $langs) === 'Décembre 2025', 'January rollover failed');
	$langs->load('lmdb@lmdb');
	$langs->setDefaultLang('en_US');
	$langs->tab_translate = array();
	check(LmdbInvoiceCustomerRef::resolve('__INVOICE_PREVIOUS_MONTH_TEXT__ __INVOICE_YEAR__', $invoice, $langs) === 'December 2025', 'Entity language switch reused French translations');
	$missing = new MissingMonthTranslate('', $conf);
	$missing->setDefaultLang('fr_FR');
	$invoice->fk_fac_rec_source = 1;
	$invoice->array_options[LmdbInvoiceCustomerRef::EXTRAFIELD_KEY] = '__INVOICE_MONTH_TEXT__';
	$customerRef = new LmdbInvoiceCustomerRef(null);
	check($customerRef->apply($invoice, $missing) === -1 && $invoice->saves === 0, 'A raw month key was persisted');
	check($customerRef->error !== 'LmdbInvoiceTranslationUnavailable', 'Missing translation error is not translated');
	$substitutions = array('__INVOICE_REF__' => $invoice->ref);
	lmdb_completesubstitutionarray($substitutions, $missing, $invoice);
	check($substitutions === array('__INVOICE_REF__' => $invoice->ref), 'Missing month broke native substitutions or injected raw keys');
	echo "PASS: native FR/EN catalogs, cleared caches, PDF hook/model, overrides, recurring reference persistence, year rollover, entity language switch, unresolved-month refusal.\n";
} finally {
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($iterator as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
	rmdir($temporary);
}
