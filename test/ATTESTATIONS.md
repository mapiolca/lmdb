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

Instance constatée : **Dolibarr 25.0.0-alpha, PHP 8.3.36, Multicompany actif**, entité TEST 1. La révision du core et la version du module tiers Multicompany ne sont pas connues. Le dernier code fonctionnel déployé et vérifié est `d457e164a2a255f062ce0a1772a9f998641c0881` ; les scénarios sont rattachés à leurs révisions ci-dessous. Les mises à jour documentaires suivantes ne changent pas ces résultats.

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

## Complément de recette — Standard fixe, Advanced configurable

Correctif `efeeefc` du 24 octobre 2026, même instance develop et même PHP :

- Standard est figé sur `ATLF-{yyyy}-{0000}` et Advanced utilise le masque enregistré. Les deux modèles ont un exemple calculé par le core ; seul Advanced présente un champ éditable. Les régressions locales vérifient que le masque Advanced ne modifie pas Standard et que l’objet appelle le modèle sélectionné.
- Les interrupteurs des modèles reprennent les liens et pictogrammes `switch_on` / `switch_off` de l’administration native des modèles Dolibarr, sans bouton HTML encadré. `ajax_constantonoff()` concerne les constantes ; l’activation documentaire continue à utiliser `addDocumentModel()` / `delDocumentModel()` et la table native, sans constante miroir ni Ajax parallèle.
- Avant le déploiement, masque temporaire `REC-{yy}-{00000}` enregistré dans l’ancien modèle configurable. Après déploiement et rechargement natif LMDB : Advanced sélectionné et masque conservé, exemple `REC-26-00001`. Standard affiche toujours `ATLF-2026-0002`.
- Sélection manuelle de Standard, puis deuxième rechargement natif : Standard reste sélectionné malgré le masque Advanced personnalisé. La migration est unique, marquée par la constante d’entité `LMDB_ATTESTATION_NUMBERING_SPLIT`, et ne réinterprète pas les choix suivants. Masque Advanced initial `ATLF-{yyyy}-{0000}` ensuite restauré.
- Interrupteur documentaire testé avec jeton : désactivation puis réactivation réussies. Action directe `disablepdf` sans jeton refusée par la protection native `CSRFCHECK_WITH_TOKEN` avant mutation. Aucun bouton HTML dans la ligne du modèle documentaire. État final : Standard sélectionné, Advanced désactivé, PDF actif et par défaut, LMDB actif. Aucun courriel ni objet métier créé.
- Syntaxe, PHPStan niveau 5 et régression native locale réussis ; trois scénarios supplémentaires couvrent la séparation des modèles et leur sélection par l’objet. Les limites MySQL/Multicompany et matrice de versions indiquées plus haut restent applicables.


## Complément de recette — catalogue, pied de modale et champs requis

Code `114f4a604142c70f8a8d89872df9dd4af83fb247`, vérifié le 24 octobre 2026 sur la même instance develop. SHA du checkout servi confirmé dans cPanel ControlGit, puis comportement du nouveau code observé dans le navigateur intégré.

- Catalogue : bouton **+** en haut à droite, ajout et édition en modales jQuery UI. Boutons Enregistrer/Annuler présents dans `.ui-dialog-buttonpane`, hors du corps du formulaire ; enregistrement POST protégé par token puis redirection. Calendrier natif utilisé. Réouvertures, annulation et croix de fermeture testées. Sans Ajax, le formulaire et ses boutons classiques restent proposés ; ce repli n’a pas été exécuté dans le navigateur.
- Soumission vide refusée avec les trois messages **Version attestée : Est requis**, **Date de mise sur le marché : Est requis**, **Fonctionnalités couvertes : Est requis**. Les erreurs proviennent de `CommonObject::validateField()` et restent associées aux champs. Version/date conservées après une précédente soumission incomplète. Même contextualisation appliquée à la validation de la fiche attestation ; ce dernier parcours est analysé localement, sans nouvelle création distante.
- Création du catalogue fictif `SPECIMEN-MODALE-20261024` (id 2), initialement désactivé, date 24/10/2026 et périmètre long avec accents. Édition réouverte avec les valeurs persistées ; texte couvert de **2 952 caractères** conservé et texte exclu modifié puis relu. Correctif de portée de la variable du formulaire vérifié : la modification n’ouvre plus un ajout vide.
- Interrupteur natif sans cadre : activation puis désactivation réussies, contenu conservé. `ajax_constantonoff()` n’est pas utilisé pour cette colonne métier ; aucune constante miroir ajoutée.
- Corbeille : demande de suppression de la version 1 déjà utilisée refusée immédiatement, sans modale ni suppression. Une version inutilisée ouvre `Form::formconfirm()` en modale native ; **Non** testé. Aucune suppression définitive de catalogue exécutée sur develop.
- Régression locale avec `CommonObject::isObjectUsed()` réel et SQLite : refus pour versions utilisées par attestations validées, annulées ou brouillons, refus sans droit ou hors entité, suppression d’une entrée inutilisée et conservation d’un fichier sentinelle du module. Les écritures d’attestation et la suppression du catalogue verrouillent la version ; la concurrence réelle MySQL/MariaDB reste à tester.
- Syntaxe des **41 fichiers PHP**, PHPStan **2.3.1 niveau 5**, régression native avec PDF : réussis sur le code indiqué. Les quatre nouveaux contrôles de validation vérifient le regroupement des trois champs manquants et leurs libellés. Aucun ignore ni baseline ajouté. Contrats natifs de détection des dépendances et `getNonce()` lus sur le socle v20 ; cela ne constitue pas une recette v20 complète.

