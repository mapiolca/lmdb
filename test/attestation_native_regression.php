<?php
/* Copyright (C) 2026 Pierre Ardoin. SPDX-License-Identifier: GPL-3.0-or-later */
// Real CommonObject, field validator and module methods; SQLite SQL adapter and
// actor/entity helpers are simulated. This is not a MySQL/Multicompany instance.
$core = $argv[1] ?? '';
if (!is_file($core.'/core/class/commonobject.class.php')) { fwrite(STDERR,"Usage: php test/attestation_native_regression.php /path/to/htdocs\n"); exit(2); }
define('DOL_DOCUMENT_ROOT',$core);
define('DOL_VERSION','24.0.1');
define('MAIN_DB_PREFIX','fixture_');
define('DOL_DATA_ROOT',__DIR__.'/.runtime/documents');
define('DOL_URL_ROOT','');
define('DOL_MAIN_URL_ROOT','https://example.invalid');
$dolibarr_main_url_root=DOL_MAIN_URL_ROOT;
define('TCPDF_PATH',$core.'/includes/tecnickcom/tcpdf/');
define('TCPDI_PATH',$core.'/includes/tcpdi/');
require_once $core.'/core/lib/functions.lib.php';
require_once $core.'/core/lib/security.lib.php';
require_once $core.'/core/class/translate.class.php';
require_once $core.'/user/class/user.class.php';
require_once dirname(__DIR__).'/class/lmdbattestation.class.php';
require_once dirname(__DIR__).'/class/lmdbattestationmail.class.php';

