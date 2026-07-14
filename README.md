# Module LMDB pour Dolibarr

Module externe Dolibarr pour Les Métiers du Bâtiment.

Version courante : **1.2.0**.

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

Le pictogramme du module est fourni dans :

```text
img/object_lmdb.png
```

## Fonctionnalités

### Référence client issue de la facture récurrente

LMDB reprend la fonction du module `capinvoicereffromrec` : un extrafield **Référence client de la facture générée** est disponible sur les factures récurrentes et sa valeur est appliquée à chaque facture client générée.

Le code historique `capinvoicereffromrec_ref` est volontairement conservé. Les définitions et valeurs existantes sont donc reprises sans duplication ni migration destructive. Lors de la première activation de LMDB 1.2.0, l'ancien réglage `CAPINVOICEREFFROMREC_ACTIVE` est également repris si le nouveau réglage LMDB n'existe pas encore.

La référence accepte les substitutions Dolibarr natives et les variables de période suivantes, calculées depuis la date réelle de la facture générée :

- `__INVOICE_PREVIOUS_MONTH__`, `__INVOICE_MONTH__`, `__INVOICE_NEXT_MONTH__` ;
- `__INVOICE_PREVIOUS_MONTH_TEXT__`, `__INVOICE_MONTH_TEXT__`, `__INVOICE_NEXT_MONTH_TEXT__` ;
- `__INVOICE_PREVIOUS_YEAR__`, `__INVOICE_YEAR__`, `__INVOICE_NEXT_YEAR__`.

Lorsqu'un modèle combine `__INVOICE_YEAR__` avec `__INVOICE_NEXT_MONTH__` ou `__INVOICE_NEXT_MONTH_TEXT__`, l'année suit automatiquement le mois calculé au changement d'année : une facture datée de décembre produit donc janvier de l'année suivante. La règle symétrique s'applique à `__INVOICE_PREVIOUS_MONTH__` et `__INVOICE_PREVIOUS_MONTH_TEXT__` pour une facture datée de janvier. Utilisé seul ou avec le mois courant, `__INVOICE_YEAR__` conserve l'année de la facture.

LMDB déclare également ces variables dans le mécanisme natif `complete_substitutions_array()`. Elles sont ainsi disponibles dans les contenus de documents et notes PDF qui passent par les substitutions Dolibarr. Le modèle `lmdbsponge` charge explicitement les domaines `main`, `bills`, `products`, `dict`, `companies`, `compta`, `projects`, `other` et `lmdb@lmdb` avant le rendu ; la référence client déjà résolue et enregistrée lors de `BILL_CREATE` est donc présente lorsque Dolibarr recharge la facture pour générer le PDF.

La fonction peut être activée ou désactivée par entité depuis `admin/setup.php`. Tant que l'ancien module `capinvoicereffromrec` est actif, LMDB suspend sa propre propagation afin d'éviter un double traitement.

#### Migration depuis CapInvoiceRefFromRec

1. Mettre à jour et réactiver LMDB afin qu'il reprenne la définition de l'extrafield et l'ancien réglage.
2. Vérifier les valeurs existantes sur les factures récurrentes.
3. Désactiver le module `capinvoicereffromrec`.
4. Générer une facture de test et vérifier sa référence client.

### Envoi automatique des factures récurrentes

LMDB peut envoyer automatiquement une facture client lorsqu'elle est générée depuis une facture récurrente configurée.

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

Les travaux planifiés, constantes, extrafields, modèles documentaires et données du registre sont conservés lors d'une désactivation/réactivation. Une désactivation arrête l'exécution sans réinitialiser la configuration.

Les onglets internes disponibles sont :

- Configuration ;
- Compatibilité ;
- À propos.

## Licence

GPL-3.0-or-later.
