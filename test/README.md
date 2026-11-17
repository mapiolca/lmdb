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