État final : LMDB actif ; les deux versions fictives du catalogue sont conservées désactivées. Le spécimen d’attestation reste annulé. Standard sélectionné, masque Advanced initial conservé, modèle PDF actif/par défaut. Aucun email envoyé ni réglage global d’email ou d’Agenda modifié pendant ce complément.


## Complément de recette — ville de l’entité, ajout et compatibilité

Code `c9dc4f7` puis `efc84a38bd39b41d154c6c13bfac366a34465314`, même instance develop et même PHP, le 24 octobre 2026 :

- Ville de signature lue directement dans `$mysoc->town`, alimenté par la constante native d’entité `MAIN_INFO_SOCIETE_TOWN`. Les réglages affichent **Ville : Là-bas**, en lecture seule, sans champ de saisie ni constante miroir. La ville est figée dans l’instantané à la validation ; les anciens instantanés et la constante LMDB historique sont conservés. Nouvelle validation distante et changement d’entité non exécutés dans ce complément.
- Bouton **+** rendu par `dolGetButtonTitle()` : classes natives `btnTitle btnTitlePlus` et `fa fa-plus-circle valignmiddle btnTitle-icon` observées. Ouverture de la modale puis Annuler testés, sans création supplémentaire.
- Page Compatibilité : `dolGetBadge()` fournit les badges verts **Disponible** et jaunes **Indisponible**. Cellule Raison vide pour les attestations disponibles ; motif natif conservé pour l’envoi LMDB des factures récurrentes indisponible dès v24. Aucun prédicat de disponibilité modifié.
- SHA servi confirmé dans ControlGit et comportement du code déployé observé après rechargement. Syntaxe PHP, régression native avec PDF et PHPStan 2.3.1 niveau 5 réussis ; la page Compatibilité est désormais incluse explicitement dans l’analyse. Aucun ignore ni baseline ajouté.
- Contrats de `Societe::setMysoc()`, `dolGetButtonTitle()` et `dolGetBadge()` lus dans les sources locales v24.0.1 et au tag v20.0.0 (`697bf01970740a3339cd99cf055b4428fc5e051c`). Cette lecture ne constitue pas une recette sur toutes les versions supportées.

LMDB reste activé, versions fictives désactivées et réglages d’email/Agenda conservés. Aucun courriel envoyé ni donnée supprimée pendant ces vérifications.

## Complément de recette — consultation, PDF et fiche native

Code `714c567390fbb3d9e8efba0175e455102eb0bdf6`, puis `d457e164a2a255f062ce0a1772a9f998641c0881`, mêmes environnements que ci-dessus. SHA distant comparé au commit local, puis checkout servi confirmé dans cPanel ControlGit.

