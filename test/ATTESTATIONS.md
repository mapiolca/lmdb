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

Instance constatée : **Dolibarr 25.0.0-alpha, PHP 8.3.36, Multicompany actif**, entité TEST 1. La révision du core et la version du module tiers Multicompany ne sont pas connues. Le dernier code fonctionnel déployé et vérifié est `64c5b8e90aca584b81b4adf8c5e5f78f36330149` ; les mises à jour documentaires suivantes ne changent pas ces résultats.

Essais réels du 24 octobre 2026, dans le navigateur intégré :

- Activation initiale, puis rechargement natif du module : paramètres existants facture/emailing conservés, configuration de recette et données conservées. Ce rechargement ne prouve pas un cycle complet désactivation/réactivation dans deux entités.
- Catalogue créé avec la version fictive `SPECIMEN-RECETTE-20261024`, datepicker natif, création et validation de l’attestation 1, référence finale `ATLF-2026-0001`, instantané figé. Ancien brouillon de préversion migré en `(PROV1)` ; nouveau brouillon `(PROV2)` observé.
- Confirmations en modales natives ; annulation du spécimen avec historique conservé. Action de suppression sans token refusée par le core avant mutation. Suppression du second brouillon avec confirmation native et token fonctionnelle.
- PDF généré, régénéré et téléchargé : TCPDF 6.11.4, deux pages A4, accents, logo, en-têtes natifs sur chaque volet, signature SPECIMEN. Les deux pages du fichier réellement téléchargé ont été rendues et inspectées avec Poppler.
- Formulaire classique de dépôt absent de la fiche et présent dans l’onglet Fichiers joints ; conteneur natif de glisser-déposer présent sur les onglets vérifiés. Dépôt classique d’un PDF accepté, faux PDF contenant un PNG refusé par le contrôle MIME, suppression du fichier de dépôt par confirmation native. Le geste de glisser-déposer lui-même reste à tester manuellement.
- Agenda UPDATE temporairement activé dans l’administration native : événement de validation 2624, un événement par génération/régénération/dépôt (2625 à 2628), aucun ajout par simple rafraîchissement. Lien natif vers l’attestation et le tiers résolu, sans « Objet supprimé ». Les événements de recette sont conservés.
- Modèle de courriel du type exact `lmdbattestation_send` : sujet et contenu substitués, expéditeur renseigné, PDF proposé en pièce jointe. Aperçu uniquement, aucun envoi réel ni test SMTP. Le blocage global des mails était actif pendant ces essais.
- Liste des attestations et onglet Agenda : changement natif 20 → 50, select de limite dans son formulaire, filtre de statut, état vide et réinitialisation. Correctif du chargement des entités vérifié avec le `DaoMulticompany` réellement installé, dont le fichier a été lu dans cPanel.
- Logo société affiché dans la liste des modules : `img/lmdb.png` chargé (596 × 334), module LMDB 2.0.0 activé. Le fichier `img/object_lmdb.png` couvre également les résolutions natives des autres versions.

État final : **LMDB reste activé sur develop à la demande de l’utilisateur**. Le spécimen 1 est annulé, sa version catalogue désactivée. Les quatre valeurs fictives de signataire, fonction, ville et signature ont été vidées par l’interface native (constantes conservées avec chaîne vide) ; le masque et le modèle PDF restent inchangés. La signature de recette privée et le PDF historique du spécimen sont conservés. L’auto-événement UPDATE est revenu à son état initial désactivé ; CREATE et DELETE sont restés désactivés. Le blocage global temporaire des emails a été retiré pour retrouver l’état initial. Aucun courriel n’a été envoyé.

Restent non exécutés sur instance : comptes avec droits partiels, API et export, partages et documents entre deux entités, validations concurrentes MySQL/MariaDB, Notifications effectivement envoyées et cycle complet de conservation de configuration. Les contrôles locaux simulés ne remplacent pas ces essais. La matrice d’instances v20–v25 et PHP 8.0 reste à couvrir avant une validation générale de compatibilité. La PR reste en brouillon.

## Complément de recette — blocs de réglage natifs

Correctif `64c5b8e` du 24 octobre 2026, même instance develop et même PHP que ci-dessus :

- Numérotation, puis Modèles de document, affichés en tête des réglages. Colonnes natives, aide du masque, état du modèle et choix par défaut ; suppression du masque redondant dans les paramètres de l’émetteur.
- Masque `REC{yy}{mm}-{00000}` enregistré par le nouveau formulaire, exemple `REC2610-00001`, persistance après rechargement. Masque `SANS-COMPTEUR` refusé avec « Erreur sur le masque », sans écraser la valeur enregistrée. Masque initial `ATLF-{yyyy}-{0000}` restauré, exemple `ATLF-2026-0002` tenant compte du spécimen conservé.
- Modèle PDF désactivé puis réactivé par son interrupteur ; état final actif et modèle par défaut inchangé. Aucun objet créé ni courriel envoyé pendant ce complément de recette. LMDB reste activé.
- Syntaxe PHP, PHPStan niveau 5 et régression locale réussis. Quatre scénarios supplémentaires exécutent `get_next_value()` natif avec SQLite : masque date/compteur, aperçu répété sans réservation/verrou, code du tiers et exclusion d’une entité inaccessible. Ces scénarios ne prouvent pas les verrous MySQL ni un partage Multicompany réel.
- Pattern lu dans `htdocs/admin/propal.php` et `core/modules/propale/mod_propale_saphir.php` des sources locales Dolibarr 24.0.1 ; contrat de masque et API également lus au tag 20.0.0 (`697bf01970740a3339cd99cf055b4428fc5e051c`). Les anciennes traductions incluent déjà les codes date dans `GenericMaskCodes`, ce qui évite d’afficher la clé récente `GenericMaskCodes1` brute. Aucun helper natif recopié dans le module.
