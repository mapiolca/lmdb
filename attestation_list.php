<?php
/* Copyright (C) 2026 Pierre Ardoin. SPDX-License-Identifier: GPL-3.0-or-later */
/** @var DoliDB $db */
/** @var Conf $conf */
/** @var User $user */
/** @var Translate $langs */
/** @var HookManager $hookmanager */
/** @var LmdbAttestation $object */
/** @var Form $form */
/** @var FormFile $formfile */
/** @var FormActions $formactions */
global $db, $conf, $user, $langs, $hookmanager, $object, $form, $formfile, $formactions;
/** @var int $id */
/** @var string $action */
global $id, $action;

require __DIR__.'/lib/lmdb_attestation_bootstrap.php';
$hookmanager->initHooks(array('lmdbattestationlist'));
$contextpage='lmdbattestationlist';
$varpage=$contextpage;
$sortfield=GETPOST('sortfield','aZ09comma')?:'t.rowid';
$sortorder=strtoupper(GETPOST('sortorder','alpha'))==='ASC'?'ASC':'DESC';
$sortable=array('t.rowid','t.ref','s.nom','v.version','t.date_attestation','t.status','t.entity');
if (!in_array($sortfield,$sortable,true)) { $sortfield='t.rowid'; }
$limit=max(1,min(1000,GETPOSTINT('limit')?:$conf->liste_limit));
$page=max(0,GETPOSTINT('page'));
$search_ref=GETPOST('search_ref','alphanohtml');
$search_soc=GETPOST('search_soc','alphanohtml');
$search_version=GETPOSTINT('search_version');
$search_status=GETPOSTISSET('search_status')?GETPOST('search_status','int'):'';
$search_entity=GETPOST('search_entity','array:int');
$search_date_start=GETPOSTDATE('search_date_start');
$search_date_end=GETPOSTDATE('search_date_end');