- Liste : désactivation temporaire de `MAIN_CHECKBOX_LEFT_COLUMN` dans les réglages natifs, commandes de recherche/réinitialisation et sélection des colonnes observées à droite ; réactivation et retour à gauche vérifiés. État initial **activé** restauré. L’onglet Agenda présente aussi la colonne de contrôle à gauche. En v20, lecture de la constante native ; à partir de v21, utilisation de `Conf::main_checkbox_left_column`, qui reprend aussi la préférence personnelle. La surcharge personnelle n’a pas été modifiée en recette.
- Infobulle : attributs natifs Ajax et appel réel à `core/ajax/ajaxtooltip.php` avec `lmdbattestation@lmdb`. Référence, statut, tiers, dates, version, représentant, licence, émetteur et périmètre figés relus ; adresse multilignes correctement rendue. Le déclenchement par survol réel n’est pas couvert par l’automatisation. Tests locaux : instantané utilisé plutôt que catalogue courant, HTML échappé, refus sans droit et hors entité.
- Fiche : disposition `fichecenter` / `fichehalfleft`, tableau `border centpercent tableforfield`, cellules `titlefield` / `valuefield`. Bordures et libellés natifs observés après déploiement ; ni tiers ni statut répétés sous la bannière, aucun CSS spécifique ajouté.
- Bannière : conversion réelle du PDF historique par `dol_convert_file()` natif ; miniature PNG de **595 × 842** chargée via `viewimage.php`, dans l’entité propriétaire. `Form::showphoto()` fournit le lien vers l’aperçu natif ; clic ouvrant sa modale avec le bon document. Le lecteur PDF intégré à cette modale reste noir dans le navigateur automatisé : l’affichage de son contenu n’est donc pas validé. La miniature elle-même est visible. Aucun PDF historique régénéré ni nouveau document légal créé pendant ce complément.
- Aperçu conditionné par Imagick et `MAIN_DISABLE_PDF_THUMBS`, avec motif dans Compatibilité et certificat de repli. La conversion peut aussi être refusée par Ghostscript ou la politique ImageMagick. Imagick n’est pas installé dans le PHP CLI local : conversion réelle vérifiée uniquement sur develop ; réglage de désactivation couvert localement.
- PDF : en-tête `_pagehead()` conservé ; ses encadrés émetteur/destinataire sont appelés uniquement en première page. Corps HTML TCPDF avec titres, paragraphes, mentions et espaces de signature ; découpage natif des textes longs. Pied natif `pdf_pagefoot()` avec réserve mesurée, détails société configurables et cycle intermédiaire/dernier pied. Aucun changement de l’instantané légal.
- Validation PDF locale : document normal de deux pages et stress de cinq pages ; texte libre vide/court/long avec retours à la ligne natifs, HTML et hook de pied, détails société activés/désactivés. Pages rendues avec Poppler et inspectées sans chevauchement. Une longue ligne de texte libre brut doit suivre le contrat natif : retours à la ligne manuels ou mode HTML. Aucun correctif du core ni promesse de prise en charge de texte brut arbitraire sans retour à la ligne. Le nouveau rendu PDF n’a pas été régénéré sur develop après ces commits, le spécimen existant étant annulé.
- Sur `d457e16` : **59 contrôles** réussis dans chaque exécution normale et stress de la régression native ; syntaxe des **41 PHP**, PHPStan **2.3.1 niveau 5** (cible PHP 8.0, zéro erreur, sans ignore/baseline), non-régression des traductions FR/EN réussis. Les doubles SQLite et les limites de matrice de versions restent ceux décrits plus haut. Aucun contrôle CI GitHub déclaré sur cette PR au moment de la vérification ; ce n’est pas une validation CI.

État final inchangé : module activé, deux versions fictives désactivées, spécimen annulé, configuration d’émetteur vide hors ville native, Standard et PDF par défaut conservés ; Agenda et messagerie inchangés. Aucun email envoyé ni donnée métier supprimée. Seule une miniature native dérivée du PDF historique a été créée.

## Complément — contacts et onglet documentaire complet

