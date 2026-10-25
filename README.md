# Module LMDB pour Dolibarr

Module externe Dolibarr pour Les Métiers du Bâtiment.

Version courante : **2.0.0**.

## Compatibilité

- Dolibarr 20.0 ou supérieur
- PHP 8.0 ou supérieur
- MySQL/MariaDB via l'abstraction Dolibarr

## Installation

Copier le dossier du module dans :

```text
htdocs/custom/lmdb
```

Activer ensuite le module **LMDB** depuis la liste des modules Dolibarr.

Le logo de la société est fourni sous les deux noms attendus par les versions Dolibarr :

```text
img/object_lmdb.png
img/lmdb.png
```

## Fonctionnalités

### Attestations individuelles de conformité Loi Finance

Dans **Outils → Attestation de conformité Loi Finance**, créer un brouillon en sélectionnant le tiers, la version et la date d’attestation. Le catalogue des versions est administré depuis **Configuration → Attestations** : aucune version n’est déclarée attestable automatiquement. Le bouton **+** ouvre une modale d’ajout ; le crayon permet une modification préremplie. Les boutons Enregistrer/Annuler utilisent le pied natif des modales et les messages identifient chaque champ à compléter. Une nouvelle version reste désactivée jusqu’à son activation explicite. L’interrupteur natif change uniquement son état. La corbeille permet de supprimer une version inutilisée après confirmation ; toute attestation liée, même brouillon ou annulée, bloque la suppression.

Les réglages présentent d’abord **Numérotation**, puis **Modèles de document**, avec les colonnes et interrupteurs natifs. Le modèle **Standard** utilise le format fixe `ATLF-{yyyy}-{0000}`. Le modèle **Advanced** permet de saisir un masque dans sa description, avec l’aide Dolibarr et un exemple calculé sans réserver de référence. Le choix s’effectue avec les interrupteurs natifs ; le masque Advanced reste conservé lorsque Standard est sélectionné. Le masque est enregistré par entité ; les références déjà attribuées restent inchangées.

Configurer le représentant de l’émetteur, sa qualité et une signature visuelle PNG. Le lieu de signature reprend la ville des coordonnées natives de l’entité (`MAIN_INFO_SOCIETE_TOWN`, via `$mysoc->town`) ; il n’est pas saisi une seconde fois dans LMDB. Le brouillon porte la référence `(PROV{ID})`. La validation réserve une référence par le modèle de numérotation natif et fige les informations légales du tiers, de l’émetteur, de la version et la signature. La signature visuelle n’est pas une signature électronique cryptographique. Une attestation validée est immuable ; elle peut être annulée, puis remplacée par une nouvelle attestation.

