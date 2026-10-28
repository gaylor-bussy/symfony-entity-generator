# Décisions et validation

## 1. Audit du fichier de référence

Audit statique présenté avant toute modification. Le fichier original reste intact. Les principaux défauts observés étaient la suppression automatique d'entités, les empreintes ne vérifiant pas le contenu réellement écrit, la réduction systématique des PK composites à des jointures, l'aplatissement des uniques composites, les FK composites non appariées et les noms d'inverses supposés. Le script utilisait des chemins et un namespace fixes.

Ses principes utiles sont conservés dans la nouvelle conception : configuration de connexion, lecture des métadonnées, attributs ORM, collections, précision des décimaux et comparaison par empreintes. Aucun code métier ni schéma de l'ancien contexte n'est utilisé dans les fixtures.

## 2. Générateur générique

Fichiers : `src/Config.php`, `src/Schema.php`, `src/SchemaInspector.php`, `src/PostgresInspector.php`, `src/Names.php`, `src/TypeResolver.php`, `src/Generator.php`, `src/SafeWriter.php`, `bin/entity-generator`.

Décisions : trois responsabilités principales distinctes (inspection, génération, écriture), modèle de tableaux documenté par les fixtures, résolution globale des noms et relations avant rendu. Les cas non représentables bloquent le lot entier. Les defaults SQL ne deviennent pas des valeurs PHP. Les cascades métier ne sont pas inventées.

La détection de jointure est volontairement conservatrice. Les associations comportant des attributs supplémentaires restent des entités. La génération composite est testée plutôt que systématiquement exclue.

## 3. Validation

Fichiers : `tests/fixtures.php`, `tests/run.php`, `tests/postgres.php`, `examples/demo.sql`, `compose.yaml`, `.github/workflows/tests.yml`.

Les tests hors ligne valident la syntaxe et les métadonnées ORM. Le test PostgreSQL fait un véritable `persist`, `flush`, rechargement et lecture des collections, puis annule les insertions. Cela complète la validation statique : un attribut peut être accepté par les métadonnées et produire malgré tout un SQL incorrect. Le test d'intégration a notamment permis de corriger la citation différente du schéma dans `Table` et `JoinTable` par la stratégie de citation Doctrine.

L'écriture est testée en aperçu, sur fichiers inchangés, sur fichiers personnels inconnus, sur modifications manuelles, avec sauvegarde forcée, sur manifeste corrompu et en présence d'un journal interrompu. Les sorties ne sont jamais nettoyées par recherche d'entités orphelines.

Un schéma supplémentaire avec espaces, contraintes homonymes et index unique autonome est créé par le test d'intégration, puis supprimé après validation. Il a révélé une limite du chargement interne des collections ManyToMany de Doctrine : dans ce cas, le générateur conserve une entité de jointure avec un avertissement, plutôt que de produire une collection inutilisable.

## 4. API Platform

Fichiers : options de `Config`, rendu optionnel dans `Generator`, tests API dans `tests/run.php`, `.env.example`.

Les cinq opérations sont instanciées et leurs métadonnées vérifiées avec les vraies dépendances API Platform. Une sérialisation Symfony effective vérifie qu'un champ sans groupe ne fuit pas. Les groupes reposent sur des listes explicites et distinctes, sans heuristique de confidentialité. L'API ne fournit pas de politique d'accès.

## 5. Livraison

Fichiers : `README.md`, `composer.json`, `composer.lock`, `.env.example`, `.gitignore`, `.gitattributes`, `.editorconfig`, `LICENSE`, exemple généré et cette note.

Le runtime ne dépend que de Dotenv ; ORM, DBAL, Cache, Serializer et API Platform sont des dépendances de développement pour valider la sortie. `composer install --no-dev` permet un usage minimal. Le dépôt est publié à la demande de son propriétaire sur [gaylor-bussy/symfony-entity-generator](https://github.com/gaylor-bussy/symfony-entity-generator). Les limitations techniques sont documentées dans le README ; les versions testées localement ne constituent pas une validation de toutes les versions permises par Composer.

### Validation locale du 28 octobre 2026

Environnement : PHP 8.2.12, PostgreSQL 16.15 de démonstration, ORM 3.7.5, DBAL 3.10.6, API Platform 4.4.6 et composants Symfony 7.4 (versions exactes dans le lock).

- `composer validate --strict` : manifeste Composer valide.
- `composer test` : 17 scénarios hors ligne, dont validation des mappings et sérialisation réelle.
- `composer test:postgres` : inspection et persistance sur base fictive, puis scénario dans un schéma aux noms atypiques.
- `php -l` : sources, commande, tests et exemple généré.
- CLI : génération de 12 entités, seconde génération inchangée et aperçu API.

L'extension `pdo_pgsql` déjà installée avec XAMPP a été activée localement, après sauvegarde de `php.ini`. La base portable utilisée pour les vérifications n'appartient pas aux fichiers publiables du projet. Le fichier `.env` local contient uniquement la configuration fictive de `.env.example`.
