<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 * SPDX-License-Identifier: GPL-3.0-or-later */
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

$lmdbAdminPage=true;
require __DIR__.'/../lib/lmdb_attestation_bootstrap.php';
require_once __DIR__.'/../lib/lmdb.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formadmin.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
if (!$user->admin || !$user->hasRight('lmdb','setup','configure')) { accessforbidden(); }
$settingkeys=array('LMDB_ATTESTATION_SIGNER'=>'LmdbIssuerRepresentative','LMDB_ATTESTATION_SIGNER_ROLE'=>'LmdbSignerRole','LMDB_ATTESTATION_CITY'=>'Town','LMDB_ATTESTATION_MASK'=>'LmdbAttestationNumberingMask');
$version = new LmdbAttestationVersion($db);
$versionid=GETPOSTINT('versionid');
if ($versionid>0 && $version->fetch($versionid)<=0) { accessforbidden(); }
// Admin catalogue IDs must not be fetched as attestation IDs by the shared bootstrap.
if ($_SERVER['REQUEST_METHOD']==='POST') {
	$result=-1;
	if ($action==='savesettings') {
		$result=1;
		foreach ($settingkeys as $key=>$label) { if (dolibarr_set_const($db,$key,GETPOST($key,'alphanohtml'),'chaine',0,'',(int)$conf->entity)<=0) { $result=-1; } }
		if (isset($_FILES['signature']['tmp_name']) && is_string($_FILES['signature']['tmp_name']) && $_FILES['signature']['tmp_name']!=='') {
			$temp=$_FILES['signature']['tmp_name'];
			if (!class_exists('finfo') || !function_exists('imagecreatefrompng') || !is_uploaded_file($temp) || (new finfo(FILEINFO_MIME_TYPE))->file($temp)!=='image/png' || filesize($temp)>2*1024*1024) { $result=-1; }
			else {
				$info=getimagesize($temp);
				if (!$info || $info[0]>3000 || $info[1]>3000) { $result=-1; }
				else {
					$image=imagecreatefrompng($temp);
					$root=isset($conf->lmdb->multidir_output[$conf->entity])?$conf->lmdb->multidir_output[$conf->entity]:'';
					$filename='signature-'.bin2hex(random_bytes(8)).'.png';
					if (!$image || !$root || is_link($root.'/attestation_settings') || dol_mkdir($root.'/attestation_settings')<0) { $result=-1; }
					else {
						imagesavealpha($image,true);
						if (!imagepng($image,$root.'/attestation_settings/'.$filename)) { $result=-1; }
						elseif (dolibarr_set_const($db,'LMDB_ATTESTATION_SIGNATURE',$filename,'chaine',0,'',(int)$conf->entity)<=0) { $result=-1; }
						else { dolChmod($root.'/attestation_settings/'.$filename); }
					}
				}
			}
		}
	} elseif ($action==='saveversion') {
		$version->version=GETPOST('version','alphanohtml');
		$version->date_release=GETPOSTDATE('date_release');
		$version->covered_scope=GETPOST('covered_scope','nohtml');
		$version->excluded_scope=GETPOST('excluded_scope','nohtml');
		$version->active=GETPOSTINT('active');
		$result=$version->save($user);
	} elseif ($action==='toggleversion' && $version->id>0) { $version->active=(int)!$version->active; $result=$version->save($user);
	} elseif ($action==='setpdf') {
		$result=addDocumentModel('loifinance','lmdbattestation','LmdbAttestation');
		if ($result>0) { $result=dolibarr_set_const($db,'LMDB_ATTESTATION_ADDON_PDF','loifinance','chaine',0,'',(int)$conf->entity); }
	} elseif ($action==='disablepdf') { $result=delDocumentModel('loifinance','lmdbattestation');
	} elseif ($action==='setnumbering') { $result=dolibarr_set_const($db,'LMDB_ATTESTATION_ADDON','mod_lmdbattestation_standard','chaine',0,'',(int)$conf->entity); }
	if ($result>0) { setEventMessages($langs->trans('RecordSaved'),null,'mesgs'); header('Location: '.$_SERVER['PHP_SELF']); exit; }
	setEventMessages($version->error ?: $langs->trans('ErrorBadParameters'),null,'errors');
}
llxHeader('',$langs->trans('LmdbAttestations'));
print load_fiche_titre($langs->trans('LmdbAttestations'),lmdbGetBackToModuleListLink(),'title_setup');
print dol_get_fiche_head(lmdbAdminPrepareHead(),'attestation','',-1);
print '<div class="info">'.$langs->trans('LmdbAttestationSettingsHelp').'</div>';
print '<form method="post" enctype="multipart/form-data" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="savesettings"><div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="liste_titre"><td colspan="2">'.$langs->trans('LmdbIssuerSettings').'</td></tr>';
foreach ($settingkeys as $key=>$label) { print '<tr class="oddeven"><td class="titlefield fieldrequired">'.$langs->trans($label).'</td><td><input class="flat minwidth300" type="text" name="'.$key.'" value="'.dol_escape_htmltag(getDolGlobalString($key)).'" required></td></tr>'; }
print '<tr class="oddeven"><td>'.$langs->trans('Signature').' (PNG, 2 Mo)</td><td><input type="file" name="signature" accept="image/png">'.(getDolGlobalString('LMDB_ATTESTATION_SIGNATURE')?' '.img_picto($langs->trans('Available'),'tick'):'').'</td></tr></table></div><div class="center"><input class="button" type="submit" value="'.$langs->trans('Save').'"></div></form>';
print load_fiche_titre($langs->trans('LmdbAttestedVersions'));
$res=$db->query('SELECT rowid,version,date_release,covered_scope,excluded_scope,active FROM '.MAIN_DB_PREFIX.'lmdb_attestation_version WHERE entity='.(int)$conf->entity.' ORDER BY version DESC');
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('LmdbAttestedVersion').'</td><td>'.$langs->trans('LmdbReleaseDate').'</td><td>'.$langs->trans('Status').'</td><td></td></tr>';
$count=0;
if (!$res) { dol_print_error($db); }
else { while (is_object($row=$db->fetch_object($res))) { $count++; print '<tr class="oddeven"><td>'.dol_escape_htmltag($row->version).'</td><td>'.dol_print_date($db->jdate($row->date_release),'day').'</td><td>'.'<form method="post"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="versionid" value="'.(int)$row->rowid.'"><input type="hidden" name="action" value="toggleversion"><button class="noborder" type="submit">'.img_picto($langs->trans($row->active?'Enabled':'Disabled'),$row->active?'on':'off').'</button></form>'.'</td><td><a href="'.$_SERVER['PHP_SELF'].'?versionid='.(int)$row->rowid.'">'.img_edit().'</a></td></tr>'; } }
if (!$count) { print '<tr class="oddeven"><td colspan="4"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>'; }
print '</table></div>';
$versionid=GETPOSTINT('versionid');
if ($versionid>0 && $version->fetch($versionid)<=0) { accessforbidden(); }
print '<form method="post" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="saveversion"><input type="hidden" name="versionid" value="'.(int)$version->id.'"><div class="div-table-responsive-no-min"><table class="border centpercent">';
foreach ($version->fields as $key=>$field) {
	if (!$field['visible']) { continue; }
	print '<tr><td class="titlefield'.(!empty($field['notnull'])?' fieldrequired':'').'">'.$langs->trans($field['label']).'</td><td>';
	if ($key==='date_release') { print $form->selectDate($version->date_release?:'','date_release',0,0,1,'',1,0); }
	elseif ($key==='active') { print '<input type="hidden" name="active" value="'.(int)$version->active.'">'.img_picto($langs->trans($version->active?'Enabled':'Disabled'),$version->active?'on':'off'); }
	elseif ($field['type']==='text') { print '<textarea class="flat centpercent" name="'.$key.'" rows="3">'.dol_escape_htmltag($version->{$key}).'</textarea>'; }
	else { print $version->showInputField($field,$key,$version->{$key}); }
	print '</td></tr>';
}
print '</table></div><div class="center"><button class="button" type="submit">'.$langs->trans('Save').'</button></div></form>';
// Native model management: activation, default selection, mask, example and help.
require_once __DIR__.'/../core/modules/lmdb/modules_lmdbattestation.php';
require_once __DIR__.'/../core/modules/lmdb/mod_lmdbattestation_standard.php';
$models=ModelePDFLmdbattestation::liste_modeles($db);
print load_fiche_titre($langs->trans('PDFModules'));
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('Name').'</td><td>'.$langs->trans('Status').'</td><td>'.$langs->trans('Default').'</td></tr><tr class="oddeven"><td>loifinance</td><td>'.yn(is_array($models)&&isset($models['loifinance'])).'</td><td>';
foreach (array('setpdf'=>'Default','disablepdf'=>'Disable') as $operation=>$label) { print '<form class="inline-block" method="post"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="'.$operation.'"><button class="button small" type="submit">'.$langs->trans($label).'</button></form>'; }
print '</td></tr></table></div>';
$numbering=new mod_lmdbattestation_standard();
print load_fiche_titre($langs->trans('NumberingModules'));
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('Name').'</td><td>'.$langs->trans('Example').'</td><td>'.$langs->trans('Information').'</td></tr><tr class="oddeven"><td>standard</td><td>'.dol_escape_htmltag($numbering->getExample()).'</td><td>'.dol_escape_htmltag(getDolGlobalString('LMDB_ATTESTATION_MASK')).' — '.$numbering->info($langs).'</td></tr></table></div>';
print dol_get_fiche_end(); llxFooter(); $db->close();
