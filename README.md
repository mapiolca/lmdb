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

Les onglets internes disponibles sont :

- Configuration ;
- Compatibilité ;
- À propos.

## Licence

GPL-3.0-or-later.
