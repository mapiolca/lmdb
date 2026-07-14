# ChangeLog

## 1.1.0 - 2026-07-14

- Transfert de l'envoi automatique des factures récurrentes depuis le module Delegation vers LMDB.
- Ajout d'une tâche quotidienne native de priorité `60`, exécutée après la génération core des factures récurrentes.
- Reprise conservatrice des extrafields historiques `lmdb_envoi_auto` et `lmdb_template` sur les factures et factures récurrentes.
- Ajout d'un registre Multicompany transactionnel empêchant les doubles envois et permettant la reprise des erreurs explicites.
- Ajout d'un garde-fou bloquant LMDB tant que l'ancienne tâche Delegation reste active.
- Utilisation des modèles d'emails `facture_send`, des contacts de facturation, des substitutions et de `CMailFile` natifs.
- Gestion conditionnelle des pièces jointes dans le répertoire documentaire de l'entité propriétaire de la facture.
- Appel du trigger core `BILL_SENTBYMAIL` après succès, sans création manuelle d'événement Agenda.
- Intégration conditionnelle du compteur natif d'emails de facture à partir de Dolibarr 23.
- Ajout des diagnostics et de la limite par passage dans la configuration LMDB.
- Passage de la famille du module à `Les Métiers du Bâtiment` et ajout de la dépendance au module Factures.
- Mise en conformité de la permission de configuration avec l'identifiant `45005001`, avec migration des affectations depuis `450051`.
- Conservation des constantes, extrafields, modèles, travaux planifiés et données lors des désactivations/réactivations.
- Ajout du marqueur racine `modulebuilder.txt`.

## 1.0.0 - 2026-06-08

- Création du module externe Dolibarr `lmdb` avec ID module `450050`.
- Ajout du modèle PDF de facture `lmdbsponge`, basé sur le modèle natif `sponge`.
- Ajout de fallbacks de traduction pour les libellés PDF de facture et les périodes de service.
- Ajout d'un garde-fou pour les dates de service des factures générées depuis une facture récurrente.
- Ajout du pictogramme du module.
- Ajout des pages d'administration `setup.php`, `compatibility.php` et `about.php`.
- Ajout des traductions `fr_FR` et `en_US`.