if (GETPOST('button_removefilter','alpha') || GETPOST('button_removefilter_x','alpha')) { $search_ref=''; $search_soc=''; $search_version=0; $search_status=''; $search_entity=array(); $search_date_start=false; $search_date_end=false; $page=0; }
$entities=array();
if (isModEnabled('multicompany')) {
	dol_include_once('/multicompany/class/entity.class.php');
	foreach (array_filter(array_map('intval',explode(',',getEntity('lmdbattestation')))) as $entityid) {
		$entityobject=new Entity($db);
		if ($entityobject->fetch($entityid)<=0) { accessforbidden(); }
		$entities[$entityid]=$entityobject->label;
	}
}
$shared=count($entities)>1;
$arrayfields=array(
	't.ref'=>array('label'=>'Ref','checked'=>'1','enabled'=>'1','position'=>1),
	's.nom'=>array('label'=>'ThirdParty','checked'=>'1','enabled'=>'1','position'=>2),
	'v.version'=>array('label'=>'LmdbAttestedVersion','checked'=>'1','enabled'=>'1','position'=>3),
	't.date_attestation'=>array('label'=>'LmdbAttestationDate','checked'=>'1','enabled'=>'1','position'=>4),
	't.status'=>array('label'=>'Status','checked'=>'1','enabled'=>'1','position'=>5),
);
if ($shared) { $arrayfields['t.entity']=array('label'=>'LmdbEnvironment','checked'=>'1','enabled'=>'1','position'=>6); }
include DOL_DOCUMENT_ROOT.'/core/actions_changeselectedfields.inc.php';
$select='t.rowid,t.ref,t.fk_soc,t.date_attestation,t.status,t.entity,t.snapshot_data,s.nom,v.version';
$parameters=array();
$hookmanager->executeHooks('printFieldListSelect',$parameters,$object,$action); $select.=$hookmanager->resPrint;
$from=' FROM '.MAIN_DB_PREFIX.'lmdb_attestation t INNER JOIN '.MAIN_DB_PREFIX.'societe s ON s.rowid=t.fk_soc LEFT JOIN '.MAIN_DB_PREFIX.'lmdb_attestation_version v ON v.rowid=t.fk_version AND v.entity=t.entity';
$where=' WHERE '.LmdbAttestation::scopeSql($db,$user);
if ($search_ref!=='') { $where.=natural_search('t.ref',$search_ref); }
if ($search_soc!=='') { $where.=natural_search('s.nom',$search_soc); }
if ($search_version) { $where.=' AND t.fk_version='.(int)$search_version; }
if ($search_status!=='' && in_array((int)$search_status,array(0,1,9),true)) { $where.=' AND t.status='.(int)$search_status; }
if ($search_date_start) { $where.=" AND t.date_attestation >= '".$db->idate($search_date_start)."'"; }
if ($search_date_end) { $where.=" AND t.date_attestation <= '".$db->idate($search_date_end)."'"; }
$selectedentities=array_values(array_intersect(array_map('intval',$search_entity),array_keys($entities)));
if ($search_entity) { $where.=' AND t.entity IN ('.($selectedentities?implode(',',$selectedentities):'0').')'; }
$hookmanager->executeHooks('printFieldListWhere',$parameters,$object,$action); $where.=$hookmanager->resPrint;
$res=$db->query('SELECT COUNT(*) AS total'.$from.$where);
if (!$res || !is_object($count=$db->fetch_object($res))) { dol_print_error($db); exit; }
$total=(int)$count->total;
$res=$db->query('SELECT '.$select.$from.$where.$db->order($sortfield,$sortorder).$db->plimit($limit,$page*$limit));
if (!$res) { dol_print_error($db); exit; }
$param='&search_ref='.urlencode($search_ref).'&search_soc='.urlencode($search_soc).'&search_version='.$search_version.'&search_status='.urlencode((string)$search_status);
foreach (array('search_date_start'=>$search_date_start,'search_date_end'=>$search_date_end) as $key=>$date) { if ($date) { foreach (array('day'=>'%d','month'=>'%m','year'=>'%Y') as $part=>$format) { $param.='&'.$key.$part.'='.dol_print_date($date,$format); } } }
foreach ($selectedentities as $eid) { $param.='&search_entity[]='.$eid; }
llxHeader('',$langs->trans('LmdbAttestationList'));
print '<form method="post" id="searchFormList" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="sortfield" value="'.$sortfield.'"><input type="hidden" name="sortorder" value="'.$sortorder.'">';
$new=$user->hasRight('lmdb','lmdbattestation','write')?dolGetButtonTitle($langs->trans('LmdbNewAttestation'),'','fa fa-plus-circle',dol_buildpath('/lmdb/attestation_card.php',1).'?action=create'):'';
print_barre_liste($langs->trans('LmdbAttestationList'),$page,$_SERVER['PHP_SELF'],$param,$sortfield,$sortorder,'',$db->num_rows($res),$total,'',0,$new,'', $limit);
$selectedfields=$form->multiSelectArrayWithCheckbox('selectedfields',$arrayfields,$varpage,$conf->main_checkbox_left_column,1);
$visible=array_filter($arrayfields,static function ($field) { return !empty($field['checked']); });
print '<div class="div-table-responsive"><table class="tagtable liste centpercent"><tr class="liste_titre_filter">';
foreach ($visible as $key=>$field) {
	print '<td>';
	if ($key==='t.ref') { print '<input class="flat maxwidth100" type="text" name="search_ref" value="'.dol_escape_htmltag($search_ref).'">'; }
	if ($key==='s.nom') { print '<input class="flat maxwidth150" type="text" name="search_soc" value="'.dol_escape_htmltag($search_soc).'">'; }
	if ($key==='v.version') { $choices=LmdbAttestationVersion::choices($db,$conf->entity,$search_version); if ($choices!==false) { print $form->selectarray('search_version',$choices,$search_version,1); print ajax_combobox('search_version'); } }
	if ($key==='t.date_attestation') { print $form->selectDate($search_date_start?:'', 'search_date_start',0,0,1,'',1,0); print '<br>'; print $form->selectDate($search_date_end?:'', 'search_date_end',0,0,1,'',1,0); }
	if ($key==='t.status') { print $form->selectarray('search_status',array(''=>'',0=>$langs->trans('Draft'),1=>$langs->trans('Validated'),9=>$langs->trans('Canceled')),$search_status); print ajax_combobox('search_status'); }
	if ($key==='t.entity') { print $form->multiselectarray('search_entity',$entities,array_map('strval',$selectedentities),0,0,'minwidth150'); }
	print '</td>';
}
print '<td class="liste_titre center">'.$form->showFilterButtons().'</td></tr><tr class="liste_titre">';
foreach ($visible as $key=>$field) { print_liste_field_titre($langs->trans($field['label']),$_SERVER['PHP_SELF'],$key,'',$param,'',$sortfield,$sortorder); }
print '<th class="center">'.$selectedfields.'</th></tr>';
$num=0;
while (is_object($row=$db->fetch_object($res))) {
	$num++;
	$line=new LmdbAttestation($db); $line->id=$row->rowid; $line->ref=$row->ref; $line->status=$row->status;
	$thirdparty=new Societe($db); $thirdparty->id=$row->fk_soc; $thirdparty->name=$row->nom;
	$snapshot=json_decode((string)$row->snapshot_data,true);
	$versionlabel=is_array($snapshot)&&isset($snapshot['version'])?$snapshot['version']:$row->version;
	print '<tr class="oddeven">';
	foreach ($visible as $key=>$field) {
		print '<td'.($key==='t.entity'?' align="center"':'').'>';
		if ($key==='t.ref') { print $line->getNomUrl(1); }
		if ($key==='s.nom') { print $thirdparty->getNomUrl(1); }
		if ($key==='v.version') { print dol_escape_htmltag($versionlabel); }
		if ($key==='t.date_attestation') { print dol_print_date($db->jdate($row->date_attestation),'day'); }
		if ($key==='t.status') { print $line->getLibStatut(5); }
		if ($key==='t.entity') { print '<div class="refidno multicompany-entity-card-container"><span class="fa fa-globe"></span><span class="multiselect-selected-title-text">'.dol_escape_htmltag($entities[$row->entity]).'</span></div>'; }
		print '</td>';
	}
	print '<td></td></tr>';
}
if (!$num) { print '<tr class="oddeven"><td colspan="'.(count($visible)+1).'"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>'; }
print '</table></div></form>'; llxFooter(); $db->close();
