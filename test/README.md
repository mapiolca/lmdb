# Vérification des traductions des factures

Depuis la racine du module :

```sh
php test/invoice_translation_regression.php /chemin/vers/dolibarr/htdocs
```

Le script charge réellement la classe `Translate` et les fichiers de langue du core indiqué. Les objets facture, la persistance et le rendu final du PDF sont simulés. Les fonctions LMDB, le hook PDF, le modèle dérivé et les substitutions sont exécutés directement ; aucune base, aucun email et aucun fichier de production ne sont utilisés.

Scénarios : catalogues natifs chargés puis dictionnaire vidé ; même situation après chargement de LMDB ; français et anglais ; conservation des traductions personnalisées et du jeu de caractères ; appels via hook Sponge et directement via `lmdbsponge` ; sauvegarde de la référence récurrente ; changements d’année et de langue entre entités ; refus de persister un mois non traduit.

Contrats et tests vérifiés le 2026-09-08 sous PHP 8.5.7 :

- Dolibarr 20.0.0, commit `697bf01970740a3339cd99cf055b4428fc5e051c` : [Translate](https://github.com/Dolibarr/dolibarr/blob/697bf01970740a3339cd99cf055b4428fc5e051c/htdocs/core/class/translate.class.php) et [hook Sponge](https://github.com/Dolibarr/dolibarr/blob/697bf01970740a3339cd99cf055b4428fc5e051c/htdocs/core/modules/facture/doc/pdf_sponge.modules.php).
- Dolibarr 23.0.4, commit `cb82037066c1c71f7c867482e9e92975222dfc14` : [Translate](https://github.com/Dolibarr/dolibarr/blob/cb82037066c1c71f7c867482e9e92975222dfc14/htdocs/core/class/translate.class.php) et [hook Sponge](https://github.com/Dolibarr/dolibarr/blob/cb82037066c1c71f7c867482e9e92975222dfc14/htdocs/core/modules/facture/doc/pdf_sponge.modules.php).

Le rechargement utilise exclusivement les APIs natives sans toucher aux propriétés privées de `Translate`. Il complète le dictionnaire partagé, car le hook ne peut pas remplacer l’objet de langue du modèle appelant. Il ne réinitialise aucune configuration et ne change aucun statut métier.

Limites : PHP 8.0 et une instance complète Multicompany n’ont pas été exécutés ; PHPStan n’est pas installé/configuré dans ce dépôt. Le rendu graphique réel, les surcharges de traduction en base et les hooks d’autres modules restent à vérifier en recette avec une facture FR, une facture EN et deux entités. Les versions intermédiaires du core ne sont pas déclarées testées. Aucune réécriture automatique des références historiques et aucune migration SQL.

Le même test exécuté sur le code précédant le correctif échoue sur la restauration du titre natif de la facture après vidage du dictionnaire.


# Désactivation de l’envoi des factures récurrentes en v24 — LMDB 1.2.2

Exécuter depuis la racine du module, sous PHP 8.0 ou supérieur :

```sh
for v in 20.0.0 21.0.0 22.0.0 23.0.4 23.10.0; do
    php test/invoice_autosend_compatibility_regression.php "$v" enabled || exit 1
done
for v in 24.0.0-alpha 24.0.0-beta 24.0.0-rc1 24.0.0 24.0.1 25.0.0; do
    php test/invoice_autosend_compatibility_regression.php "$v" disabled || exit 1
done
```

Résultat du 2026-09-11, sous **PHP 8.5.7** : les onze versions simulées passent. Le script exécute les classes LMDB, le descripteur et la page de réglages réels avec des doubles pour les classes core, les helpers, les accès SQL et le mail. Les fichiers temporaires sont créés sous `test/` puis supprimés. Aucune instance, configuration réelle ou messagerie n’est utilisée.

Contrôles exécutés :

- frontière v23/v24, y compris alpha, bêta, RC et versions futures ; maintien du chemin antérieur avant v24 ;
- cron des factures absent du descripteur en v24+, ancien cron quittant immédiatement sans accès SQL ni mail, registre LMDB ignorant également les envois natifs ;
- ajout des extrafields avant v24, absence de création en v24+, actualisation des définitions existantes par l’API `ExtraFields`, avec la bonne entité, rejouée deux fois ;
- condition du cron existant actualisée sans écriture de statut, fréquence ou historique ; conservation de la déclaration après `remove()` ;
- rendu réel du formulaire LMDB sous v23, masquage sous v24, POST direct sous v24 refusé avant écriture ;
- cron, disponibilité et formulaire des campagnes d’emailing conservés.

La condition de version est calculée par `LmdbCompatibility::isRecurringInvoiceAutoSendSupported()`. Le descripteur persiste son résultat avec une expression native `isModEnabled(...) && 0/1` : `version_compare()` n’est pas placé dans une expression protégée par `dol_eval()`. Réactiver LMDB après une montée de version Dolibarr, dans chaque entité, actualise la visibilité des extrafields et du cron. Le garde-fou de `run()` s’applique immédiatement, même avant cette réactivation.

Preuves de lecture du core du 2026-09-11 :

- branche Dolibarr `24.0`, commit `34ea54a551b4adab85618e78da4cce2f1405967d` : le [ChangeLog v24](https://github.com/Dolibarr/dolibarr/blob/34ea54a551b4adab85618e78da4cce2f1405967d/ChangeLog#L62) annonce l’envoi automatique ; [FactureRec::createRecurringInvoices()](https://github.com/Dolibarr/dolibarr/blob/34ea54a551b4adab85618e78da4cce2f1405967d/htdocs/compta/facture/class/facture-rec.class.php) utilise `auto_validate = 2` et `fk_email_template` ;
- source locale Dolibarr `develop`, commit `f0eeff2eefc2ce93359681652b535468ea145277` : `card-rec.php` expose le statut et le modèle d’email natifs ; `DolibarrModules::insert_cronjobs()` conserve une tâche existante et exige donc une actualisation explicite de sa condition.

Les expressions de visibilité `isModEnabled(...) && 0/1` ont aussi été exécutées avec `dol_eval()` extrait des sources natives 20.0.0 (`697bf01970740a3339cd99cf055b4428fc5e051c`), 23.0.4 (`cb82037066c1c71f7c867482e9e92975222dfc14`) et du checkout `develop` ci-dessus, sous PHP 8.5.7 : résultats booléens attendus dans les six cas actif/inactif, sans assouplissement de l’évaluateur. Il s’agit d’une exécution isolée, pas d’un test sur instance.

Non-régression des traductions exécutée avec la classe `Translate` et les catalogues du checkout local `develop` ci-dessus : réussite. Les factures et le rendu PDF restent simulés selon le premier chapitre.

Limites : ces contrôles ne constituent pas des tests sur des instances Dolibarr complètes v20–v25. PHP 8.0, MySQL/MariaDB, Multicompany réel, navigateur, SMTP et basculement métier n’ont pas été exécutés. PHPStan n’est ni installé ni configuré dans ce module. Les règles de droits, CSRF, PDF, SQL métier et campagnes ne sont pas modifiées ; aucune notification réelle n’a été envoyée.

Recette à effectuer après déploiement : comparer avant/après les valeurs des extrafields, constantes et paramètres cron dans deux entités ; réactiver LMDB ; constater le masquage des anciennes options ; configurer le statut et le modèle natifs de chaque facture récurrente ; vérifier un envoi sur une instance de test et l’absence de second envoi par l’ancien cron LMDB. Les anciennes options LMDB ne sont pas migrées automatiquement vers les options natives.


# Colonne des dates d’envoi programmées

```sh
php test/mailing_schedule_list_regression.php
DOLIBARR_PATH=/chemin/dolibarr/htdocs python3 test/run_phpstan.py
```

Le premier test exécute les hooks du module avec une base SQLite en mémoire : requête native filtrée/paginée réutilisée, une seule lecture groupée des programmations, deux entités, filtre projet, date nulle ou absente, campagne SMS, droit de lecture refusé, module désactivé, colonne masquée, appels successifs et erreur SQL. SQLite vérifie le comportement SQL isolé ; cela ne remplace pas un essai MySQL/MariaDB avec Multicompany et le gestionnaire de hooks natif.

Vérification du 2026-11-17 sous PHP 8.5.7 : test réussi ; PHPStan niveau 5, cible syntaxique PHP 8.0 via `test/run_phpstan.py`, aucune erreur avec les déclarations du checkout core `029a92fb538e90db684dec8b6ba80ee5a6638efd`. Recette navigateur sur un tableau HTML local avec le script réel et le jQuery du core : date affichée avant le statut, absence de date vide, alignement des cellules et double initialisation réussis. Ce tableau est une simulation de DOM, sans instance distante ni déploiement.

Lecture des sources `htdocs/comm/mailing/list.php` des tags officiels 20.0.0, 21.0.0, 22.0.0, 23.0.0 et 24.0.0 : contextes `mailinglist`, hooks `doActions`, `printFieldListTitle` et `printFieldListFooter`, identifiant `data-rowid` disponible ; aucun hook de rendu par ligne. La liste projet utilise `projectmailing` en v24 et dans le checkout local. La branche distante `25.0` était inaccessible (HTTP 404) : v25 reste ciblée mais non vérifiée. Cette lecture ne constitue pas une exécution de ces cinq versions.

Après déploiement : réactiver LMDB par entité et vérifier la liste normale, le filtre destinataire, l’onglet projet lorsque disponible, la pagination 20/50/100, la préférence afficher/masquer, les cases d’action à gauche et à droite, les listes vides, deux entités et la date/heure avec le fuseau utilisateur. La colonne nécessite JavaScript ; sans lui la liste conserve sa structure native. La colonne n’ajoute aucun tri ni filtre de date. Aucun test PHP 8.0, instance MySQL/MariaDB ou Multicompany réelle n’a été exécuté.

## Complément de recette du 2026-11-17 — PR #9

Code fonctionnel examiné : `b603f9913bd52db658959a880b8930fc5e490681`, branche `feat/mailing-scheduled-date-column`, base `12b4acba9f12f55806db14ec59ba0007cde833bb`. Aucun correctif du code fonctionnel ajouté pendant cette recette.

Le test accepte désormais le chemin d’un core pour exécuter son vrai `HookManager` :

```sh
php test/mailing_schedule_list_regression.php /chemin/dolibarr/htdocs
```

Résultat réussi sous PHP 8.5.7 avec les gestionnaires des sources mises en cache :

| Source | Révision de référence | Résultat |
|---|---|---|
| 20.0.0 | `697bf01970740a3339cd99cf055b4428fc5e051c` | Réussi |
| 21.0.0 | `fd970b582a4d8c5779a2958a4e9f4fce225cf085` | Réussi |
| 22.0.0 | `49b9a6d19f3deb6d410c0e9b3310e95be4ea7710` | Réussi |
| 23.0.0 | `57a1f05d490a7a80944a8232e9c613e6556d2704` | Réussi |
| 24.0.0 | `769c7db907099643558e77d7002c109cfda919e5` | Réussi |
| Checkout local | `029a92fb538e90db684dec8b6ba80ee5a6638efd` | Réussi |

Les appels `doActions`, `printFieldListTitle` et `printFieldListFooter` traversent réellement le gestionnaire natif : retour neutre, absence de double lecture lorsque LMDB figure dans deux contextes, consommation de la sortie, réinitialisation native de `resprints` à `null`, second appel sans contamination et colonne masquée sans lecture. La découverte du descripteur et des contextes, la session et la base restent simulées. Ces résultats ne valident pas des instances complètes v20–v25.

Le test SQL isolé, la non-régression des traductions FR/EN et PHPStan niveau 5 ont été réexécutés avec succès. La recette distante sur `https://develop.lesmetiersdubatiment.fr` est **bloquée avant déploiement**, et ne constitue donc pas une validation de la colonne :

- session Dolibarr connectée, version affichée `25.0.0-alpha`, compte Codex, entité TEST 1 ; TEST 2 disponible ; module LMDB 2.0.0 actif ; liste initiale de deux emailings consultée, sans nouvelle colonne ;
- dépôt cPanel **DEVELOP - LMDB**, répertoire servi `develop/htdocs/custom/lmdb`, branche initiale et finale confirmée `feat/attestations-conformite-loi-finance`, HEAD `4872f948f37a2e651a687a7abad6e99d919e2cc6` ;
- saisie de la nouvelle branche sans bascule effective, puis `Update from Remote` refusé avec « The system could not contact the remote repository. » ; le même échec est constaté depuis la session cPanel renouvelée ;
- tentative de diagnostic par un terminal distinct sans résultat de commande observable ; aucun checkout réussi, aucun rechargement ou changement de réglage du module, aucune modification d’emailing ni aucun envoi ;
- preuve locale ignorée : `test/.runtime/mailing-deployment-blocked.jpg`.

À reprendre après rétablissement de l’accès Git du serveur : déployer la branche, confirmer son SHA et la présence du script servi, recharger LMDB dans chaque entité, puis exécuter la recette navigateur listée plus haut. Ne pas assimiler l’absence de colonne sur l’ancien code à un échec du patch. Aucun contrôle CI n’était affiché sur la PR #9 lors de cette vérification.

## Recette finale sur develop — PR #9, 2026-11-17

Cette entrée complète et corrige le diagnostic de blocage ci-dessus, sans en effacer la trace. L’ancienne branche distante `feat/attestations-conformite-loi-finance` n’existe plus dans `git ls-remote`. Le message générique cPanel ne prouvait pas une panne réseau. La sélection effective de l’option `feat/mailing-scheduled-date-column` dans le composant Chosen, suivie de **Mettre à jour**, a basculé le dépôt servi ; **Update from Remote** fonctionne ensuite et confirme le dépôt à jour. Aucune URL distante, clé, protection TLS/SSH ou identité Git n’a été modifiée. La tentative SSH locale est restée refusée par la vérification de clé d’hôte, sans contournement.

Code fonctionnel utilisé pour les essais ci-dessous et confirmé dans cPanel : `c49051f6c86acbb0301bc2c0f7e93297aa816c6a`. Le complément final impose également POST pour la persistance de la préférence vide, dans le formulaire natif protégé par le CSRF de `main.inc.php` ; un GET n’effectue aucune écriture additionnelle LMDB, comme vérifié par le test. Instance réellement observée : **Dolibarr 25.0.0-alpha, PHP 8.3.36, pilote mysqli, Multicompany actif**, compte Codex. Révision du core, version du serveur MySQL/MariaDB et version Multicompany non relevées. Ces résultats ne valident pas une future v25 finale.

Les essais réels ont conduit aux corrections suivantes, publiées sur la même branche : traduction sans entités HTML pour le texte injecté ; en-tête natif `th` ; URL de script versionnée par sa date de modification pour invalider le cache ; conservation du choix de masquage même lorsque cette colonne est la seule option. L’API native de préférences supprime une valeur vide : une sélection CSV non vide ne contenant aucun champ (`,`) permet au sélecteur natif de conserver l’état sans champ affiché. Le correctif intermédiaire `240c552` ne suffisait pas ; il est remplacé par le traitement après sauvegarde native dans `printFieldListWhere`.

À la demande complémentaire de l’utilisateur, la colonne propose désormais le titre triable natif et une période inclusive avec deux datepickers Dolibarr. Le hook SQL enrichit simultanément le SELECT et son préfixe natif de comptage, puis applique les bornes avant comptage, tri et pagination. Les liens de tri et de pagination conservent les composantes de date. Aucun endpoint, table, mécanisme de droits ni traitement d’envoi supplémentaire.

Scénarios effectivement vérifiés dans le navigateur sur TEST 1 :

- rechargement natif de LMDB, initialement actif, pour actualiser les contextes ; module actif à la fin ; aucune programmation modifiée ;
- emailing 1 : liste et fiche concordent sur `05/08/2026 21:20` ; emailing 2 sans programmation : cellule vide ;
- titre lisible avec accents, en-tête `th scope=col`, script avec paramètre de version servi et colonne avant le statut ;
- clic sur le titre : ordre décroissant `1, 2`, puis croissant `2, 1` (valeur nulle d’abord en ordre croissant) ;
- bornes sélectionnées avec les **calendriers natifs**, du 05/08/2026 au 05/08/2026 : un seul résultat (emailing 1) ; campagne sans date exclue ;
- passage à 50 avec filtre actif : bornes conservées dans les liens ; du 06/08/2026 au 06/08/2026 : état vide natif ;
- ligne vide : `colspan=10`, identique au nombre d’en-têtes ; réinitialisation native : retour des deux campagnes ;
- limites 20, 50, 100, puis 20 : sélection native fonctionnelle, dates et alignement conservés ; le select `#limit` possède un formulaire parent ; seulement deux campagnes disponibles, donc pas de changement de page multi-page exécuté ;
- désélection dans le sélecteur natif puis soumission : colonne masquée ; actualisation : masquage conservé ; resélection : colonne réaffichée ;
- largeur mobile initiale (539 px) avec défilement horizontal natif, puis contrôle bureau 1440 px pour accéder au menu d’entité ; override de largeur restauré à la fin.

Multicompany : bascule effectuée avec le bouton natif **Changer d’entité**, après sélection de TEST 2. La page est refusée au compte Codex dans TEST 2, sans restitution des campagnes. Aucun droit ni module activé pour contourner ce refus. Retour effectif à TEST 1 confirmé. Cela valide le refus du parcours dans TEST 2, pas un affichage positif de la colonne dans deux entités autorisées ; le filtrage SQL positif sur deux entités reste couvert par le test isolé.

Limite native distincte reproduite : `mailing/list.php?filteremail=lmdb-recette%40example.invalid` échoue avec `Unknown column 'm.fk_project' in 'ON'`. La même erreur, dans les requêtes de comptage et de liste dépourvues du SELECT LMDB, est observée avec le contexte natif alternatif `contextpage=mailingrecipebaseline` (hooks de colonne inactifs). Le core combine `mailing m, mailing_cibles mc LEFT JOIN projet pr ON ... m.fk_project`. Ce parcours natif par destinataire reste bloqué sur cette instance ; aucun fichier core modifié. L’onglet projet sans campagne liée, les cases de masse à droite et un compte de lecture seul n’ont pas fait l’objet d’un essai positif distant.

Vérifications locales finales sur le code `c49051f` : test étendu réussi avec les vrais HookManager v20–v24 et le checkout local ; SQL SQLite en mémoire pour bornes, date invalide, reset, tri ASC/DESC avant LIMIT, cohérence du préfixe de comptage et conservation des paramètres ; syntaxe PHP, non-régression FR/EN et PHPStan niveau 5 (cible PHP 8.0) réussis. L’ancien code `b91158f` échoue sur le nouveau scénario des entités HTML ; le correctif réussit. La découverte des hooks, la session et le SQL des tests locaux restent simulés ; aucune instance complète v20–v24 ni PHP 8.0 réel exécutée.

État final demandé : branche de recette conservée sur develop ; TEST 1, liste sans filtre, tri de création décroissant, limite 20, colonne visible. Les préférences ont été exercées via l’interface native puis remises dans leur état visible initial. Aucun emailing créé, modifié, validé, envoyé ou supprimé ; aucun cron exécuté, aucune configuration SMTP ou notification modifiée. Le rechargement natif du module est le seul changement de configuration de module effectué.

Preuves locales ignorées : `test/.runtime/mailing-git-repaired.jpg`, `mailing-label-before.jpg`, `mailing-list-final-test1.jpg`, `mailing-test2-denied.jpg`. Le SHA du dernier commit documentaire servi peut être plus récent que `c49051f`, sans changement du code fonctionnel. La PR décrit les résultats rattachés à ce code et distingue les contrôles CI absents des tests locaux.
