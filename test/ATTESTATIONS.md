# Validation des attestations — LMDB 2.0.0

## Périmètre

Objet, droits, entité et accès commercial au tiers, SQL, numérotation, instantané légal, PDF, fichiers joints, courriel individuel, Agenda/Notifications, menus, export et API. Aucun changement core. ID existant **450050**, famille conservée ; aucun nouvel ID de module attribué. Catégories, import, liens publics, consentement en ligne et nouveau cron : non applicables.

## Contrôles locaux du 24 octobre 2026

PHP **8.5.7**, sources natives Dolibarr **24.0.1**, révision `029a92fb538e90db684dec8b6ba80ee5a6638efd`.

```sh
php test/attestation_native_regression.php /chemin/htdocs --pdf
php test/attestation_native_regression.php /chemin/htdocs --pdf --stress
DOLIBARR_PATH=/chemin/htdocs python3 test/run_phpstan.py
php test/invoice_translation_regression.php /chemin/htdocs
```

- Objet et `CommonObject` réels avec adaptateur SQLite en mémoire : accès, refus administrateur sans droit, instantané, refus d’une suppression avec statut falsifié, chemins documentaires et absence de repli d’entité.
- Réservation de courriel avant transport, état pending, rejeu, échec de début/commit et rollback. Aucun transport SMTP ni destinataire réel utilisés.
- `dol_eval()` natif sur les trois menus, avec et sans droits ; `HookManager` natif avec appels successifs Notifications/modèles et réinitialisation des résultats.
- TCPDF natif : PDF normal à deux volets, accents et signature SPECIMEN ; document long avec texte libre HTML et résultat de hook de pied simulé. Inspection visuelle des pages rendues avec Poppler. Indexation ECM simulée dans cette fixture.
- PHPStan **2.3.1, niveau 5**, cible syntaxique PHP 8.0, déclarations core réelles, sans ignore ni baseline. Le stub Multicompany sert uniquement à l’analyse statique en l’absence des sources du module tiers localement.
- Syntaxe de tous les PHP ; non-régression des traductions FR/EN et onze scénarios simulés de la frontière v23/v24 pour l’envoi de factures.

SQLite retire `FOR UPDATE` : ces essais ne prouvent pas les verrous ni l’imbrication transactionnelle MySQL/MariaDB ou DebugBar. PHP 8.0 n’a pas été exécuté. Le téléchargement de PHPStan reste un cache local ignoré sous `test/.runtime/`.

## Lecture des contrats core

Révisions figées examinées :

| Version | Révision | Nature |
|---|---|---|
| 20.0.0 | `697bf01970740a3339cd99cf055b4428fc5e051c` | Lecture de sources |
| 21.0.0 | `fd970b582a4d8c5779a2958a4e9f4fce225cf085` | Lecture de sources |
| 22.0.0 | `49b9a6d19f3deb6d410c0e9b3310e95be4ea7710` | Lecture de sources |
| 23.0.0 | `57a1f05d490a7a80944a8232e9c613e6556d2704` | Lecture de sources |
| 24.0.0 | `769c7db907099643558e77d7002c109cfda919e5` | Lecture de sources |
| develop (cible v25, préversion) | `ad739a0949abc00782dc8d578315d7c54a414c9e` | Lecture de sources, aucune preuve de version finale |

Contrats examinés dans `htdocs/core/` : `CommonObject` (CRUD, validation, indexation/génération), `FormFile::showdocuments()` et `form_attach_new_file()`, `FormMail`, `functions.lib.php::getMultidirOutput()`, `files.lib.php::checkSecureAccess`/drag-and-drop, `FileUpload::overrideUploadOptions`, `pdf_pagefoot()`, contrôleur `actions_sendmails.inc.php`, branche générique `ActionsAuto` (`actionmsg`/`actionmsg2`) et cycle de `HookManager`. Les signatures documentaires employées sont présentes sur ces six révisions. Présence et lecture de source ne constituent pas des essais d’instances v20–v25.

## Recette distante

Déploiement du code effectué via le navigateur intégré et cPanel ControlGit : dépôt **DEVELOP - LMDB**, branche `feat/attestations-conformite-loi-finance`, dans le répertoire du module servi par develop. L’état initial était la branche `fix/v24-autosend-invoice-disabled`, commit `02be1b77fda5fd41cea9f5f7c589fa2cd184d581`. La bascule de branche a été confirmée par le SHA affiché dans cPanel. Le dépôt étant directement dans le répertoire servi, aucune tâche `.cpanel.yml` supplémentaire n’est nécessaire.

La session Dolibarr **25.0.0-alpha + Multicompany** a expiré avant l’activation et la recette ; reconnexion utilisateur demandée. Aucune activation, modification de réglages métier, création d’attestation distante ni émission de courriel n’a été effectuée. La version PHP et la révision core réellement exécutées sur develop restent à relever après connexion. Le checkout cPanel ne vaut pas preuve d’un parcours HTTP fonctionnel : aucun essai distant du nouveau code n’est revendiqué. La branche de recette est conservée sur develop.

À couvrir : activation/réactivation sans perte des réglages, catalogue, brouillon/édition/validation, génération/régénération, lecture/téléchargement, upload PDF/MIME, Agenda configurable sans doublon et lien résoluble, modèles de courriel et aperçu, absence d’envoi réel, pagination/filtres/colonnes, CSRF, comptes avec droits partiels et deux entités Multicompany. Tester aussi API et export avec leurs permissions dédiées. Conserver l’état initial des réglages modifiés temporairement.
