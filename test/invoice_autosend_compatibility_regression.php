<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Execute real LMDB entry points with isolated core, database and mail boundaries.
// Usage: php test/invoice_autosend_compatibility_regression.php 24.0.0 disabled
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(function ($severity, $message, $file, $line) {
	if (error_reporting() & $severity) {
		throw new ErrorException($message, 0, $severity, $file, $line);
	}
	return false;
});
if ($argc !== 3 || !in_array($argv[2], array('enabled', 'disabled'), true)) {
	fwrite(STDERR, "Usage: php ".$argv[0]." VERSION enabled|disabled\n");
	exit(2);
}
define('DOL_VERSION', $argv[1]);
define('MAIN_DB_PREFIX', 'test_lmdb_');
define('DOL_URL_ROOT', '');
$expected = $argv[2] === 'enabled';
$temporary = __DIR__.'/.autosend-'.bin2hex(random_bytes(6));
register_shutdown_function(function () use ($temporary) {
	if (!is_dir($temporary)) { return; }
	$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($files as $file) {
		if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
	}
	rmdir($temporary);
});
foreach (array('main.inc.php', 'core/modules/DolibarrModules.class.php', 'core/modules/facture/doc/pdf_sponge.modules.php', 'core/class/extrafields.class.php', 'core/class/html.form.class.php', 'core/class/html.formmail.class.php', 'core/class/CMailFile.class.php', 'core/lib/files.lib.php', 'core/lib/functions2.lib.php', 'core/lib/admin.lib.php', 'compta/facture/class/facture.class.php', 'comm/mailing/class/mailing.class.php', 'societe/class/societe.class.php', 'user/class/user.class.php') as $file) {
	$path = $temporary.'/'.$file;
	if (!is_dir(dirname($path))) { mkdir(dirname($path), 0700, true); }
	file_put_contents($path, "<?php // Isolated boundary; classes defined by the test.\n");
}
define('DOL_DOCUMENT_ROOT', $temporary);