- Contacts : chargement explicite de `Contact`, rôles idempotents dans `c_type_contact`, listes et actions natives `element_contact`/`contacts.tpl.php`. Les contacts externes sont limités au tiers de l’attestation et aux entités accessibles, conformément à la réponse utilisateur. Les mutations appellent un seul UPDATE CRUD avec le contexte `contacts`, sans réécriture de l’instantané légal.
- Régression locale : ajout, doublon, activation/désactivation du lien, retrait, refus de tiers/entité/rôle étrangers, refus d’un identifiant de liaison appartenant à une autre attestation et d’un acteur sans droit. Un contact déplacé vers un autre tiers disparaît de la restitution sans suppression de sa liaison historique. Adaptateur PDO SQLite héritant du pilote natif ; ce montage ne prouve pas les verrous MySQL. Dispatcher de triggers natif chargé, sans Agenda/Notifications activés dans la fixture.
- Documents : répertoire propriétaire partagé par compteurs, liste complète `list_of_documents()`, liens `listOfLinks()` et actions natives. Les mutations de lien sont rattachées à l’objet ; les actions ECM/renommage sont limitées à son répertoire et ne peuvent renommer le PDF officiel. Génération et dépôt restent soumis au statut validé et aux droits correspondants ; lecture des fichiers conservée pour les objets annulés.
- Contrat des formulaires intégrés aux titres : présent dès 21.0.0, absent de 20.0.0 (révisions figées du tableau ci-dessus). Le contrôle centralisé de compatibilité conserve les formulaires natifs classiques en v20. PHPStan utilise un stub documentaire ciblé du paramètre `nooutput=2`, reconnu par le code natif v21+ mais mal décrit dans son PHPDoc v24 ; aucune erreur masquée.

## Recette PDF complémentaire du 24 octobre 2026

Code du modèle déployé `2fe6fff3c11b0fae4ab0e2138f8f947694ab0713`, develop Dolibarr 25.0.0-alpha / PHP 8.3.36 / TEST 1 / Multicompany actif. Génération et téléchargement dans le navigateur intégré, rendu local Poppler des PDF reçus, TCPDF 6.11.4.

- Spécimen 3, `ATLF-2026-0002` : deux pages A4, dates client laissées vides, caractères accentués, signature SPECIMEN et coordonnées figées. Régénération depuis Fichiers joints avec l’option native d’adresse dans le pied : adresse, téléphone et coordonnées ajoutés sur les deux pages.
- Spécimen 4, `ATLF-2026-0003` : périmètre de 2 952 caractères, trois pages A4, dates d’acquisition 20/10/2026 et d’utilisation 21/10/2026 conservées, accents ÀÉÈÇ, continuation du volet éditeur puis volet client distinct. Aucun encadré d’adresse répété après la première page ; titre/logo/référence/date conservés. Signatures et mentions ne chevauchent pas les pieds.
- Le réglage natif de détails de pied est revenu de 1 à sa valeur initiale 0. Les deux versions catalogue sont revenues inactives et les paramètres fictifs d’émetteur/signature ont retrouvé une valeur effective vide par l’administration native. L’asset de signature de test est conservé ; aucun courriel envoyé.

Les essais HTML/texte libre très long et hook de pied sont locaux ; aucun hook tiers de pied activé sur develop. Les essais de droits partiels et documents partagés entre deux entités restent non exécutés sur instance.

### Ajustements demandés pendant la recette

Le formulaire Contacts/Adresses est rendu intégralement par `core/tpl/contacts.tpl.php`, en-tête puis lignes d’ajout dans le même conteneur. Les hooks natifs `selectCompaniesForNewContactListWhere` et `addSQLWhereFilterOnSelectUsers` limitent les sélecteurs au tiers et aux utilisateurs accessibles. Le mode liste natif du tiers est forcé uniquement pendant ce rendu, puis le réglage en mémoire est restauré, car son ancien mode Ajax n’accepte pas de restriction par objet. Les deux hooks sont présents en v20.0.0 et v24.0.1 ; aucune réécriture de template ou de sélecteur.

À la demande de l’utilisateur, le choix de modèle et Générer sont retirés de l’onglet Fichiers joints et conservés sur la fiche principale.

