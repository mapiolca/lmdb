<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file       htdocs/custom/lmdb/core/modules/facture/doc/pdf_lmdbsponge.modules.php
 * \ingroup    lmdb
 * \brief      LMDB invoice PDF model based on Dolibarr sponge model.
 */

$lmdbPdfLib = function_exists('dol_buildpath') ? dol_buildpath('/lmdb/lib/lmdb_pdf.lib.php', 0) : DOL_DOCUMENT_ROOT.'/custom/lmdb/lib/lmdb_pdf.lib.php';
require_once $lmdbPdfLib;

$coreSpongeFile = DOL_DOCUMENT_ROOT.'/core/modules/facture/doc/pdf_sponge.modules.php';
if (file_exists($coreSpongeFile)) {
	require_once $coreSpongeFile;
}

if (class_exists('pdf_sponge')) {
	/**
	 * LMDB Sponge invoice PDF model.
	 */
	class pdf_lmdbsponge extends pdf_sponge
	{
		/**
		 * Constructor.
		 *
		 * @param DoliDB $db Database handler
		 */
		public function __construct($db)
		{
			global $langs;

			parent::__construct($db);

			$langs->loadLangs(array('lmdb@lmdb'));
			$this->name = 'lmdbsponge';
			$this->description = $langs->trans('PDFLmdbSpongeDescription');
		}

		/**
		 * Build PDF file on disk.
		 *
		 * @param Facture   $object          Invoice object
		 * @param Translate $outputlangs     Output language
		 * @param string    $srctemplatepath Source template path
		 * @param int       $hidedetails     Hide details
		 * @param int       $hidedesc        Hide description
		 * @param int       $hideref         Hide reference
		 * @param mixed     $moreparams      More parameters
		 * @return int 1 if OK, 0 if KO
		 */
		public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0, $moreparams = null)
		{
			global $langs;

			if (!is_object($outputlangs)) {
				$outputlangs = $langs;
			}

			$outputlangs->loadLangs(array('main', 'bills', 'products', 'dict', 'companies', 'lmdb@lmdb'));
			lmdbPdfApplyTranslationFallbacks($outputlangs);

			$method = new ReflectionMethod('pdf_sponge', 'write_file');
			if ($method->getNumberOfParameters() >= 7) {
				return parent::write_file($object, $outputlangs, $srctemplatepath, $hidedetails, $hidedesc, $hideref, $moreparams);
			}

			return parent::write_file($object, $outputlangs, $srctemplatepath, $hidedetails, $hidedesc, $hideref);
		}
	}
} else {
	require_once DOL_DOCUMENT_ROOT.'/core/modules/facture/modules_facture.php';

	/**
	 * Fallback class when core sponge model is unavailable.
	 */
	class pdf_lmdbsponge extends ModelePDFFactures
	{
		/**
		 * @var DoliDB Database handler
		 */
		public $db;

		/**
		 * @var string Model name
		 */
		public $name;

		/**
		 * @var string Model description
		 */
		public $description;

		/**
		 * @var string Document type
		 */
		public $type;

		/**
		 * @var string Error message
		 */
		public $error = '';

		/**
		 * Constructor.
		 *
		 * @param DoliDB $db Database handler
		 */
		public function __construct($db)
		{
			global $langs;

			$this->db = $db;
			$langs->loadLangs(array('lmdb@lmdb'));
			$this->name = 'lmdbsponge';
			$this->description = $langs->trans('PDFLmdbSpongeDescription');
			$this->type = 'pdf';
		}

		/**
		 * Build PDF file on disk.
		 *
		 * @param Facture   $object          Invoice object
		 * @param Translate $outputlangs     Output language
		 * @param string    $srctemplatepath Source template path
		 * @param int       $hidedetails     Hide details
		 * @param int       $hidedesc        Hide description
		 * @param int       $hideref         Hide reference
		 * @param mixed     $moreparams      More parameters
		 * @return int 0 because the core model is missing
		 */
		public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0, $moreparams = null)
		{
			$this->error = 'Core sponge PDF model was not found';
			return 0;
		}
	}
}
