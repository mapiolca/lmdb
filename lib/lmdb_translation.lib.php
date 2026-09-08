<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Restore invoice translations when a cron cleared the dictionary but kept
 * Translate's private list of loaded domains. Mutate the supplied object so
 * native PDF hooks, which cannot replace the caller's translator, benefit too.
 *
 * Native loading retains entity-specific database overrides and language
 * fallbacks. Existing runtime translations and output settings are preserved.
 *
 * @param Translate $outputlangs Output language object
 * @return void
 */
function lmdbLoadInvoiceTranslations(Translate $outputlangs)
{
	global $conf;

	$freshlangs = new Translate('', $conf);
	$freshlangs->setDefaultLang($outputlangs->getDefaultLang());
	$freshlangs->dir = $outputlangs->dir;
	$freshlangs->loadLangs(array('main', 'bills', 'products', 'dict', 'companies', 'compta', 'projects', 'other', 'lmdb@lmdb'));
	foreach ($freshlangs->tab_translate as $key => $translation) {
		if (!isset($outputlangs->tab_translate[$key]) || $outputlangs->tab_translate[$key] === '' || $outputlangs->tab_translate[$key] === $key) {
			$outputlangs->tab_translate[$key] = $translation;
		}
	}
}