class AttestationTestDb
{
	public $type='sqlite';
	public $prefix='fixture_';
	public $pdo;
	public $lastError='';
	public $level=0;
	public $failCommit=false;
	public $failBegin=false;
	public $failSql='';
	public function __construct() { $this->pdo=new PDO('sqlite::memory:'); $this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); }
	public function prefix() { return $this->prefix; }
	public function query($sql,$usesavepoint=0,$type='auto') {
		if ($this->failSql!=='' && strpos($sql,$this->failSql)!==false) { $this->lastError='Injected database failure'; return false; }
		$sql=str_replace(' FOR UPDATE','',$sql);
		try { return $this->pdo->query($sql); } catch (Exception $e) { $this->lastError=$e->getMessage(); return false; }
	}
	public function fetch_object($res) { return $res->fetchObject(); }
	public function num_rows($res) { $rows=$res->fetchAll(PDO::FETCH_OBJ); $res->execute(); return count($rows); }
	public function free($res) { return true; }
	public function escape($str) { return str_replace("'","''",(string)$str); }
	public function sanitize($str,$a=0,$b=0,$c=0) { return $str; }
	public function idate($date) { return $date ? date('Y-m-d H:i:s',(int)$date) : null; }
	public function jdate($date) { return $date ? strtotime($date) : null; }
	public function lasterror() { return $this->lastError; }
	public function last_insert_id($table='') { return (int)$this->pdo->lastInsertId(); }
	public function begin() { if ($this->failBegin) { return 0; } if (!$this->level++) { $this->pdo->beginTransaction(); } return 1; }
	public function commit() { if ($this->failCommit) { return 0; } if (--$this->level===0) { $this->pdo->commit(); } return 1; }
	public function rollback() { if ($this->level>0 && --$this->level===0) { $this->pdo->rollBack(); } return 1; }
	public function affected_rows($res) { return $res->rowCount(); }
	public function plimit($limit,$offset=0) { return ' LIMIT '.(int)$limit.' OFFSET '.(int)$offset; }
}
class AttestationTestUser extends User
{
	public $allow=true;
	public function hasRight($module,$level1,$level2=null,$default=0) { return $this->allow; }
}
// Native helpers use these module/translation globals. No real configuration is touched.
$conf=(object)array('entity'=>1,'global'=>new stdClass(),'modules'=>array(),'modules_parts'=>array('hooks'=>array(),'substitutions'=>array(),'models'=>array()),'file'=>(object)array('dol_document_root'=>array($core,dirname(__DIR__,2)), 'main_authentication'=>array()),'currency'=>'EUR');
foreach (array('lmdb','societe') as $module) { $conf->{$module}=(object)array('enabled'=>1); $conf->modules[$module]=1; }
$conf->lmdb->multidir_output=array(1=>DOL_DATA_ROOT.'/lmdb',2=>DOL_DATA_ROOT.'/entity2/lmdb');
$langs=new Translate('',$conf); $langs->setDefaultLang('fr_FR'); $langs->loadLangs(array('main','errors','other'));
$db=new AttestationTestDb();
$user=new AttestationTestUser($db); $user->id=1; $user->admin=1; $user->socid=0;
$hookmanager=null;
// Query getEntity() without Multicompany, so expected entity is 1.
foreach (array('lmdb_attestation','lmdb_attestation_version','lmdb_attestation_email') as $table) {
	$sql=file_get_contents(dirname(__DIR__).'/sql/llx_'.$table.'.sql');
	$sql=preg_replace('/--[^\n]*\n/','',$sql);
	$sql=str_replace(array('llx_','integer AUTO_INCREMENT PRIMARY KEY',') ENGINE=innodb;'),array(MAIN_DB_PREFIX,'integer PRIMARY KEY AUTOINCREMENT',');'),$sql);
	$sql=preg_replace('/tms timestamp[^,]+,/', 'tms timestamp,',$sql);
	if (!$db->query($sql)) { throw new RuntimeException($db->lasterror()); }
}
foreach (glob(dirname(__DIR__).'/sql/llx_lmdb_attestation*.key.sql') as $keyfile) {
	$sql=str_replace('llx_', MAIN_DB_PREFIX, file_get_contents($keyfile));
	$sql=preg_replace('/ALTER TABLE (\w+) ADD (UNIQUE )?INDEX (\w+) \(([^;]+)\);/', 'CREATE $2INDEX $3 ON $1 ($4);', $sql);
	foreach (explode(';',$sql) as $statement) { if (trim($statement)!=='' && !$db->query($statement)) { throw new RuntimeException($db->lasterror()); } }
}
$db->query('CREATE TABLE fixture_societe (rowid integer PRIMARY KEY, entity integer, nom text)');
$db->query('CREATE TABLE fixture_societe_commerciaux (fk_soc integer,fk_user integer)');
$db->query("INSERT INTO fixture_societe VALUES (1,1,'Client de test'),(2,2,'Autre entité')");
$db->query("INSERT INTO fixture_lmdb_attestation VALUES (1,1,'ATLF-TEST-0001',1,1,'2026-10-24',NULL,NULL,'','',1,'{\"schema\":\"1\",\"version\":\"24.0.2\"}',NULL,NULL,'2026-10-24',NULL,NULL,1,NULL,NULL,NULL,NULL,NULL)");
function checkAttestation($ok,$message) { if (!$ok) { throw new RuntimeException($message); } echo 'PASS '.$message."\n"; }
$object=new LmdbAttestation($db);
checkAttestation($object->fetch(1)>0,'Native fetch inside business/entity scope');
checkAttestation($object->socid===$object->fk_soc,'Native thirdparty alias is synchronized after fetch');
checkAttestation($object->legalSnapshot()['version']==='24.0.2','Frozen snapshot loaded');
$user->allow=false;
checkAttestation($object->fetch(1)<0,'Administrator without hasRight cannot fetch');
checkAttestation($object->delete($user)<0,'Administrator without hasRight cannot delete');
checkAttestation($object->validate($user)<0,'Administrator without hasRight cannot validate');
checkAttestation($object->generateDocument('loifinance',$langs)<0,'Administrator without hasRight cannot generate');
$user->allow=true;
checkAttestation($object->fetch(1)>0,'Allowed user can fetch again');
$object->status=0;
checkAttestation($object->delete($user)<0,'Forged draft status cannot delete a validated record');
checkAttestation($db->query('SELECT rowid FROM fixture_lmdb_attestation WHERE rowid=1')->fetchColumn()==1,'Refused deletion preserves persisted record');
$object->snapshot_data='{"schema":"1","version":123}';
try { $object->legalSnapshot(); $invalid=false; } catch (RuntimeException $e) { $invalid=true; }
checkAttestation($invalid,'Malformed legal snapshot refused');
$object->fetch(1);
$object->entity=2;
$conf->lmdb->multidir_output[2]='';
checkAttestation($object->documentDirectory(false)===false,'Missing owner directory does not fall back to current entity');
$conf->lmdb->multidir_output[2]=DOL_DATA_ROOT.'/entity2/lmdb';
$object->entity=1;
$object->ref='../escape';
checkAttestation($object->documentDirectory(true)===false,'Traversal in reference refused');
$object->fetch(1);
$submission=str_repeat('a',64);
checkAttestation(LmdbAttestationMail::reserve($db,$object,$user,$submission)>0,'First mail submission reserved before transport');
checkAttestation(LmdbAttestationMail::reserve($db,$object,$user,str_repeat('b',64))<0,'Concurrent/pending mail cannot be sent twice');
$db->query("UPDATE fixture_lmdb_attestation_email SET status='failed'");
checkAttestation(LmdbAttestationMail::reserve($db,$object,$user,$submission)<0,'Previously consumed submission cannot be replayed');
checkAttestation($object->setValueFrom('status',1)<0,'Native raw Ajax mutation cannot bypass validation');
$db->failBegin=true;
checkAttestation(LmdbAttestationMail::reserve($db,$object,$user,str_repeat('c',64))<0,'Failed transaction start blocks mail transport');
$db->failBegin=false;
$db->failCommit=true;
checkAttestation(LmdbAttestationMail::reserve($db,$object,$user,str_repeat('d',64))<0,'Failed commit blocks mail transport');
$db->failCommit=false;
checkAttestation($db->query("SELECT COUNT(*) FROM fixture_lmdb_attestation_email WHERE status='pending'")->fetchColumn()==0,'Failed reservation rolled back');
require_once dirname(__DIR__).'/core/modules/modLmdb.class.php';
$descriptor=new modLmdb($db);
foreach ($descriptor->menu as $menu) {
	checkAttestation(dol_eval($menu['perms'],1,0,'1')==1 && $menu['user']===0,'Native menu evaluator permits authorized internal user');
}
$user->allow=false;
foreach ($descriptor->menu as $menu) { checkAttestation(dol_eval($menu['perms'],1,0,'1')==0,'Native menu evaluator refuses missing rights'); }
$user->allow=true;
require_once $core.'/core/class/hookmanager.class.php';
require_once dirname(__DIR__).'/class/actions_lmdb.class.php';
$hookmanager=new HookManager($db);
$actions=new ActionsLmdb($db);
$hookmanager->hooks=array('notification'=>array('lmdb'=>$actions));
$hookmanager->hooksSorted=array('notification'=>array('lmdb'=>$actions));
$hookmanager->contextarray=array('notification');
$action='';
checkAttestation($hookmanager->executeHooks('notifsupported',array(),$object,$action)===0 && count($hookmanager->resArray['arrayofnotifsupported'])===3,'Native hook manager receives all CRUD notifications');
checkAttestation($actions->resprints===null && $actions->results===array(),'Native hook manager resets results after call');
checkAttestation($hookmanager->executeHooks('emailElementlist',array(),$object,$action)===0 && isset($hookmanager->resArray['lmdbattestation_send']) && !isset($hookmanager->resArray['arrayofnotifsupported']),'Successive native hook calls do not leak results');
$db->query("INSERT INTO fixture_lmdb_attestation_version VALUES (1,1,'USED','2026-10-24','Covered scope','',1),(2,1,'UNUSED','2026-10-24','Covered scope','',0),(3,2,'OTHER-ENTITY','2026-10-24','Covered scope','',0)");
$langs->load('lmdb@lmdb');
$emptycatalogue = new LmdbAttestationVersion($db);
checkAttestation($emptycatalogue->save($user)<0 && count($emptycatalogue->errors)===3, 'All missing catalogue fields are reported together');
foreach (array('version','date_release','covered_scope') as $requiredfield) {
	checkAttestation(strpos($emptycatalogue->getFieldError($requiredfield), $langs->trans($emptycatalogue->fields[$requiredfield]['label']).' : ')===0, 'Required notification identifies '.$requiredfield);
}
$catalogue = new LmdbAttestationVersion($db);
checkAttestation($catalogue->fetch(1)>0 && $catalogue->delete($user)<0 && $catalogue->error==='LmdbAttestedVersionInUse', 'Native child detection blocks deleting a used catalogue version');
$db->query('UPDATE fixture_lmdb_attestation SET status=2 WHERE rowid=1');
checkAttestation($catalogue->delete($user)<0, 'Cancelled attestation still blocks catalogue deletion');
$db->query('UPDATE fixture_lmdb_attestation SET status=0 WHERE rowid=1');
checkAttestation($catalogue->delete($user)<0, 'Draft attestation still blocks catalogue deletion');
$db->query('UPDATE fixture_lmdb_attestation SET status=1 WHERE rowid=1');
checkAttestation($catalogue->setActive($user,0)>0 && $catalogue->setActive($user,1)>0, 'Catalogue activation changes only the state');
checkAttestation($catalogue->covered_scope==='Covered scope', 'Catalogue activation preserves the scope');
$catalogue->fetch(2); $user->allow=false;
checkAttestation($catalogue->delete($user)<0 && $catalogue->setActive($user,1)<0, 'Administrator without configure right cannot delete or activate a version');
$user->allow=true;
$catalogue->id=3; $catalogue->entity=1;
checkAttestation($catalogue->delete($user)<0, 'Forged entity cannot delete another entity catalogue entry');
$catalogue->fetch(2);
dol_mkdir(DOL_DATA_ROOT.'/lmdb');
$cataloguesentinel = DOL_DATA_ROOT.'/lmdb/catalogue-delete-sentinel.txt';
file_put_contents($cataloguesentinel, 'Keep unrelated module documents');
checkAttestation($catalogue->delete($user)>0 && $catalogue->fetch(2)<=0 && is_file($cataloguesentinel), 'Unused catalogue deletion preserves unrelated module documents');
unlink($cataloguesentinel);
require_once dirname(__DIR__).'/core/modules/lmdb/mod_lmdbattestation_standard.php';
require_once dirname(__DIR__).'/core/modules/lmdb/mod_lmdbattestation_advanced.php';
$conf->global->LMDB_ATTESTATION_MASK = 'REC{yy}{mm}-{0000}';
$standard = new mod_lmdbattestation_standard();
$numbering = new mod_lmdbattestation_advanced();
$db->failSql = 'FOR UPDATE';
checkAttestation($standard->getExample() === 'ATLF-'.date('Y', dol_now()).'-0002', 'Standard remains fixed when the Advanced mask is customized');
$preview = $numbering->getExample();
checkAttestation($preview === 'REC'.date('ym', dol_now()).'-0001', 'Custom mask preview uses native date and counter without locking');
checkAttestation($numbering->getExample() === $preview, 'Repeated preview does not consume a reference');
$db->failSql = '';
$conf->global->LMDB_ATTESTATION_MASK = 'REC-{cccc}-{0000}';
$numbering = new mod_lmdbattestation_advanced();
$sample = new LmdbAttestation($db); $sample->specimen = 1; $sample->date_attestation = dol_now();
$thirdparty = new Societe($db); $thirdparty->code_client = 'CLIENT';
checkAttestation($numbering->getNextValue($thirdparty, $sample) === 'REC-CLIE-0001', 'Custom mask receives the actual thirdparty code');
$db->query("INSERT INTO fixture_lmdb_attestation (entity,ref,fk_soc,fk_version,date_attestation,date_creation,fk_user_creat) VALUES (2,'REC-CLIE-0099',2,1,'2026-10-24','2026-10-24',1)");
checkAttestation($numbering->getNextValue($thirdparty, $sample) === 'REC-CLIE-0001', 'Custom mask counter excludes inaccessible entities');
$conf->global->LMDB_ATTESTATION_ADDON = 'mod_lmdbattestation_advanced';
checkAttestation($sample->getNextNumRef($thirdparty) === 'REC-CLIE-0001', 'Object uses the selected Advanced numbering model');
$conf->global->LMDB_ATTESTATION_ADDON = 'mod_lmdbattestation_standard';
checkAttestation($sample->getNextNumRef($thirdparty) === 'ATLF-'.date('Y', dol_now()).'-0002', 'Object uses the selected fixed Standard model');
$conf->global->LMDB_ATTESTATION_MASK = 'ATLF-{yyyy}-{0000}';
if (in_array('--pdf',$argv,true)) {
	require_once $core.'/core/lib/functions2.lib.php';
	require_once $core.'/core/class/hookmanager.class.php';
	require_once dirname(__DIR__).'/core/modules/lmdb/doc/pdf_loifinance.modules.php';
	$hookmanager=new HookManager($db);
	$object->fetch(1);
	$dir=$object->documentDirectory(true);
	dol_mkdir($dir.'/private');
	$image=imagecreatetruecolor(200,80); imagefill($image,0,0,imagecolorallocate($image,255,255,255));
	imagestring($image,5,10,30,'SPECIMEN',imagecolorallocate($image,0,0,120)); imagepng($image,$dir.'/private/signature.png');
	$object->snapshot_data=json_encode(array('schema'=>'1','legal_model'=>'BOI-LETTRE-000242-20260325','issuer_name'=>'Société de test Émetteur','issuer_address'=>'10 rue du Test','issuer_zip'=>'75001','issuer_town'=>'Paris','issuer_id'=>'SIREN fictif','signer'=>'Signataire fictif','signer_role'=>'Représentant légal','city'=>'Paris','client_name'=>'Client fictif ÀÉÈÇ','client_address'=>'20 rue du Test','client_zip'=>'69001','client_town'=>'Lyon','client_id'=>'SIREN fictif','version'=>'24.0.2','date_release'=>(string)strtotime('2026-09-01'),'covered_scope'=>'Fonctions de caisse et enregistrement des encaissements couverts par les journaux inaltérables.','excluded_scope'=>'Modules tiers non audités.','signature_file'=>'private/signature.png','date_attestation'=>(string)strtotime('2026-10-24'),'date_acquisition'=>'','date_start'=>'','client_representative'=>'','license_number'=>''),JSON_UNESCAPED_UNICODE);
	if (in_array('--stress',$argv,true)) {
		$stress=json_decode($object->snapshot_data,true);
		$stress['covered_scope']=str_repeat('Périmètre détaillé avec accents : inaltérabilité, sécurisation, conservation et archivage. ',150);
		$object->snapshot_data=json_encode($stress,JSON_UNESCAPED_UNICODE);
		$mysoc=new class($db) extends Societe { public $useLocalTax2=0; };
		$conf->global->MAIN_PDF_FREETEXT='<p>'.str_repeat('Texte libre de pied de page avec caractères accentués. ',8).'</p>';
		$conf->global->PDF_ALLOW_HTML_FOR_FREE_TEXT=1;
		class AttestationPdfFootHook {
			public $warnings=array();
			public $resprints;
			public $results=array();
			public $error='';
			public $errors=array();
			public function pdf_pagefoot($parameters,&$object,&$action,$hookmanager) {
				$this->resprints='<p>Complément de pied de page de recette — hook natif actif.</p>';
				return 0;
			}
		}
		$hookmanager=new HookManager($db);
		$hookmanager->hooks=array('pdfgeneration'=>array('fixture'=>new AttestationPdfFootHook()));
		$hookmanager->hooksSorted=$hookmanager->hooks;
		$hookmanager->contextarray=array('pdfgeneration');
	}
	class AttestationPdfFixture extends LmdbAttestation {
		public $documentEvents=0;
		public function indexFile($destfull,$update_main_doc_field) { return is_file($destfull)?1:-1; }
		public function emitCrud($action,$reason,User $user) { if ($action==='UPDATE' && $reason==='document') { $this->documentEvents++; } return 1; }
	}
	$fixture=new AttestationPdfFixture($db);
	foreach (array('id','ref','entity','status','snapshot_data','date_attestation') as $key) { $fixture->{$key}=$object->{$key}; }
	$model=new pdf_loifinance($db);
	checkAttestation($model->write_file($fixture,$langs)>0,'Native TCPDF two-part PDF generated');
	$db->query("UPDATE fixture_lmdb_attestation SET snapshot_data='".$db->escape($object->snapshot_data)."' WHERE rowid=1");
	$db->query('CREATE TABLE fixture_document_model (nom text,type text,libelle text,description text,entity integer)');
	$db->query("INSERT INTO fixture_document_model VALUES ('loifinance','lmdbattestation','LmdbAttestation','',1)");
	$conf->modules_parts['models']=array('/lmdb/');
	$generated=$fixture->generateDocument('loifinance',$langs);
	checkAttestation($generated>0,'Native generator discovery and business document generation succeed'.($generated>0?'':': '.$fixture->error));
	checkAttestation($fixture->documentEvents===1,'Document generation emits a single UPDATE event');
	echo $dir.'/'.$fixture->ref.'.pdf'."\n";
}