class TestDatabase {
	public $queries = array();
	public $reject = false;
	public function query($sql) {
		if ($this->reject) { throw new LogicException('Unexpected database access'); }
		$this->queries[] = $sql;
		return false;
	}
	public function lasterror() { return 'Simulated database failure'; }
	public function plimit($limit) { return ' LIMIT '.(int) $limit; }
	public function close() {}
	public function escape($value) { return addslashes($value); }
	public function idate($value) { return '2026-09-11 12:00:00'; }
}
class Translate {
	public function loadLangs($domains) {}
	public function load($domain) {}
	public function trans($key, ...$params) { return $key; }
}
class Facture { public $id = 1; public $entity = 2; }
class Mailing { const STATUS_VALIDATED = 1; const STATUS_SENTPARTIALY = 2; }
class CMailFile { public function __construct(...$args) { throw new LogicException('Unexpected mail creation'); } }
class Form {
	public function __construct($db) {}
	public function textwithpicto($label, $help) { return $label; }
	public function selectarray($name, ...$args) { return '<select name="'.$name.'"></select>'; }
}
class DolibarrModules {
	public function _remove($sql, $options) { check(!$this->cronjobs, 'remove must preserve stored jobs'); return 1; }
}
class ExtraFields {
	public static $existing = false;
	public static $calls = array();
	public function __construct($db) {}
	public function fetch_name_optionals_label($table, $force, $name) { return self::$existing ? array($name => $name) : array(); }
	public function updateExtraField(...$args) { self::$calls[] = array('update', $args); return 1; }
	public function addExtraField(...$args) { self::$calls[] = array('add', $args); return 1; }
}
class TestAccessDenied extends RuntimeException {}
function check($value, $message) { if (!$value) { throw new RuntimeException($message); } }
function isModEnabled($module) { return in_array($module, array('lmdb', 'invoice', 'cron', 'mailing'), true); }
function getDolGlobalInt($name, $default = 0) { return $name === 'MAILING_LIMIT_SENDBYWEB' ? 50 : $default; }
function getDolGlobalString($name, $default = '') { return $default; }
function dol_buildpath($path, $mode) { return $mode ? $path : dirname(__DIR__).substr($path, strlen('/lmdb')); }
function getCommonSubstitutionArray(...$args) { return array(); }
function complete_substitutions_array(...$args) {}
function make_substitutions($value, ...$args) { return $value; }
function dol_now() { return 1; }
function accessforbidden() { throw new TestAccessDenied(); }
function GETPOST($name, $type) { return $_POST[$name] ?? ''; }
function GETPOSTINT($name) { return (int) ($_POST[$name] ?? 0); }
function dolibarr_set_const(...$args) { throw new LogicException('Unexpected configuration write'); }
function llxHeader(...$args) {}
function llxFooter() {}
function load_fiche_titre(...$args) { return ''; }
function dol_get_fiche_head(...$args) { return ''; }
function dol_get_fiche_end() { return ''; }
function dol_escape_htmltag($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function img_picto(...$args) { return ''; }
function newToken() { return 'test-token'; }
function ajax_constantonoff($name) { return $name; }

$db = new TestDatabase();
$conf = (object) array('entity' => 2);
$langs = new Translate();
$user = (object) array('id' => 1, 'admin' => 1);
require __DIR__.'/../core/modules/modLmdb.class.php';
require __DIR__.'/../class/lmdbinvoiceautosend.class.php';
$module = new modLmdb($db);
check($module->version === '1.2.2', 'Descriptor version');
check(LmdbCompatibility::isRecurringInvoiceAutoSendSupported() === $expected, 'Version boundary');
check(isset($module->cronjobs[0]) === $expected, 'Invoice cron declaration');
check(isset($module->cronjobs[1]) && $module->cronjobs[1]['objectname'] === 'LmdbMailingAutoSend', 'Campaign cron preserved');
$jobs = $module->cronjobs;
check($module->remove() === 1 && $module->cronjobs === $jobs, 'Deactivation preserves jobs');
$features = LmdbCompatibility::getFeatures();
check($features['recurring_invoice_auto_send']['available'] === $expected, 'Compatibility display');
check($features['scheduled_native_mailing_send']['available'], 'Campaign compatibility unchanged');

// Replay fresh installation, upgrade and reactivation without a real database.
$install = new ReflectionMethod(modLmdb::class, 'installInvoiceAutoSendExtraFields');
$install->setAccessible(true);
foreach (array(false, true, true) as $existing) {
	ExtraFields::$existing = $existing;
	ExtraFields::$calls = array();
	check($install->invoke($module) === 1, 'Extrafield installation');
	check(count(ExtraFields::$calls) === (($expected || $existing) ? 4 : 0), 'No new invoice fields on v24+');
	foreach (ExtraFields::$calls as $call) {
		check($call[0] === ($existing ? 'update' : 'add'), 'Existing fields updated without deletion');
		check($call[1][15] === '2', 'Extrafield owner entity');
		check($call[1][17] === 'isModEnabled("lmdb") && '.($expected ? '1' : '0'), 'Extrafield availability');
	}
}
$db->queries = array();
LmdbInvoiceAutoSend::normalizeCronTranslationKeys($db, 2);
$query = $db->queries[0];
check(strpos($query, '&& '.($expected ? '1' : '0')."'") !== false, 'Existing cron eligibility');
check(strpos($query, 'WHERE entity = 2') !== false, 'Cron owner entity');
check(!preg_match('/\b(status|frequency|unitfrequency|datenextrun|datelastrun)\s*=/i', $query), 'Existing cron settings preserved');

$db->queries = array();
$db->reject = !$expected;
$job = new LmdbInvoiceAutoSend($db);
if ($expected) {
	check($job->run() === 1 && count($db->queries) === 1, 'Old versions reach the existing processing path');
} else {
	check($job->run() === 0 && $job->output !== '', 'Old job safely skipped with an explanation');
	check(LmdbInvoiceAutoSend::markInvoiceSentFromTrigger($db, new Facture(), $user) === 0, 'No legacy ledger write on native send');
	check(!$db->queries, 'No database access or mail on v24+');
}

// Execute the real setup controller and rendering with native helpers stubbed.
$db->reject = false;
$_SERVER['CONTEXT_DOCUMENT_ROOT'] = $temporary;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = array();
ob_start();
include __DIR__.'/../admin/setup.php';
$html = ob_get_clean();
check((strpos($html, 'value="saveautosend"') !== false) === $expected, 'Invoice settings visibility');
check(strpos($html, 'value="savescheduledmailing"') !== false, 'Campaign settings preserved');
if (!$expected) {
	$db->reject = true;
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST = array('action' => 'saveautosend', 'lmdb_auto_invoice_send_max_per_run' => 100, 'token' => 'test-token');
	$refused = false;
	try { include __DIR__.'/../admin/setup.php'; } catch (TestAccessDenied $error) { $refused = true; }
	check($refused, 'Direct POST rejected before any configuration write');
}
echo 'OK: Dolibarr '.DOL_VERSION.' simulated, invoice delivery '.$argv[2]."; campaigns preserved.\n";
