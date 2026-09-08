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
