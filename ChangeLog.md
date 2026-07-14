# ChangeLog

## 1.2.0 - 2026-07-14

- Intégration dans LMDB des fonctions du module `capinvoicereffromrec` avec attribution à Eric Seigne / CAP-REL.
- Reprise conservatrice de l'extrafield historique `capinvoicereffromrec_ref` et de toutes ses valeurs par entité.
- Propagation transactionnelle de la référence client lors du trigger core `BILL_CREATE`, sans second trigger métier ni événement Agenda parallèle.
- Résolution des substitutions Dolibarr natives et des neuf variables de mois et d'année depuis la date réelle de la facture générée.
- Déclaration des variables de période via les substitutions natives afin de les rendre disponibles dans les contenus PDF, avec chargement explicite des domaines de traduction LMDB et Factures avant rendu.
- Ajout d'un interrupteur natif par entité, initialisé depuis `CAPINVOICEREFFROMREC_ACTIVE` lorsqu'un ancien réglage existe.
- Suspension automatique de la fonction LMDB tant que l'ancien module `capinvoicereffromrec` est actif afin d'éviter un double traitement.
- Ajout de la fonction dans les réglages, l'onglet Compatibilité, l'onglet À propos et la documentation française et anglaise.
- Passage de la version du module à `1.2.0`.

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
- Chargement explicite des traductions LMDB dans les Travaux planifiés et ajout des clés conventionnelles du module et de la permission.
- Correction du résumé du cron pour respecter la limite de quatre substitutions de `Translate::trans()` sur Dolibarr v20.
- Auto-réparation des clés de traduction de la tâche existante lors de son prochain lancement.
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
