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
// v21+ includes the native personal preference; v20 uses the global setting.
$leftcolumn = LmdbCompatibility::isDolibarrVersionAtLeast('21.0.0') ? $conf->main_checkbox_left_column : getDolGlobalInt('MAIN_CHECKBOX_LEFT_COLUMN');
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
if ($id<=0 || !isModEnabled('agenda') || (!$user->hasRight('agenda','myactions','read') && !$user->hasRight('agenda','allactions','read'))) { accessforbidden(); }
require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
$limit=max(1,min(1000,GETPOSTINT('limit')?:$conf->liste_limit));
$page=max(0,GETPOSTINT('page'));
$sortfield=GETPOST('sortfield','aZ09comma')?:'a.datep';
$sortorder=strtoupper(GETPOST('sortorder','alpha'))==='ASC'?'ASC':'DESC';
$columns=array('a.id'=>'Ref','a.datep'=>'Date','u.lastname'=>'Owner','c.libelle'=>'Type','a.label'=>'Label','p.lastname'=>'Contact','a.percent'=>'Status');
if (!isset($columns[$sortfield])) { $sortfield='a.datep'; }
$search_label=GETPOST('search_label','alphanohtml');
$search_date=GETPOSTDATE('search_date');
if (GETPOST('button_removefilter','alpha')) { $search_label=''; $search_date=false; $page=0; }
$from=' FROM '.MAIN_DB_PREFIX.'actioncomm a LEFT JOIN '.MAIN_DB_PREFIX.'user u ON u.rowid=a.fk_user_action LEFT JOIN '.MAIN_DB_PREFIX.'c_actioncomm c ON c.id=a.fk_action LEFT JOIN '.MAIN_DB_PREFIX.'socpeople p ON p.rowid=a.fk_contact';
$where=" WHERE a.elementtype='lmdbattestation@lmdb' AND a.fk_element=".$id.' AND a.entity IN ('.$db->sanitize(getEntity('agenda')).')';
// Match native Agenda ownership/assignment restrictions before counting/pagination.
if (!$user->hasRight('agenda','allactions','read')) {
	$where.=' AND (a.fk_user_action='.(int)$user->id.' OR a.fk_user_author='.(int)$user->id.' OR EXISTS (SELECT ar.rowid FROM '.MAIN_DB_PREFIX."actioncomm_resources ar WHERE ar.fk_actioncomm=a.id AND ar.element_type='user' AND ar.fk_element=".(int)$user->id.'))';
}
if ($search_label!=='') { $where.=natural_search('a.label',$search_label); }
if ($search_date) { $where.=" AND a.datep >= '".$db->idate($search_date)."' AND a.datep < '".$db->idate(dol_time_plus_duree($search_date,1,'d'))."'"; }
$res=$db->query('SELECT COUNT(*) AS total'.$from.$where);
if (!$res || !is_object($count=$db->fetch_object($res))) { dol_print_error($db); exit; }
$total=(int)$count->total;
$res=$db->query('SELECT a.id,a.label,a.datep,a.datep2,a.percent,a.fk_user_action,a.fk_user_author,c.code AS type_code,c.libelle AS type_label,u.lastname,u.firstname,p.rowid AS contact_id,p.lastname AS contact_lastname,p.firstname AS contact_firstname'.$from.$where.$db->order($sortfield,$sortorder).$db->plimit($limit,$page*$limit));
if (!$res) { dol_print_error($db); exit; }
$param='&id='.$id.'&search_label='.urlencode($search_label);
if ($search_date) { foreach (array('day'=>'%d','month'=>'%m','year'=>'%Y') as $part=>$format) { $param.='&search_date'.$part.'='.dol_print_date($search_date,$format); } }
lmdbAttestationHeader($object,'agenda');
print dol_get_fiche_end();
print '<form method="get" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="id" value="'.$id.'">';
print_barre_liste($langs->trans('Events'),$page,$_SERVER['PHP_SELF'],$param,$sortfield,$sortorder,'',$db->num_rows($res),$total,'',0,'','',$limit);
print '<div class="div-table-responsive"><table class="tagtable liste centpercent"><tr class="liste_titre_filter">';
if ($leftcolumn) { print '<td class="liste_titre maxwidthsearch center actioncolumn">'.$form->showFilterButtons('left').'</td>'; }
foreach ($columns as $key=>$label) {
	print '<td>';
	if ($key==='a.label') { print '<input name="search_label" class="flat maxwidth150" value="'.dol_escape_htmltag($search_label).'">'; }
	if ($key==='a.datep') { print $form->selectDate($search_date?:'','search_date',0,0,1,'',1,0); }
	print '</td>';
}
print '<td></td>'; // Linked object filter.
if (!$leftcolumn) { print '<td class="liste_titre maxwidthsearch center actioncolumn">'.$form->showFilterButtons().'</td>'; }
print '</tr><tr class="liste_titre">';
if ($leftcolumn) { print '<td class="center maxwidthsearch actioncolumn"></td>'; }
foreach ($columns as $key=>$label) { print_liste_field_titre($langs->trans($label),$_SERVER['PHP_SELF'],$key,'',$param,'',$sortfield,$sortorder); }
print '<th>'.$langs->trans('LinkedObject').'</th>';
if (!$leftcolumn) { print '<td class="center maxwidthsearch actioncolumn"></td>'; }
print '</tr>';
$num=0;
while (is_object($row=$db->fetch_object($res))) {
	$num++;
	$event=new ActionComm($db); $event->id=$row->id; $event->ref=(string)$row->id; $event->label=$row->label; $event->type_code=$row->type_code; $event->type_label=$row->type_label; $event->code=$row->type_code; $event->percentage=$row->percent; $event->authorid=$row->fk_user_author; $event->userownerid=$row->fk_user_action;
	// Rows are already scoped above. Preserve native links for assigned users.
	$event->userassigned=array($user->id=>array('id'=>$user->id,'transparency'=>0));
	$owneruser=new User($db); $owneruser->id=$row->fk_user_action; $owneruser->lastname=$row->lastname; $owneruser->firstname=$row->firstname;
	$contact=new Contact($db); $contact->id=$row->contact_id; $contact->lastname=$row->contact_lastname; $contact->firstname=$row->contact_firstname;
	print '<tr class="oddeven">';
	if ($leftcolumn) { print '<td class="nowrap center actioncolumn"></td>'; }
	print '<td>'.$event->getNomUrl(1,0,'','',0,1).'</td><td>'.dol_print_date($db->jdate($row->datep),'dayhour').'</td><td>'.($owneruser->id?$owneruser->getNomUrl(1,'',0,0,0,1):'').'</td><td>'.dol_escape_htmltag($event->getTypeLabel()).'</td><td>'.dol_escape_htmltag($row->label).'</td><td>'.($contact->id?$contact->getNomUrl(1,'',0,'',1):'').'</td><td>'.$event->getLibStatut(5).'</td><td>'.$object->getNomUrl(1).'</td>';
	if (!$leftcolumn) { print '<td class="nowrap center actioncolumn"></td>'; }
	print '</tr>';
}
if (!$num) { print '<tr class="oddeven"><td colspan="9"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>'; }
print '</table></div></form>';
llxFooter(); $db->close();
