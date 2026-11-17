<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
// In-memory SQL test. No instance, email or production configuration is used.
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
$temporary = sys_get_temp_dir().'/lmdb-mailing-list-'.bin2hex(random_bytes(6));
foreach (array('comm/mailing/class/mailing.class.php', 'core/class/html.form.class.php') as $file) {
	mkdir(dirname($temporary.'/'.$file), 0700, true);
	file_put_contents($temporary.'/'.$file, '<?php');
}
define('DOL_DOCUMENT_ROOT', $temporary);
define('MAIN_DB_PREFIX', 'test_');
function check($value, $message) { if (!$value) throw new RuntimeException($message); }
$enabled = true;
function isModEnabled($module) { global $enabled; return $enabled; }
function dol_print_date($date, $format) { check($format === 'dayhour', 'Native date/time format'); return gmdate('Y-m-d H:i', $date); }
function dol_buildpath($path, $mode) { return $path; }
function dol_syslog($message, $level) {}
class Mailing {}
class ListTestLanguages {
	public function load($catalogue) {}
	public function trans($key) { return $key; }
}
class ListTestUser {
	public $allowed = true;
	public function hasRight($module, $right) { check($module === 'mailing' && $right === 'lire', 'Direct native reading permission'); return $this->allowed; }
}
class ListTestDatabase {
	public $connection;
	public $queries = 0;
	public $fail = false;
	public function __construct() { $this->connection = new SQLite3(':memory:'); }
	public function query($sql) { $this->queries++; return $this->fail ? false : $this->connection->query($sql); }
	public function fetch_object($result) { $row = $result->fetchArray(SQLITE3_ASSOC); return $row === false ? false : (object) $row; }
	public function free($result) { $result->finalize(); }
	public function jdate($date) { return strtotime($date.' UTC'); }
}
require __DIR__.'/../class/actions_lmdb.class.php';
try {
	$db = new ListTestDatabase();
	$db->connection->exec('CREATE TABLE test_mailing (rowid INTEGER, entity INTEGER, messtype TEXT, fk_project INTEGER); CREATE TABLE test_lmdb_mailing_schedule (entity INTEGER, fk_mailing INTEGER, scheduled_send_at TEXT)');
	$db->connection->exec("INSERT INTO test_mailing VALUES (1,1,'email',7),(2,1,'email',7),(3,2,'email',7),(4,1,'sms',7),(5,1,'email',8),(6,1,'email',7)");
	$db->connection->exec("INSERT INTO test_lmdb_mailing_schedule VALUES (1,1,'2026-11-20 12:30:00'),(1,2,NULL),(2,3,'2026-11-21 08:00:00'),(1,4,'2026-11-21 08:00:00'),(1,5,'2026-11-21 08:00:00'),(2,1,'2099-01-01 00:00:00')");
	$conf = (object) array('entity' => 1);
	$langs = new ListTestLanguages(); $user = new ListTestUser();
	$hooks = new ActionsLmdb($db); $object = new Mailing(); $action = ''; $manager = null;
	$params = array('context' => 'mailinglist');
	$hooks->doActions($params, $object, $action, $manager);
	check(isset($arrayfields[ActionsLmdb::SCHEDULED_SEND_FIELD]), 'Column registered before native preference handling');
	$params['arrayfields'] = $arrayfields; $params['totalarray'] = array('nbfield' => 6);
	$sql = 'SELECT m.rowid FROM test_mailing m WHERE m.entity = 1 AND m.fk_project = 7 ORDER BY m.rowid LIMIT 2';
	function payload($hooks, $params, &$object, &$action, $manager) {
		$hooks->printFieldListFooter($params, $object, $action, $manager);
		check(preg_match('/id="lmdb-mailing-list-dates">(.*?)<\/script>/', $hooks->resprints, $matches), 'JSON payload rendered');
		return json_decode($matches[1], true);
	}
	check($hooks->printFieldListTitle($params, $object, $action, $manager) === 0, 'Native list continues');
	$data = payload($hooks, $params, $object, $action, $manager);
	check($data['dates'] === array(1 => '2026-11-20 12:30'), 'Page, project, null date and entity scope preserved');
	check($data['index'] === 6 && $db->queries === 1, 'One grouped query per page');
	$hooks->printFieldListFooter($params, $object, $action, $manager);
	check($hooks->resprints === '', 'No stale output after repeated footer call');
	$sql = 'SELECT rowid FROM test_mailing WHERE entity = 1 AND rowid IN (4,6)';
	$hooks->printFieldListTitle($params, $object, $action, $manager);
	check(payload($hooks, $params, $object, $action, $manager)['dates'] === array(), 'SMS and missing dates remain empty');
	$conf->entity = 2; $sql = 'SELECT rowid FROM test_mailing WHERE entity = 2';
	$hooks->printFieldListTitle($params, $object, $action, $manager);
	check(payload($hooks, $params, $object, $action, $manager)['dates'] === array(3 => '2026-11-21 08:00'), 'Second entity');
	foreach (array('no right', 'module disabled', 'hidden column', 'other context') as $case) {
		$before = $db->queries; $p = $params;
		$user->allowed = $case !== 'no right'; $enabled = $case !== 'module disabled';
		if ($case === 'hidden column') $p['arrayfields'][ActionsLmdb::SCHEDULED_SEND_FIELD]['checked'] = 0;
		if ($case === 'other context') $p['context'] = 'thirdpartylist';
		$hooks->printFieldListTitle($p, $object, $action, $manager);
		$hooks->printFieldListFooter($p, $object, $action, $manager);
		check($db->queries === $before && $hooks->resprints === '', $case.': no data read or leaked');
	}
	$user->allowed = true; $enabled = true; $db->fail = true;
	check($hooks->printFieldListTitle($params, $object, $action, $manager) === -1, 'SQL failure reported');
	$hooks->printFieldListFooter($params, $object, $action, $manager);
	check($hooks->resprints === '', 'SQL failure never looks like absent scheduling');
	echo "OK: grouped SQL, native page scope, permissions, two entities, null/absent dates, SMS, preferences, repeated hooks, SQL error.\n";
} finally {
	foreach (array('comm/mailing/class/mailing.class.php', 'core/class/html.form.class.php') as $file) unlink($temporary.'/'.$file);
	foreach (array('comm/mailing/class', 'comm/mailing', 'comm', 'core/class', 'core') as $dir) rmdir($temporary.'/'.$dir);
	rmdir($temporary);
}
