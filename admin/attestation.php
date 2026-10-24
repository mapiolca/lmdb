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
if (!defined('CSRFCHECK_WITH_TOKEN')) { define('CSRFCHECK_WITH_TOKEN', 1); }
require __DIR__.'/../lib/lmdb_attestation_bootstrap.php';
$langs->loadLangs(array('admin', 'errors'));
require_once __DIR__.'/../lib/lmdb.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formadmin.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
if (!$user->admin || !$user->hasRight('lmdb','setup','configure')) { accessforbidden(); }
$settingkeys=array('LMDB_ATTESTATION_SIGNER'=>'LmdbIssuerRepresentative','LMDB_ATTESTATION_SIGNER_ROLE'=>'LmdbSignerRole','LMDB_ATTESTATION_CITY'=>'Town');
$version = new LmdbAttestationVersion($db);
$versionid=GETPOSTINT('versionid');
if ($versionid>0 && $version->fetch($versionid)<=0) { accessforbidden(); }
// Admin catalogue IDs must not be fetched as attestation IDs by the shared bootstrap.
if ($_SERVER['REQUEST_METHOD']==='POST' || in_array($action, array('enablepdf','disablepdf','setpdf','setnumbering','setversionactive','confirm_deleteversion'), true)) {
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
		$result=$version->save($user);
		if ($result <= 0) { $action = $versionid > 0 ? 'editversion' : 'createversion'; }
	} elseif ($action==='setversionactive' && $version->id>0) { $result=$version->setActive($user, GETPOSTINT('value'));
	} elseif ($action==='confirm_deleteversion') {
		if (GETPOST('confirm','alpha') !== 'yes') { header('Location: '.$_SERVER['PHP_SELF']); exit; }
		$result=$version->delete($user);
	} elseif ($action==='updateMask') {
		$mask = GETPOST('maskattestation', 'alphanohtml');
		$samplethirdparty = new Societe($db);
		$samplethirdparty->initAsSpecimen();
		$example = get_next_value($db, $mask, 'lmdb_attestation', 'ref', '', $samplethirdparty, dol_now(), 'next', false, null, (string)(int)$conf->entity);
		if ($mask !== '' && strlen($mask) <= 128 && !preg_match('/\s/', $mask) && $example !== '' && !preg_match('/^(Error|error)/', $example) && !preg_match('/[{}]/', $example) && $example === dol_sanitizeFileName($example)) {
			$result = dolibarr_set_const($db, 'LMDB_ATTESTATION_MASK', $mask, 'chaine', 0, '', (int)$conf->entity);
		} else { $version->error = $langs->trans('ErrorBadMask'); }
	} elseif ($action==='enablepdf') { $result=addDocumentModel('loifinance','lmdbattestation','LmdbAttestation');
	} elseif ($action==='setpdf') {
		$result=addDocumentModel('loifinance','lmdbattestation','LmdbAttestation');
		if ($result>0) { $result=dolibarr_set_const($db,'LMDB_ATTESTATION_ADDON_PDF','loifinance','chaine',0,'',(int)$conf->entity); }
	} elseif ($action==='disablepdf') { $result=delDocumentModel('loifinance','lmdbattestation');
	} elseif ($action==='setnumbering') {
		$numberingmodel = GETPOST('value', 'aZ09');
		if (in_array($numberingmodel, array('mod_lmdbattestation_standard', 'mod_lmdbattestation_advanced'), true)) {
			$result=dolibarr_set_const($db,'LMDB_ATTESTATION_ADDON',$numberingmodel,'chaine',0,'',(int)$conf->entity);
		}
	}
	if ($result>0) { setEventMessages($langs->trans('RecordSaved'),null,'mesgs'); header('Location: '.$_SERVER['PHP_SELF']); exit; }
	setEventMessages($langs->trans($version->error ?: 'ErrorBadParameters'),$version->errors,'errors');
}
llxHeader('',$langs->trans('LmdbAttestations'));
print load_fiche_titre($langs->trans('LmdbAttestations'),lmdbGetBackToModuleListLink(),'title_setup');
print dol_get_fiche_head(lmdbAdminPrepareHead(),'attestation','',-1);
// Core admin layout: numbering first, then document models, before business settings.
require_once __DIR__.'/../core/modules/lmdb/modules_lmdbattestation.php';
require_once __DIR__.'/../core/modules/lmdb/mod_lmdbattestation_standard.php';
require_once __DIR__.'/../core/modules/lmdb/mod_lmdbattestation_advanced.php';
require_once __DIR__.'/../core/modules/lmdb/doc/pdf_loifinance.modules.php';
print load_fiche_titre($langs->trans('NumberingModules'), '', '');
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('Name').'</td><td>'.$langs->trans('Description').'</td><td class="nowrap">'.$langs->trans('Example').'</td><td class="center width60">'.$langs->trans('Status').'</td><td class="center">'.$langs->trans('ShortInfo').'</td></tr>';
foreach (array('mod_lmdbattestation_standard','mod_lmdbattestation_advanced') as $numberingclass) {
	$numbering = new $numberingclass();
	print '<tr class="oddeven"><td>'.dol_escape_htmltag($numbering->getName($langs)).'</td><td>'.$numbering->info($langs).'</td><td class="nowrap">'.dol_escape_htmltag($numbering->getExample()).'</td><td class="center">';
	if (getDolGlobalString('LMDB_ATTESTATION_ADDON') === $numberingclass) { print img_picto($langs->trans('Activated'), 'switch_on'); }
	else { print '<a class="reposition" href="'.$_SERVER['PHP_SELF'].'?action=setnumbering&value='.$numberingclass.'&token='.newToken().'">'.img_picto($langs->trans('Disabled'), 'switch_off').'</a>'; }
	print '</td><td class="center">'.$form->textwithpicto('', $langs->trans('Version').': '.dol_escape_htmltag($numbering->getVersion()), 1, 'info').'</td></tr>';
}
print '</table></div><br>';

