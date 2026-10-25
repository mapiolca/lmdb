<?php
/* Test-only configuration; no instance is bootstrapped and no DB is opened. */
define('DOL_DOCUMENT_ROOT', getenv('DOLIBARR_PATH'));
define('DOL_URL_ROOT', '');
define('DOL_DATA_ROOT', __DIR__.'/.runtime/documents');
define('DOL_VERSION', '24.0.1');
define('MAIN_DB_PREFIX', 'fixture_');
