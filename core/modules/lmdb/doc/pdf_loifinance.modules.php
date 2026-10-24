<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once DOL_DOCUMENT_ROOT.'/core/class/commondocgenerator.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';

/** French fiscal form BOI-LETTRE-000242-20260325, two separate parts.
 * Uses the explicit legal snapshot, never mutable company/catalogue values.
 */
class pdf_loifinance extends CommonDocGenerator
{
	public $name = 'loifinance';
	public $description = 'LmdbAttestationPdfDescription';
	public $type = 'pdf';
	public $version = 'dolibarr';
	public $update_main_doc_field = 1;
	public $phpmin = array(8, 0);
	public $emetteur;
	public $marge_gauche = 15;
	public $marge_droite = 15;
	public $marge_haute = 15;
	public $marge_basse = 15;
	public $page_largeur = 210;
	public $page_hauteur = 297;
	public $format = array(210, 297);
	public function __construct($db) { $this->db = $db; }

	public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		global $user, $langs, $conf;
		if (!$user->hasRight('lmdb', 'lmdbattestation', 'generate') || (int)$object->status !== LmdbAttestation::STATUS_VALIDATED) { $this->error = 'NotEnoughPermissions'; return 0; }
		try { $data = $object->legalSnapshot(); } catch (RuntimeException $e) { $this->error = $e->getMessage(); return 0; }
		foreach (array('issuer_name','issuer_address','issuer_zip','issuer_town','issuer_id','signer','signer_role','city','client_name','client_address','client_zip','client_town','client_id','version','date_release','covered_scope','excluded_scope','date_attestation','date_acquisition','date_start','client_representative','license_number') as $required) { if (!array_key_exists($required,$data)) { $this->error='LmdbInvalidSnapshot'; return 0; } }
		$dir = $object->documentDirectory(true);
		if ($dir === false || is_link($dir.'/'.$object->ref.'.pdf') || !is_file($dir.'/private/signature.png') || is_link($dir.'/private/signature.png')) { $this->error = 'LmdbDocumentDirectoryUnavailable'; return 0; }
		// The administrative model is French, independent of the user's UI language.
		$french = new Translate('', $conf);
		$french->setDefaultLang('fr_FR');
		$french->loadLangs(array('main','companies','dict','lmdb@lmdb'));
		$object->context['outputlangs'] = $french;
		$pdf = pdf_getInstance($this->format);
		$pdf->SetCreator('Dolibarr');
		$pdf->SetAuthor($data['issuer_name']);
		$pdf->SetTitle('Attestation de conformité Loi Finance — '.$object->ref);
		$pdf->SetMargins(15, 15, 15);
		$pdf->setPrintHeader(false);
		$pdf->setPrintFooter(false);
		$pdf->SetFont(pdf_getPDFFont($french), '', 10);
		// Measure/surreserve footer before rendering. Even long native free text
		// and company details cannot collide with flowing content.
		$this->emetteur = new Societe($this->db);
		foreach (array('name','address','zip','town','idprof1') as $property) {
			$key = $property === 'idprof1' ? 'issuer_id' : 'issuer_'.$property;
			$this->emetteur->{$property} = $data[$key];
		}
		$pdf->AddPage();
		// Native HTML height measurement uses its own TCPDF transactions.
		// An independent instance avoids nesting those transactions on the document.
		$measure=pdf_getInstance($this->format);
		$measure->SetMargins(15,15,15);
		$measure->setPrintHeader(false); $measure->setPrintFooter(false);
		$measure->SetFont(pdf_getPDFFont($french),'',10); $measure->AddPage();
		$footer=max(30,$this->_pagefoot($measure,$object,$french,0)+5);
		if ($footer > 140) { $this->error = 'LmdbAttestationFooterTooHigh'; return 0; }
		$pdf->setPageOrientation('', true, $footer);
		$pdf->SetAutoPageBreak(false, $footer);
		$pdf->SetFont('', 'B', 15);
		$pdf->MultiCell(180, 8, 'Attestation individuelle relative à l’utilisation d’un logiciel ou d’un système de caisse sécurisé',0,'L');
		$pdf->SetFont('', '', 9);
		$pdf->MultiCell(180, 6, 'Référence : '.$object->ref.' — Modèle BOI-LETTRE-000242 du 25 mars 2026',0,'L');
		$pdf->Ln(4);
		$e = static function ($text) { return dol_escape_htmltag((string)$text); };
		// Use one loaded French Translate for all dates, including long date variants.
		$date = static function ($timestamp) use ($french) { return (int)$timestamp > 0 ? dol_print_date((int)$timestamp, 'day', false, $french) : '________________'; };
		$html = '<h3>I. Volet 1 — Éditeur ou intégrateur</h3>';
		$html .= '<p>Je soussigné, <b>'.$e($data['signer']).'</b>, '.$e($data['signer_role']).', représentant légal de la société <b>'.$e($data['issuer_name']).'</b> ('.$e($data['issuer_id']).'), '.$e($data['issuer_address']).', '.$e($data['issuer_zip']).' '.$e($data['issuer_town']).', éditeur ou intégrateur du logiciel <b>Dolibarr ERP CRM</b>, atteste que les fonctionnalités de caisse de ce logiciel, mis sur le marché à compter du <b>'.$date($data['date_release']).'</b>, dans sa version <b>'.$e($data['version']).'</b>'.($data['license_number'] !== '' ? ', sous le numéro de licence '.$e($data['license_number']) : '').', satisfont aux conditions d’inaltérabilité, de sécurisation, de conservation et d’archivage des données en vue du contrôle de l’administration fiscale, prévues au 3° bis du I de l’article 286 du code général des impôts.</p>';
		$html .= '<p><b>Bénéficiaire :</b> '.$e($data['client_name']).' ('.$e($data['client_id']).'), '.$e($data['client_address']).', '.$e($data['client_zip']).' '.$e($data['client_town']).'.</p>';
		$html .= '<p><b>Périmètre couvert :</b><br>'.nl2br($e($data['covered_scope'])).'</p>';
		$html .= '<p><b>Fonctionnalités non couvertes :</b><br>'.nl2br($e($data['excluded_scope'] !== '' ? $data['excluded_scope'] : 'Aucune exclusion déclarée par l’émetteur.')).'</p>';
		$html .= '<p>Fait à '.$e($data['city']).', le '.$date($data['date_attestation']).'.</p><p>Signature du représentant légal de l’émetteur :</p>';
		$this->writeBlocks($pdf, $html, $object, $french, $footer);
		if ($pdf->GetY() + 30 > 297 - $footer) { $this->_pagefoot($pdf, $object, $french, 1); $pdf->AddPage(); }
		$pdf->Image($dir.'/private/signature.png', 18, $pdf->GetY(), 45, 22, 'PNG');
		$pdf->SetY($pdf->GetY()+26);
		$this->writeBlocks($pdf, '<p>L’établissement d’une fausse attestation est un délit pénal passible de trois ans d’emprisonnement et de 45 000 € d’amende (article 441-1 du code pénal). L’usage d’une fausse attestation est passible des mêmes peines.</p>', $object, $french, $footer);
		$this->_pagefoot($pdf, $object, $french, 1);
		$pdf->AddPage();
		$pdf->SetY(15);
		$html = '<h2>II. Volet 2 — Entreprise utilisatrice</h2><p>Attestation '.$e($object->ref).' — Dolibarr ERP CRM '.$e($data['version']).'</p>';
		$html .= '<p>Je soussigné, <b>'.$e($data['client_representative'] !== '' ? $data['client_representative'] : '________________________________').'</b>, représentant légal de la société <b>'.$e($data['client_name']).'</b> ('.$e($data['client_id']).'), '.$e($data['client_address']).', '.$e($data['client_zip']).' '.$e($data['client_town']).', certifie avoir acquis ou téléchargé le <b>'.$date($data['date_acquisition']).'</b>, auprès de <b>'.$e($data['issuer_name']).'</b>, le logiciel mentionné au volet 1 de cette attestation.</p>';
		$html .= '<p>J’atteste utiliser ce logiciel pour enregistrer les règlements de mes clients particuliers, conformément à la réglementation fiscale en vigueur, depuis le <b>'.$date($data['date_start']).'</b>.</p><p>Fait à ________________________________, le __________________.</p><p>Signature du représentant légal de l’entreprise utilisatrice :</p><br><br><br><br>';
		$html .= '<p><b>Les volets 1 et 2 doivent être présentés ensemble à l’administration fiscale. L’attestation n’a de valeur que si le volet 2 est dûment complété et signé par l’entreprise utilisatrice.</b></p>';
		$html .= '<p>L’établissement d’une fausse attestation est un délit pénal passible de trois ans d’emprisonnement et de 45 000 € d’amende (article 441-1 du code pénal). L’usage d’une fausse attestation est passible des mêmes peines.</p>';
		$this->writeBlocks($pdf, $html, $object, $french, $footer);
		$this->_pagefoot($pdf, $object, $french, 0);
		$file = $dir.'/'.$object->ref.'.pdf';
		$pdf->Output($file, 'F');
		if (!is_file($file) || filesize($file) <= 0) { $this->error = 'ErrorFailedToCreateFile'; return 0; }
		dolChmod($file);
		if ($object->indexFile($file, 1) < 0) { $this->error = $object->error; return 0; }
		return 1;
	}

	/** Flow textual blocks with native font measurements. Each page is finalized
	 * before adding the next one, including long scope paragraphs. No final pass.
	 */
	private function writeBlocks($pdf, $html, $object, $french, $footer)
	{
		$blocks = preg_split('~(?=<(?:p|h[123])(?:>|\s))~u', $html);
		foreach ($blocks as $block) {
			$heading = preg_match('~^<h[123]~', $block) === 1;
			$pdf->SetFont(pdf_getPDFFont($french), $heading ? 'B' : '', $heading ? 12 : 10);
			$text = dol_html_entity_decode(dol_string_nohtmltag(preg_replace('~<br\s*/?>~i', "\n", $block)), ENT_QUOTES | ENT_HTML5, 'UTF-8', 1);
			foreach (explode("\n", trim($text)) as $paragraph) {
				$line = '';
				$words = preg_split('/\s+/u', $paragraph);
				$lines = array();
				foreach ($words as $word) {
					$candidate=$line === '' ? $word : $line.' '.$word;
					if ($line !== '' && $pdf->GetStringWidth($candidate)>175) { $lines[]=$line; $line=''; }
					if ($pdf->GetStringWidth($word)>175) {
						foreach (preg_split('//u',$word,-1,PREG_SPLIT_NO_EMPTY) as $character) {
							if ($pdf->GetStringWidth($line.$character)>175) { $lines[]=$line; $line=''; }
							$line.=$character;
						}
					} else { $line.=($line === '' ? '' : ' ').$word; }
				}
				if (trim($line)!=='') { $lines[]=rtrim($line); }
				foreach ($lines as $line) {
					$height = $pdf->getStringHeight(180, $line) + 0.5;
					if (strpos($line,'Signature du représentant légal de l’entreprise utilisatrice') === 0) { $height=max($height,35); }
					if ($pdf->GetY()+$height > 297-$footer) { $this->_pagefoot($pdf,$object,$french,1); $pdf->AddPage(); $pdf->SetY(15); $pdf->SetFont(pdf_getPDFFont($french),$heading?'B':'',$heading?12:10); }
					$pdf->MultiCell(180,$height,$french->convToOutputCharset($line),0,'L');
				}
			}
			$pdf->Ln(3);
		}
		$pdf->SetFont(pdf_getPDFFont($french),'',10);
	}

	/** Native footer with automatic page breaks temporarily disabled. */
	protected function _pagefoot(&$pdf, $object, $outputlangs, $hidefreetext = 0)
	{
		$break = $pdf->getAutoPageBreak();
		$margin = $pdf->getBreakMargin();
		$pdf->SetAutoPageBreak(false, 0);
		$height = pdf_pagefoot($pdf, $outputlangs, 'MAIN_PDF_FREETEXT', $this->emetteur, 15, 15, 297, $object, 1, $hidefreetext, 210);
		$pdf->SetAutoPageBreak($break, $margin);
		return $height;
	}
}