$models = ModelePDFLmdbattestation::liste_modeles($db);
if (!is_array($models)) { dol_print_error($db); }
$pdfmodel = new pdf_loifinance($db);
$pdfactive = is_array($models) && isset($models[$pdfmodel->name]);
print load_fiche_titre($langs->trans('ModelModules'), '', '');
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('Name').'</td><td>'.$langs->trans('Description').'</td><td class="center width60">'.$langs->trans('Status').'</td><td class="center width60">'.$langs->trans('Default').'</td><td class="center">'.$langs->trans('ShortInfo').'</td></tr>';
print '<tr class="oddeven"><td>'.dol_escape_htmltag($pdfmodel->name).'</td><td>'.$langs->trans($pdfmodel->description).'</td><td class="center"><a class="reposition" href="'.$_SERVER['PHP_SELF'].'?action='.($pdfactive ? 'disablepdf' : 'enablepdf').'&token='.newToken().'">'.img_picto($langs->trans($pdfactive ? 'Enabled' : 'Disabled'), $pdfactive ? 'switch_on' : 'switch_off').'</a></td><td class="center">';
if ($pdfactive && getDolGlobalString('LMDB_ATTESTATION_ADDON_PDF') === $pdfmodel->name) { print img_picto($langs->trans('Default'), 'on'); }
else { print '<a class="reposition" href="'.$_SERVER['PHP_SELF'].'?action=setpdf&token='.newToken().'">'.img_picto($langs->trans('Default'), 'off').'</a>'; }
$pdfinfo = $langs->trans('Name').': '.dol_escape_htmltag($pdfmodel->name).'<br>'.$langs->trans('Type').': '.dol_escape_htmltag($pdfmodel->type).'<br>'.$langs->trans('Width').'/'.$langs->trans('Height').': '.$pdfmodel->page_largeur.'/'.$pdfmodel->page_hauteur;
print '</td><td class="center">'.$form->textwithpicto('', $pdfinfo, 1, 'info').'</td></tr></table></div><br>';
print '<div class="info">'.$langs->trans('LmdbAttestationSettingsHelp').'</div>';
print '<form method="post" enctype="multipart/form-data" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="savesettings"><div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="liste_titre"><td colspan="2">'.$langs->trans('LmdbIssuerSettings').'</td></tr>';
foreach ($settingkeys as $key=>$label) { print '<tr class="oddeven"><td class="titlefield fieldrequired">'.$langs->trans($label).'</td><td><input class="flat minwidth300" type="text" name="'.$key.'" value="'.dol_escape_htmltag(getDolGlobalString($key)).'" required></td></tr>'; }
print '<tr class="oddeven"><td>'.$langs->trans('Signature').' (PNG, 2 Mo)</td><td><input type="file" name="signature" accept="image/png">'.(getDolGlobalString('LMDB_ATTESTATION_SIGNATURE')?' '.img_picto($langs->trans('Available'),'tick'):'').'</td></tr></table></div><div class="center"><input class="button" type="submit" value="'.$langs->trans('Save').'"></div></form>';
$newversion = '<a class="reposition" href="'.$_SERVER['PHP_SELF'].'?action=createversion&token='.newToken().'" title="'.dol_escape_htmltag($langs->trans('Add')).'" aria-label="'.dol_escape_htmltag($langs->trans('Add')).'">'.img_picto($langs->trans('Add'), 'add').'</a>';
print load_fiche_titre($langs->trans('LmdbAttestedVersions'), $newversion);
$res=$db->query('SELECT rowid,version,date_release,covered_scope,excluded_scope,active FROM '.MAIN_DB_PREFIX.'lmdb_attestation_version WHERE entity='.(int)$conf->entity.' ORDER BY version DESC');
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('LmdbAttestedVersion').'</td><td>'.$langs->trans('LmdbReleaseDate').'</td><td>'.$langs->trans('Status').'</td><td></td></tr>';
$count=0;
if (!$res) { dol_print_error($db); }
else {
	while (is_object($row=$db->fetch_object($res))) {
		$count++;
		$versionurl = $_SERVER['PHP_SELF'].'?versionid='.(int)$row->rowid;
		print '<tr class="oddeven"><td>'.dol_escape_htmltag($row->version).'</td><td>'.dol_print_date($db->jdate($row->date_release),'day').'</td>';
		print '<td class="center"><a class="reposition" href="'.$versionurl.'&action=setversionactive&value='.($row->active ? '0' : '1').'&token='.newToken().'">'.img_picto($langs->trans($row->active ? 'Enabled' : 'Disabled'), $row->active ? 'switch_on' : 'switch_off').'</a></td>';
		print '<td class="right nowraponall"><a class="editfielda" href="'.$versionurl.'&action=editversion&token='.newToken().'">'.img_edit().'</a> ';
		print '<a class="marginleftonly" href="'.$versionurl.'&action=deleteversion&token='.newToken().'">'.img_delete().'</a></td></tr>';
	}
}
if (!$count) { print '<tr class="oddeven"><td colspan="4"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>'; }
print '</table></div>';
if ($action === 'deleteversion' && $version->id > 0) {
	print $form->formconfirm($_SERVER['PHP_SELF'].'?versionid='.(int)$version->id, $langs->trans('Delete'), $langs->trans('LmdbAttestedVersionConfirmDelete', dol_escape_htmltag($version->version)), 'confirm_deleteversion', '', 0, 1);
}
if (in_array($action, array('createversion','editversion'), true)) { require __DIR__.'/../lib/lmdb_attestation_version_form.php'; }
print dol_get_fiche_end(); llxFooter(); $db->close();
