# Périmètres de référence — Dolibarr 23.0 et 24.0

Rédaction du 25 octobre 2026, pour LMDB 2.0.0. Les deux familles reçoivent le même périmètre fonctionnel de référence, traduit en français et anglais. Elles restent **désactivées** après installation : le texte doit être vérifié et adapté à la révision, à la configuration et aux flux réellement examinés par l’émetteur.

## Fonctions couvertes proposées

- Validation des factures clients et des avoirs associés ; enregistrement des règlements et traçabilité de leurs annulations ou suppressions autorisées par les parcours natifs.
- Si TakePOS natif est utilisé : ventes et règlements au comptoir, justificatifs et clôtures de caisse par les fonctions natives correspondantes.
- Avec Archives/Journaux inaltérables activé : journalisation des événements pris en charge, chaînage des empreintes, consultation, contrôle du chaînage et export des journaux pour conservation et archivage.

L’intégrité du code, les modules et flux utilisés, ainsi que les modalités de conservation et d’archivage de l’installation doivent être contrôlés avant émission. Le préremplissage ne valide pas automatiquement chaque révision 23.0.x ou 24.0.x.

## Exclusions proposées

Modules externes, développements spécifiques, caisses et connecteurs tiers non examinés ; imports, scripts ou écritures directes contournant la validation et la journalisation natives. Une extension affectant les fonctions couvertes exige une analyse complémentaire de l’ensemble du parcours avant émission : cette exclusion ne suffit pas à rendre conforme une installation qui contourne ces mécanismes.

Les autres fonctions de gestion sans lien avec les encaissements, la conformité comptable/FEC, la facturation électronique et une certification NF525/LNE ne sont pas l’objet de cette attestation.

## Sources et limites

Lecture de sources le 25 octobre 2026, sans essai métier exécuté sur une instance 23.0 ou 24.0 dans ce complément :

| Source figée | Contrat examiné |
|---|---|
| [Dolibarr 23.0.0 — BlockedLog](https://github.com/Dolibarr/dolibarr/blob/57a1f05d490a7a80944a8232e9c613e6556d2704/htdocs/blockedlog/class/blockedlog.class.php) | `loadTrackedEvents()` : factures, paiements clients, documents et événements conditionnels TakePOS/clôture. |
| [Dolibarr 23.0.0 — ActionsBlockedLog](https://github.com/Dolibarr/dolibarr/blob/57a1f05d490a7a80944a8232e9c613e6556d2704/htdocs/core/triggers/interface_50_modBlockedlog_ActionsBlockedLog.class.php) | Traitement des événements natifs et écriture des journaux. |
| [Dolibarr 24.0.0 — BlockedLog](https://github.com/Dolibarr/dolibarr/blob/769c7db907099643558e77d7002c109cfda919e5/htdocs/blockedlog/class/blockedlog.class.php) | Même socle fonctionnel, événements conditionnels selon les modules et options. |
| [Dolibarr 24.0.0 — ActionsBlockedLog](https://github.com/Dolibarr/dolibarr/blob/769c7db907099643558e77d7002c109cfda919e5/htdocs/core/triggers/interface_99_modBlockedlog_ActionsBlockedLog.class.php) | Évolution du traitement, notamment contrôles de modification/dévalidation ; le comportement interne n’est pas supposé identique à v23. |

Le [modèle fiscal BOI-LETTRE-000242 du 25 mars 2026](https://bofip.impots.gouv.fr/bofip/10692-PGP.html/identifiant=BOI-LETTRE-000242-20260325) prévoit un périmètre et des exclusions propres au logiciel attesté. Le [wiki officiel Dolibarr](https://wiki.dolibarr.org/index.php/Loi_finances_2016_sur_les_logiciels_de_caisse_et_Certification_NF525_ou_LNE) distingue l’attestation de la certification NF525/LNE et souligne l’examen de la solution complète, extensions comprises lorsqu’elles affectent les encaissements.

Ces lectures étayent une proposition de texte ; elles ne prouvent ni la conformité d’une installation ni la validation juridique d’un émetteur. Le module ne précharge pas de version finale 25.0 à partir de l’alpha.

## Installation et conservation

Les nouvelles entrées sont inactives. Les entrées 23.0/24.0 existantes ne sont complétées que si elles appartiennent à l’entité concernée, sont inactives, ont les deux textes vides et ne sont référencées par aucune attestation. Un seul texte personnalisé, une activation ou une utilisation bloque le préremplissage. Les instantanés des attestations ne sont pas réécrits.

Les textes sont enregistrés dans la langue de l’administration au moment du premier préremplissage et restent ensuite éditables par l’émetteur. Une réactivation ne retraduit pas et ne remplace pas les textes persistés.
