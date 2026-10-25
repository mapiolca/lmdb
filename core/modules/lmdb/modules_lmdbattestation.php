<?php
/* Copyright (C) 2026 Pierre Ardoin. SPDX-License-Identifier: GPL-3.0-or-later */
require_once DOL_DOCUMENT_ROOT.'/core/class/commondocgenerator.class.php';
/** Native FormFile document-model contract for the custom element. */
abstract class ModelePDFLmdbattestation extends CommonDocGenerator
{
	public static function liste_modeles($db, $maxfilenamelength = 0)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
		return getListOfModels($db, 'lmdbattestation', $maxfilenamelength);
	}
}
