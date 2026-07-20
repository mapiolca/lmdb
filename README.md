# Module LMDB pour Dolibarr

Module externe Dolibarr pour Les Métiers du Bâtiment.

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

### Programmation des emailings natifs

LMDB ajoute l'extrafield natif **Date et heure d'envoi programmées** (`lmdb_scheduled_send_at`) sur la fiche des emailings Dolibarr. Le champ utilise le sélecteur date/heure du core et peut être renseigné ou effacé tant que la campagne est au brouillon ou validée. Une valeur vide désactive la programmation.

La tâche native **Envoi programmé LMDB des emailings natifs** s'exécute toutes les cinq minutes. Elle :

- sélectionne uniquement les campagnes email de l'entité courante ayant une date programmée atteinte ;
- fait entrer dans le traitement automatique uniquement les campagnes à l'état **Validé** ;
- reprend les campagnes passées à l'état partiel par ce traitement lorsque des destinataires en erreur doivent être réessayés ;
- délègue l'envoi au script officiel `scripts/emailings/mailing-send.php`, afin de conserver les destinataires, substitutions, désabonnements, pièces jointes, réglages SMTP et limites natifs ;
- transmet l'entité courante au processus CLI et utilise la signature de l'utilisateur ayant validé la campagne ;
- pose un verrou MySQL/MariaDB par campagne pour éviter deux exécutions LMDB concurrentes ;
- passe l'emailing à l'état **Envoyé complètement** dès qu'aucun destinataire non envoyé ou en erreur ne subsiste ;
- ignore ensuite définitivement les campagnes à l'état **Envoyé complètement**.

Les campagnes sans date programmée, au brouillon, déjà envoyées complètement ou d'une autre entité sont ignorées. Le module Emailing, les Travaux planifiés, le script CLI natif de Dolibarr et un exécutable PHP CLI sont requis. La constante core `MAILING_LIMIT_SENDBYCLI=-1` désactive également cette fonctionnalité.

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
- définir explicitement `lmdbsponge` comme modèle PDF de facture par défaut.
- vérifier l'enregistrement et l'activation de la tâche d'envoi ;
- détecter un conflit avec l'ancienne tâche Delegation ;
- consulter le nombre d'envois en erreur ou à vérifier ;
- choisir la limite de traitement par passage (`25`, `50`, `100` ou `250`).
- vérifier la disponibilité du module Emailing, du script d'envoi natif et de PHP CLI ;
- vérifier l'enregistrement et l'activation de la tâche d'envoi programmé des emailings ;
- consulter le nombre de campagnes validées arrivées à échéance et de campagnes partielles à reprendre ;
- choisir le nombre maximal d'emailings traités par passage (`1`, `5`, `10` ou `25`).

Les travaux planifiés (fréquence, activation et historique compris), constantes, extrafields, modèles documentaires et données du registre sont conservés lors d'une désactivation/réactivation. Une désactivation arrête l'exécution sans réinitialiser la configuration.

Les onglets internes disponibles sont :

- Configuration ;
- Compatibilité ;
- À propos.

## Licence

GPL-3.0-or-later.