Le modèle PDF `loifinance` reprend les deux volets du [modèle fiscal BOI-LETTRE-000242 du 25 mars 2026](https://bofip.impots.gouv.fr/bofip/10692-PGP.html/identifiant=BOI-LETTRE-000242-20260325). Le client complète et signe le volet 2 hors ligne ; l’ensemble signé peut être joint en PDF. Les deux volets conservent les en-têtes documentaires Dolibarr : logo, référence et date. Les encadrés émetteur/destinataire figurent uniquement sur la première page. Le pied natif respecte les réglages de texte libre, de détails société et les hooks ; les paragraphes et signatures restent hors de sa zone réservée. Les deux volets sont nécessaires. L’activation du module ne constitue pas une certification d’une version de Dolibarr : l’émetteur reste responsable des versions et périmètres qu’il atteste.

Les attestations utilisent le pictogramme certificat bleu et des confirmations en modales natives. Les caractéristiques de la fiche reprennent la disposition et les classes natives des champs Dolibarr. La bannière affiche la miniature de la première page du PDF, ouvrant son aperçu natif, lorsque la conversion Imagick est disponible et autorisée par les réglages Dolibarr ; le certificat reste affiché sinon. La fiche utilise les blocs et onglets natifs Notes, Fichiers joints et Événements/Agenda. Le formulaire classique de dépôt du PDF signé reste dans l’onglet Fichiers joints ; le glisser-déposer natif est activé sur les onglets compatibles, avec les mêmes droits et contrôles serveur. Les événements `LMDB_ATTESTATION_CREATE`, `LMDB_ATTESTATION_UPDATE` et `LMDB_ATTESTATION_DELETE` sont configurables dans l’administration native Agenda et Notifications. Le contexte `trigger_reason` distingue création, édition, validation, annulation et document. Aucun second événement Agenda n’est créé manuellement.

L’envoi individuel depuis la fiche utilise `FormMail`, le contrôleur de courriel et le transport SMTP natifs, car il s’agit d’un document choisi par l’utilisateur, sans abonnement Notifications. Les modèles sont de type exact `lmdbattestation_send` et disposent des substitutions `__LMDB_ATTESTATION_REF__`, `__LMDB_ATTESTATION_VERSION__`, `__LMDB_ATTESTATION_DATE__`, `__LMDB_ATTESTATION_THIRDPARTY__` et `__LMDB_ATTESTATION_URL__`. Un registre empêche le rejeu d’une soumission et bloque un envoi interrompu en état « pending » ; vérifier sa livraison avant toute reprise manuelle. Aucun envoi automatique d’attestation ni nouveau cron n’est ajouté.

La liste et l’onglet Agenda respectent la position native de la colonne d’actions (réglage global et préférence personnelle lorsque le core la fournit). La référence propose l’infobulle Ajax native lorsque cette option Dolibarr est activée : statut, tiers, version, dates, licence, représentant, émetteur et périmètre. Les informations validées proviennent de l’instantané ; les accès sont revérifiés lors du chargement.

L’activation précharge les familles Dolibarr 23.0 et 24.0, désactivées, avec un périmètre fonctionnel de référence et des exclusions à vérifier et adapter par l’émetteur ([sources et limites](test/ATTESTATION_SCOPES.md)). Les dates de première publication proviennent du wiki officiel (28/02/2026 et 20/08/2026). Aucune entrée finale 25.0 n’est créée à partir de l’alpha. Une entrée existante conserve son contenu et son activation ; seules les entrées de préversion inactives, inutilisées et dont les deux périmètres sont vides reçoivent ces textes.

L’onglet Contacts/Adresses utilise le formulaire et les liaisons natifs Dolibarr : Responsable interne, Signataire client et Destinataire. Les contacts externes sont exclusivement ceux du tiers de l’attestation, dans les entités accessibles. Les liens actifs sont proposés parmi les destinataires du courriel ; leur modification ne réécrit pas l’instantané légal. L’onglet Fichiers joints affiche les compteurs, la liste documentaire complète et les liens natifs, avec dépôt pour une attestation validée et droits distincts. La génération reste sur la fiche principale. Les formulaires intégrés aux titres sont disponibles dès Dolibarr 21 ; Dolibarr 20 conserve les formulaires classiques.

Les permissions lecture, écriture, validation, annulation, génération, envoi, suppression, export et API sont distinctes. Les utilisateurs externes sont refusés. Les restrictions commerciales du tiers sont appliquées avant pagination. Les partages Multicompany de l’objet et de sa numérotation sont déclarés ; les modifications et envois s’effectuent dans l’entité propriétaire. Les documents restent dans le répertoire de cette entité, y compris pour une consultation depuis une autre entité. La lecture suffit pour télécharger un PDF existant ; la signature privée est protégée.

Les fichiers joints sont limités aux PDF, contrôlés par MIME. L’API REST native expose les brouillons, validation, annulation et génération, avec les mêmes règles métier. Un export natif est déclaré. Les données légales figées dans `snapshot_data` constituent un instantané historique explicite ; les relations vivantes restent `fk_soc` et `fk_version`.

Après mise à jour, réactiver LMDB dans chaque entité concernée pour installer les trois nouvelles tables, actualiser droits/menus et déclarer les événements, modèles et partages. L’initialisation conserve les réglages et modèles personnalisés existants. À la première mise à jour depuis la préversion 2.0.0 qui ne proposait qu’un modèle configurable, un masque personnalisé déjà enregistré sélectionne Advanced ; les choix suivants sont conservés sans migration répétée. Les validations exécutées et leurs limites sont détaillées dans [test/ATTESTATIONS.md](test/ATTESTATIONS.md).

### Programmation des emailings natifs

LMDB ajoute par le hook natif `mailingcard` le champ **Date et heure d'envoi programmées** sur la fiche des emailings Dolibarr. Le champ utilise le sélecteur date/heure du core et peut être renseigné ou effacé tant que la campagne est au brouillon ou validée. Une valeur vide désactive la programmation. Les dates sont conservées dans la table normalisée `lmdb_mailing_schedule`, propre au module et filtrée par entité ; aucune table d'extrafields du module Emailing n'est requise.

La tâche native **Envoi programmé LMDB des emailings natifs** s'exécute toutes les cinq minutes. Elle :

- sélectionne uniquement les campagnes email de l'entité courante ayant une date programmée atteinte ;
- fait entrer dans le traitement automatique uniquement les campagnes à l'état **Validé** ;
- reprend les campagnes passées à l'état partiel par ce traitement lorsque des destinataires en erreur doivent être réessayés ;
- utilise directement le mécanisme Web natif Dolibarr (`CMailFile`, substitutions, désabonnements, suivi de lecture, pièces jointes et réglages SMTP), sans exécutable PHP CLI secondaire ;
- respecte `MAILING_LIMIT_SENDBYWEB` comme taille maximale de chaque lot et enchaîne les lots dans le même passage tant que des destinataires non traités subsistent ;
- utilise la signature de l'utilisateur ayant validé la campagne ;
- pose un verrou MySQL/MariaDB par campagne pour éviter deux exécutions LMDB concurrentes ;
- conserve l'état **Envoyé partiellement** lorsque des erreurs subsistent, puis réessaie les destinataires concernés au passage suivant sans les relancer indéfiniment dans la même exécution ;
- passe l'emailing à l'état **Envoyé complètement** dès qu'aucun destinataire non envoyé ou en erreur ne subsiste ;
- ignore ensuite définitivement les campagnes à l'état **Envoyé complètement**.

Les campagnes sans date programmée, au brouillon, déjà envoyées complètement ou d'une autre entité sont ignorées. Les modules Emailing et Travaux planifiés sont requis. L'envoi Web doit être autorisé dans la configuration du module Emailing avec une valeur `MAILING_LIMIT_SENDBYWEB` supérieure à zéro. PHP CLI et les fonctions système `exec`, `shell_exec` ou `proc_open` ne sont pas nécessaires.

### Référence client issue de la facture récurrente

LMDB reprend la fonction du module `capinvoicereffromrec` : un extrafield **Référence client de la facture générée** est disponible sur les factures récurrentes et sa valeur est appliquée à chaque facture client générée.

Le code historique `capinvoicereffromrec_ref` est volontairement conservé. Les définitions et valeurs existantes sont donc reprises sans duplication ni migration destructive. Lors de la première activation de LMDB 1.2.0, l'ancien réglage `CAPINVOICEREFFROMREC_ACTIVE` est également repris si le nouveau réglage LMDB n'existe pas encore.

La référence accepte les substitutions Dolibarr natives et les variables de période suivantes, calculées depuis la date réelle de la facture générée :

- `__INVOICE_PREVIOUS_MONTH__`, `__INVOICE_MONTH__`, `__INVOICE_NEXT_MONTH__` ;
- `__INVOICE_PREVIOUS_MONTH_TEXT__`, `__INVOICE_MONTH_TEXT__`, `__INVOICE_NEXT_MONTH_TEXT__` ;
- `__INVOICE_PREVIOUS_YEAR__`, `__INVOICE_YEAR__`, `__INVOICE_NEXT_YEAR__`.

Les variables textuelles de mois utilisent les traductions natives `Month01` à `Month12`. Si l'objet de traduction Dolibarr renvoie encore une clé technique telle que `Month08`, LMDB applique sa traduction locale dans la langue de sortie (`Août` en français, `August` en anglais).

Lorsqu'un modèle combine `__INVOICE_YEAR__` avec `__INVOICE_NEXT_MONTH__` ou `__INVOICE_NEXT_MONTH_TEXT__`, l'année suit automatiquement le mois calculé au changement d'année : une facture datée de décembre produit donc janvier de l'année suivante. La règle symétrique s'applique à `__INVOICE_PREVIOUS_MONTH__` et `__INVOICE_PREVIOUS_MONTH_TEXT__` pour une facture datée de janvier. Utilisé seul ou avec le mois courant, `__INVOICE_YEAR__` conserve l'année de la facture.

LMDB déclare également ces variables dans le mécanisme natif `complete_substitutions_array()`. Elles sont ainsi disponibles dans les contenus de documents et notes PDF qui passent par les substitutions Dolibarr. Le modèle `lmdbsponge` charge explicitement les domaines `main`, `bills`, `products`, `dict`, `companies`, `compta`, `projects`, `other` et `lmdb@lmdb` avant le rendu ; la référence client déjà résolue et enregistrée lors de `BILL_CREATE` est donc présente lorsque Dolibarr recharge la facture pour générer le PDF. Le hook natif `pdfgeneration` applique les mêmes fallbacks aux factures générées directement avec Sponge, notamment depuis les modèles de factures récurrentes.

La fonction peut être activée ou désactivée par entité depuis `admin/setup.php`. Tant que l'ancien module `capinvoicereffromrec` est actif, LMDB suspend sa propre propagation afin d'éviter un double traitement.

#### Migration depuis CapInvoiceRefFromRec

1. Mettre à jour et réactiver LMDB afin qu'il reprenne la définition de l'extrafield et l'ancien réglage.
2. Vérifier les valeurs existantes sur les factures récurrentes.
3. Désactiver le module `capinvoicereffromrec`.
4. Générer une facture de test et vérifier sa référence client.

### Envoi automatique des factures récurrentes

Sous **Dolibarr v20 à v23**, LMDB peut envoyer automatiquement une facture client lorsqu'elle est générée depuis une facture récurrente configurée.

À partir de **Dolibarr v24** (préversions comprises), ce traitement LMDB est désactivé au profit de l’envoi natif. Le contrôle serveur s’applique aussi aux anciennes tâches LMDB encore actives : aucun envoi ni écriture dans le registre LMDB n’est effectué. La programmation des campagnes d’emailing reste disponible.

Après mise à jour, réactiver LMDB dans chaque entité concernée pour masquer les anciens extrafields d’envoi et actualiser la condition de visibilité du cron existant. Les valeurs des extrafields, constantes, états, fréquences et historiques des tâches sont conservés ; aucun nouveau cron d’envoi de factures ni extrafield d’envoi absent n’est créé en v24+. L’onglet Compatibilité explique cette indisponibilité et les réglages LMDB ne sont plus modifiables.

Configurer ensuite, sur chaque facture récurrente native, le statut **Validé (à payer) et envoi par email** (`auto_validate = 2`) et le modèle d’email (`fk_email_template`). Les anciennes options LMDB ne sont pas transférées automatiquement : vérifier les destinataires, modèles et prochains envois lors de ce basculement. Aucun email n’est déclenché par la mise à jour du module.

La facture récurrente porte deux extrafields natifs :

- **Envoi automatique de la facture** (`lmdb_envoi_auto`) ;
- **Modèle d'email pour l'envoi automatique** (`lmdb_template`), limité aux modèles Dolibarr de type `facture_send` de l'entité.

Ces codes sont volontairement identiques à ceux historiquement créés par le module Delegation. Lors de l'activation de LMDB, leurs définitions sont reprises sans supprimer les valeurs existantes. Dolibarr copie ensuite nativement ces valeurs vers la facture générée.

La tâche native **Envoi automatique LMDB des factures récurrentes** :

- s'exécute chaque jour avec la priorité `60`, après la génération native des factures récurrentes ;
- traite uniquement les factures validées, non payées, issues d'une facture récurrente et appartenant à l'entité courante ;
- reprend les erreurs explicites au passage suivant ;
- utilise un registre transactionnel pour empêcher un double envoi ;
- place un traitement interrompu dans un état à vérifier manuellement au lieu de risquer un second email ;
- n'envoie aucune facture créée avant le marqueur établi lors de la première activation de la version `1.1.0`.

Les destinataires sont les contacts de facturation, avec repli sur l'email du tiers. Les destinataires To, CC et BCC du modèle sont ajoutés après substitutions. L'expéditeur du modèle est prioritaire sur `MAIN_MAIL_EMAIL_FROM`.

Lorsque le modèle d'email demande une pièce jointe, LMDB utilise le document principal de la facture ou le génère avec le modèle PDF configuré. Le chemin est contrôlé avec le répertoire documentaire de l'entité propriétaire de la facture. Lorsque le modèle ne demande pas de pièce jointe, l'email est envoyé sans PDF.

Après un envoi réussi, LMDB appelle le trigger core `BILL_SENTBYMAIL`. L'événement Agenda et les traitements natifs restent donc la source de vérité ; LMDB ne crée pas d'`ActionComm` parallèle.

L'envoi utilise directement les classes email Dolibarr parce qu'il s'agit d'un envoi documentaire individuel piloté par un modèle `facture_send`, et non d'un abonnement générique. LMDB ne crée donc pas de configuration parallèle dans le module Notifications.

#### Migration depuis Delegation

La tâche historique `sendEmailsNotificationOnInvoiceDate` du module Delegation doit être désactivée dans **Travaux planifiés**. Tant que Delegation et cette ancienne tâche sont actifs, LMDB bloque tous ses envois et affiche un avertissement dans sa page de configuration afin d'éviter les doublons.

LMDB ne modifie ni le code ni les réglages du module Delegation.

### Modèle PDF de facture `lmdbsponge`

Le module ajoute un modèle PDF de facture nommé `lmdbsponge`, basé sur le modèle natif `sponge`.

Corrections apportées :

- fallbacks de traduction pour les clés visibles dans les factures PDF ;
- correction du libellé français `À partir du %s` ;
- correction de l'affichage des périodes de service lorsque `DateFromTo`, `DateFrom` ou `DateUntil` ne sont pas disponibles dans la langue de sortie.
- chargement explicite des traductions et complément défensif des dates de service pour les factures générées depuis une facture récurrente, y compris par tâche automatique.

Le modèle est enregistré comme modèle de document Dolibarr pour les factures, mais il n'est pas imposé comme modèle par défaut lors de l'activation du module.

## Configuration

### Traductions pendant les travaux planifiés

Le cron peut vider `tab_translate` tout en laissant les domaines marqués comme déjà chargés dans `Translate`. LMDB recharge les catalogues de facturation avec une nouvelle instance native, puis complète les traductions absentes ou brutes dans l’objet de langue utilisé par le document. Cette récupération conserve la langue, le jeu de caractères, les répertoires de traduction et les personnalisations déjà disponibles.

Le modèle `lmdbsponge`, le hook `beforePDFCreation` et les substitutions de références utilisent cette même récupération. Si un nom de mois reste impossible à traduire, la référence n’est pas enregistrée. Une référence déjà stockée avec `Month09`, par exemple, nécessite une correction métier explicite avant régénération ; le correctif ne réécrit aucune facture historique.

Le fichier natif de découverte du hook doit être livré sous son nom exact `class/actions_lmdb.class.php`. Une copie suffixée par ` 2` ne remplace pas ce fichier. Les contrôles automatisés et les limites de validation sont décrits dans [test/README.md](test/README.md).

La page de configuration principale est :

```text
admin/setup.php
```

Depuis cette page, un administrateur peut :

- vérifier l'enregistrement du modèle `lmdbsponge` ;
- réenregistrer le modèle si nécessaire ;
- définir explicitement `lmdbsponge` comme modèle PDF de facture par défaut ;
- activer ou désactiver la propagation de la référence client des factures récurrentes ;
- consulter les variables de période disponibles et détecter l'ancien module concurrent ;
- vérifier l'enregistrement et l'activation de la tâche d'envoi ;
- détecter un conflit avec l'ancienne tâche Delegation ;
- consulter le nombre d'envois en erreur ou à vérifier ;
- choisir la limite de traitement par passage (`25`, `50`, `100` ou `250`).
- vérifier la disponibilité du module Emailing, du script d'envoi natif et de PHP CLI ;
- vérifier l'enregistrement et l'activation de la tâche d'envoi programmé des emailings ;
- consulter le nombre de campagnes validées arrivées à échéance et de campagnes partielles à reprendre ;
- choisir le nombre maximal d'emailings traités par passage (`1`, `5`, `10` ou `25`).

Les travaux planifiés (fréquence, activation et historique compris), constantes, extrafields de facturation, programmations d'emailings, modèles documentaires et données du registre sont conservés lors d'une désactivation/réactivation. Une désactivation arrête l'exécution sans réinitialiser la configuration.

Les onglets internes disponibles sont :

- Configuration ;
- Attestations ;
- Compatibilité ;
- À propos.

## Licence

GPL-3.0-or-later.