Préchargement à l’installation : familles `23.0` (2026-02-28) et `24.0` (2026-08-20), dates de première publication selon [le wiki officiel](https://wiki.dolibarr.org/index.php/Releases), consulté le 24 octobre 2026. La date GitHub de création d’une release peut différer de la date de publication du wiki (cas 23.0.0). Le périmètre couvert reste vide, l’entrée inactive et son activation est refusée tant qu’il n’est pas renseigné. Aucun fait de certification n’est déduit de la publication du logiciel. La préversion 25.0.0-alpha ne permet pas de précharger une version finale ni un périmètre figé.

Six contrôles locaux supplémentaires vérifient le double passage, les deux versions uniques, le refus d’activation d’un périmètre vide, la conservation d’une entrée personnalisée, l’isolement par entité et l’absence de 25.0. Les insertions sont transactionnelles.

### Résultat distant final — code fonctionnel `dd2efcfe4c96ea0a78b8665db8108afd8d31a85c`

SHA confirmé dans cPanel ControlGit puis comportement modifié confirmé dans le DOM de develop. Rechargement natif après fin du pull : catalogue 23.0/24.0 créé, aucun doublon de rôles, émetteur vide, catalogue fictif inactif, Standard et modèle PDF conservés. Le clic sur l’interrupteur de 23.0 sans périmètre affiche « Fonctionnalités couvertes : Est requis » et laisse l’entrée inactive.

- Formulaire Contacts : en-tête puis lignes d’ajout dans le conteneur natif. Le sélecteur tiers propose uniquement TEST 1 ; ajout du responsable Codex, désactivation/réactivation de sa liaison puis ajout du destinataire fictif `Recette SPECIMEN CONTACT ATTESTATION` (contact 36, `attestation-recette@example.invalid`). Ce destinataire apparaît dans FormMail avec le tiers et le PDF ; aucun envoi. Le contact natif et les deux liaisons de recette sont conservés.
- Fichiers joints : compteur et taille, liste et tri natifs, ouverture de l’édition, enregistrement du même nom sans partage public, lien fictif `https://example.invalid/recette-attestation` enregistré. Bouton + ouvrant le sélecteur natif, dépôt classique du PDF de recette accepté ; compteur passé de 1 à 2 et taille totale 226 Ko. Le nouveau fichier `ATLF-2026-0003-normal-initial.pdf` et le lien sont conservés. Aucun bloc de génération dans l’onglet ; choix de modèle et Générer conservés sur la fiche. Le geste de dépôt par glisser-déposer reste non exécuté.
- Régénération des deux PDF depuis la fiche après restauration de l’émetteur et des détails de pied : 2 et 3 pages A4, signature et données figées conservées. Les contacts liés après validation n’écrasent pas le représentant légal figé. Les fichiers téléchargés ont été rendus et inspectés ; extraction des positions confirmant les en-têtes de toutes les pages et le corps hors de la zone de pied.
- Spécimens 3 (`ATLF-2026-0002`) et 4 (`ATLF-2026-0003`) annulés par modale native, documents conservés et lisibles. Les deux versions fictives restent inactives. Les versions préchargées 23.0 et 24.0 sont inactives, avec périmètre à compléter. Aucun ajout 25.0. Les valeurs effectives de signataire/fonction/signature sont vides ; la ville reste celle de l’entité, et le réglage de détails de pied vaut 0. Les assets privés de recette sont conservés.
- Le blocage global des emails a été remis à son état initial désactivé après les essais. Aucun courriel envoyé. Les réglages Agenda sont restés désactivés ; aucun événement automatique de recette supplémentaire attendu dans ce complément. LMDB reste activé.

Vérifications finales locales sur `dd2efcf` : **78 contrôles par exécution normale et stress**, PHPStan **niveau 5 / cible PHP 8.0 sans erreur**, syntaxe des **42 PHP**. Régression factures FR/EN réussie. Aucune exécution GitHub Actions trouvée pour ce SHA ; les résultats locaux ne sont pas présentés comme une CI.

## Complément du 25 octobre 2026 — périmètres 23.0 et 24.0

À la demande de l’utilisateur, les deux familles reçoivent désormais un périmètre fonctionnel de référence et des exclusions. [Sources figées, texte et limites](ATTESTATION_SCOPES.md). Ce complément remplace le préremplissage vide décrit dans la recette du 24 octobre, sans modifier les instantanés ni les textes personnalisés. Aucune activation automatique et aucune entrée 25.0.

Validation locale avant publication : **83 contrôles**, sources natives Dolibarr 24.0.1 `029a92fb538e90db684dec8b6ba80ee5a6638efd`, PHP 8.5.7, adaptateur SQL SQLite simulé. Français/anglais, retours à la ligne, initialisation répétée, rattrapage d’entrée vide inutilisée, conservation des entrées personnalisées/actives/utilisées et isolement par entité vérifiés ; échecs d’écriture et de commit simulés avec rollback. PHPStan 2.3.1 niveau 5 / cible PHP 8.0 sans erreur ; lint des deux PHP modifiés et `git diff --check` réussis. Le modèle PDF n’est pas modifié dans ce complément ; les recettes PDF précédentes restent rattachées à leur SHA.
