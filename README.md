# PostgreSQL Entity Generator

Générateur autonome d'entités Doctrine à partir d'une base PostgreSQL existante. Il produit des classes PHP avec attributs ORM, propriétés typées, accesseurs et relations bidirectionnelles, sans application Symfony installée dans ce dépôt.

L'objectif est de proposer une alternative indépendante au parcours de rétro-ingénierie de l'ancienne commande `doctrine:mapping:import`. Ce projet est limité à PostgreSQL et n'est pas un outil officiel Symfony ou Doctrine. [`make:entity` ne fait pas l'import d'une base existante](https://symfony.com/doc/7.0/doctrine/reverse_engineering.html).

## Compatibilité

| Composant | Cible |
| --- | --- |
| Exécution du générateur | PHP 8.2+, Composer 2, extensions PDO, pdo_pgsql, iconv, tokenizer |
| Base de démonstration | PostgreSQL 16 |
| Entités produites | Doctrine ORM 3.5+ dans la branche 3, **DBAL 3.10.x** |
| Application cible | Symfony 7.4 ; framework non requis pour le générateur |
| Ressources API optionnelles | API Platform 4.2+ dans la branche 4, Serializer Symfony 7.4 |

Les versions effectivement installées sont verrouillées dans `composer.lock`. DBAL 4 n'est pas annoncé compatible : la conversion de certains types PHP diffère, notamment pour `bigint`. La CI prévoit PHP 8.2, 8.3 et 8.4 ; les commandes de validation locale sont décrites plus bas.

## Installation

```bash
git clone <URL-du-depot>
cd <repertoire-du-depot>
composer install
cp .env.example .env
```

Sous PowerShell, remplacer la dernière commande par :

```powershell
Copy-Item .env.example .env
```

Pour utiliser uniquement le générateur, `composer install --no-dev` suffit. Les dépendances Doctrine et API Platform du dépôt servent à vérifier les fichiers produits ; l'application destinataire doit installer ses propres dépendances.

Vérifier le pilote :

```bash
php --ini
php -m
```

Sous Windows/XAMPP, activer `extension=pdo_pgsql` dans le `php.ini` chargé par la CLI et vérifier le chemin `extension_dir`. Sous Debian/Ubuntu, installer le paquet `php-pgsql` correspondant à la version de PHP. Une activation ponctuelle est possible si la DLL est déjà installée : `php -d extension=pdo_pgsql bin/entity-generator --help`.

## Configuration

Modifier `.env` avec une base dont vous êtes autorisé à lire le schéma. Le générateur ne charge aucune donnée des tables et n'exécute aucun DDL ni aucune migration. Il utilise une transaction de métadonnées en lecture seule, avec isolation `REPEATABLE READ`.

```dotenv
DATABASE_URL="postgresql://utilisateur:mot_de_passe@127.0.0.1:5432/ma_base?sslmode=require"
DB_SCHEMA=public
ENTITY_OUTPUT=generated/Entity
ENTITY_NAMESPACE="App\\Entity"
EXCLUDE_TABLES=doctrine_migration_versions,spatial_ref_sys
```

| Variable | Rôle |
| --- | --- |
| `DATABASE_URL` | Connexion PostgreSQL ; encoder les caractères spéciaux du nom d'utilisateur et du mot de passe dans l'URL |
| `DB_SCHEMA` | Schéma exact, `public` par défaut ; ne pas ajouter de guillemets SQL |
| `ENTITY_OUTPUT` | Dossier relatif au répertoire courant, ou chemin absolu |
| `ENTITY_NAMESPACE` | Namespace des classes produites |
| `EXCLUDE_TABLES` | Liste exacte séparée par des virgules ; une valeur vide désactive les exclusions par défaut |

Les variables d'environnement du processus priment sur le fichier `.env`. Les paramètres d'URL acceptés sont `sslmode`, `connect_timeout`, `sslrootcert`, `sslcert` et `sslkey`. Les autres paramètres provoquent une erreur explicite. Les secrets ne sont pas inscrits dans le manifeste de génération.

Pour charger une autre configuration :

```bash
php bin/entity-generator --env=/chemin/vers/generateur.env --dry-run
```

Le fichier `.env` et les fichiers générés sont ignorés par Git. Aucun fichier de configuration contenant de vrais identifiants ne doit être publié.

## Utilisation

```bash
# Consulter l'aide, sans connexion à une base
php bin/entity-generator --help

# Inspecter le schéma et prévisualiser les actions, sans écrire de fichier
php bin/entity-generator --dry-run

# Générer les entités
php bin/entity-generator

# Remplacer explicitement les fichiers en conflit, avec sauvegardes
php bin/entity-generator --force
```

Le rapport indique `créé`, `inchangé`, `modifié`, `ignoré (exclusion)` ou `ignoré (conflit manuel)`. Le mode aperçu affiche les actions prévues, pas un diff du code. Codes de sortie : `0` succès, `1` erreur ou conflit lors d'une écriture, `2` conflits détectés en aperçu.

Les entités sont produites dans le dossier configuré, sans création automatique de repository. Pour les utiliser dans Symfony, configurer par exemple `ENTITY_OUTPUT=../mon-application/src/Entity` et conserver `ENTITY_NAMESPACE="App\\Entity"`. Commencer par un aperçu. Le projet Symfony cible doit disposer de Doctrine ORM 3 et DBAL 3.10, par exemple :

```bash
# Dans l'application Symfony cible
composer require symfony/orm-pack 'doctrine/orm:^3.5' 'doctrine/dbal:~3.10.0'
php bin/console doctrine:schema:validate
```

La génération reconstruit un mapping ORM utilisable ; elle ne prétend pas reconstruire à l'identique tout le DDL de la base. Ne pas appliquer automatiquement de migration issue d'un écart de schéma sans l'examiner.

## Protection des fichiers

- Aucun fichier d'entité obsolète n'est supprimé automatiquement.
- Un fichier existant identique au résultat est conservé. Un fichier différent sans référence connue est protégé.
- `.entity-generator.json` conserve l'empreinte SHA-256 réellement écrite pour chaque fichier, ainsi que les empreintes du schéma, des sources du générateur et de sa configuration. Une modification manuelle est détectée par comparaison avec le contenu réel.
- En cas de conflit, le lot entier est refusé avant remplacement. `--force` autorise le remplacement et conserve une copie des fichiers précédents.
- Chaque lot modifié est préparé dans des fichiers temporaires ; sa syntaxe est vérifiée avant écriture. Un verrou exclut les générations simultanées dans le même dossier. Les liens symboliques en sortie sont refusés.
- Les sauvegardes restent dans `.entity-generator-backups/<identifiant>/`. Les fichiers sont remplacés par renommage ; une exception provoque une restauration du lot.

Un système de fichiers ne fournit pas de transaction atomique sur un ensemble de fichiers. En cas d'arrêt brutal du processus ou de la machine, `.entity-generator-transaction.json` bloque la génération suivante. Examiner son champ `backup` et sa liste `existed` : restaurer les fichiers précédents depuis cette sauvegarde, retirer seulement les fichiers nouvellement créés par ce lot (`false`), puis retirer le journal après vérification. Le générateur ne tente aucune réparation destructrice implicite. Les sauvegardes n'ont pas de purge automatique.

Le mode `--dry-run` ne crée ni dossier, ni verrou, ni manifeste. Il lit un instantané des fichiers sans réserver leur état pour une exécution ultérieure ; une génération réelle refait les vérifications sous verrou.

## Règles de génération

Les clés étrangères sont groupées par contrainte et leurs colonnes source/cible sont appariées dans leur ordre réel. Une relation `OneToOne` n'est retenue que si la FK est couverte par une unicité complète (ou un sous-ensemble lui-même unique), sinon elle devient `ManyToOne` avec son inverse `OneToMany`. Les index uniques simples non partiels sont également reconnus.

Une table est réduite en `ManyToMany` uniquement dans le cas conservateur suivant : exactement deux colonnes et deux FK simples, colonnes non nulles sans défaut, clé primaire sur leur combinaison, aucune unicité plus restrictive et aucune autre table qui la référence. Une table d'association avec des attributs supplémentaires reste une entité. Les autres cas ne sont pas automatiquement simplifiés. Si le schéma, la table de jointure ou ses colonnes comportent un espace ou une parenthèse ouvrante, la jointure reste aussi une entité, avec avertissement : le chargement interne `ManyToMany` de Doctrine 3 refuse ces critères.

Les noms PHP sont normalisés et désambiguïsés de façon déterministe, y compris les mots réservés et les imports. Les noms SQL d'origine sont conservés explicitement dans les mappings et cités pour PostgreSQL. Les collections utilisent des noms comme `demoArticleViaAuthorCollection` ; les méthodes `addDemoArticleViaAuthorCollection()` et `removeDemoArticleViaAuthorCollection()` évitent les hypothèses linguistiques sur le singulier/pluriel.

Les setters du côté propriétaire mettent à jour le côté inverse. Les collections évitent les doublons et synchronisent leurs méthodes d'ajout/retrait. Retirer un enfant d'une relation obligatoire exige de le réaffecter d'abord ; un refus laisse la relation intacte. Une relation `OneToOne` déjà occupée exige un détachement explicite avant remplacement. Ne pas modifier directement la collection retournée par un getter si l'on souhaite maintenir les deux côtés : passer par `add...()` et `remove...()`.

Les identifiants `IDENTITY` et `SERIAL`/séquences sont reconnus pour les clés simples entières. Ils n'ont pas de setter. Les clés composites assignées sont prises en charge, notamment avec des FK simples identifiantes. Les propriétés scalaires obligatoires doivent être renseignées avant leur lecture et avant `persist()` ; elles ne reçoivent pas de valeur PHP arbitraire. Les relations et identifiants automatiques peuvent être `null` avant initialisation/persistance, même si la base impose ensuite une valeur.

### Types

| PostgreSQL | Doctrine DBAL 3.10 | PHP |
| --- | --- | --- |
| `smallint`, `integer` | `smallint`, `integer` | `int` |
| `bigint` | `bigint` | `string` |
| `numeric(p,s)` | `decimal` | `string` |
| `real`, `double precision` | `float` | `float` |
| `varchar`, `char`, `text` | `string` / `text` | `string` |
| `boolean` | `boolean` | `bool` |
| `uuid` | `guid` | `string` |
| `json`, `jsonb` | `json`, option `jsonb` | `mixed` : JSON peut aussi être scalaire |
| `bytea` | `blob` | `mixed`, garde `resource|string` et nullabilité |
| `date` | `date_immutable` | `DateTimeImmutable` |
| `timestamp` | `datetime_immutable` | `DateTimeImmutable` |
| `timestamptz` | `datetimetz_immutable` | `DateTimeImmutable` |
| `time` sans fuseau | `time_immutable` | `DateTimeImmutable` |

Voir les [conversions DBAL 3.10](https://www.doctrine-project.org/projects/doctrine-dbal/en/3.10/reference/types.html). Aucune expression SQL de valeur par défaut n'est exécutée ou recopiée en initialisation PHP. Les valeurs à fournir sont signalées. Le fuseau métier et la gestion des microsecondes restent à vérifier dans l'application cible.

### Limites explicites

Le lot entier est refusé, avec diagnostic, pour les types inconnus (domaines, enums, tableaux PostgreSQL, géométrie, `interval`, `timetz`, etc.), `numeric` sans précision ou à échelle non représentable, tables sans PK, tables partitionnées, colonnes calculées, identifiants générés composites, FK partagées, FK à nullabilité mixte, FK vers une clé différente de la PK complète, FK composites identifiantes et références à un identifiant lui-même dérivé d'une relation.

Les relations interschémas ne sont pas prises en charge. Une FK vers une table exclue bloque la génération : inclure la cible ou exclure aussi la source. Les noms de table contenant un point ou les identifiants contenant un backtick sont refusés pour éviter les ambiguïtés des métadonnées Doctrine. Les vues et partitions enfants ne sont pas importées.

Les CHECK, contraintes d'exclusion, index partiels ou d'expression, index ordinaires et différabilité restent en base et font l'objet d'avertissements ; ils ne sont pas reconstruits intégralement dans les entités. Les triggers, politiques RLS, règles métier, cascade de persistance, suppression d'orphelins, lifecycle callbacks et héritage ne sont pas déduits. `onDelete` SQL n'est pas une cascade de persistance Doctrine.

## API Platform, optionnel

```dotenv
API_OPERATIONS=Get,GetCollection
API_READ_FIELDS=demo_author.id,demo_author.display_name
API_WRITE_FIELDS=demo_author.display_name
API_READ_GROUP=entity:read
API_WRITE_GROUP=entity:write
```

```bash
php bin/entity-generator --api-platform --dry-run
php bin/entity-generator --api-platform
```

Les opérations acceptées sont `Get`, `GetCollection`, `Post`, `Patch`, `Delete`. Seules les opérations configurées sont créées. Chaque entité reçoit `ApiResource`, avec des contextes de sérialisation distincts en lecture et écriture.

**Les champs autorisés sont sélectionnés explicitement par leur nom SQL `table.colonne`.** Par défaut, les listes sont vides ; aucun groupe n'est ajouté aux propriétés. Un champ inconnu ou une relation dans ces listes provoque une erreur, de même qu'un identifiant généré sélectionné en écriture. Aucun choix n'est fait sur la seule base d'un nom comme `password` ou `token`. Il appartient à l'utilisateur de choisir des champs réellement publics.

L'application cible doit installer API Platform 4 et le Serializer Symfony. Cette option ne configure ni routes d'une application autonome, ni authentification, ni autorisations, ni validation métier. Les relations ne sont pas exposées par les groupes. Pour des opérations d'écriture sur des entités avec relations obligatoires ou champs non exposés, prévoir des DTO, processeurs et règles de validation adaptés avant d'activer ces opérations. Vérifier séparément les identifiants composites dans les routes et formats API.

## Démonstration fictive

Le fichier `examples/demo.sql` ne contient aucune donnée réelle. Il couvre notamment deux FK vers la même cible, des relations autoréférentes, une vraie jointure, une association avec attribut, un identifiant composite et une FK composite.

Avec Docker Desktop démarré :

```bash
docker compose up -d --wait
php bin/entity-generator --env=.env.example --dry-run
php bin/entity-generator --env=.env.example
```

La base écoute uniquement sur `127.0.0.1:55432`, avec le compte fictif `demo`. Le script SQL est exécuté lors de la première initialisation du conteneur. Aucune base personnelle ou professionnelle n'est nécessaire.

Un résultat simple est fourni dans [`examples/generated/DemoTag.php`](examples/generated/DemoTag.php). Les entités complètes de la démonstration apparaissent dans `generated/Entity` après génération.

## Tests

```bash
composer install
composer validate --strict
composer test

# Avec la base Docker fictive initialisée
composer test:postgres
```

`tests/run.php` teste la génération déterministe, la syntaxe PHP, les métadonnées Doctrine sans connexion, la cohérence des relations, les conflits manuels et les sauvegardes, les opérations API et la sérialisation effective des champs autorisés.

`tests/postgres.php` inspecte réellement PostgreSQL, valide les mappings, construit le DDL sans l'appliquer, puis vérifie un aller-retour de persistance/hydratation Doctrine. Les insertions sont annulées en fin de transaction ; les séquences peuvent avancer. Par protection, il ignore `DATABASE_URL` et refuse toute destination autre que `entity_generator_demo` sur `127.0.0.1:55432`. `TEST_DATABASE_URL` peut préciser les identifiants de cette seule destination. Les artefacts de tests restent dans `var/`.

La CI GitHub Actions reprend ces validations sur une base PostgreSQL fictive. Son exécution distante n'est pas présumée avant publication.

## Architecture et provenance

- `Config` : variables d'environnement et options de génération.
- `PostgresInspector` : connexion et inspection en lecture seule, derrière `SchemaInspector`.
- `Schema` : métadonnées internes indépendantes de la connexion et de l'ORM.
- `Names` et `TypeResolver` : noms PHP/SQL et conversions explicites.
- `Generator` : résolution globale des relations, puis production Doctrine et API optionnelle.
- `SafeWriter` : prévalidation, empreintes, conflits, sauvegardes et remplacement.
- `bin/entity-generator` : orchestration CLI et rapport.

Le point d'extension d'inspection permet une évolution ultérieure ; aucun autre SGBD n'est implémenté. Le formatage est déterministe et intégré, sans dépendance à PHP-CS-Fixer ni à un chemin de projet externe.

Cette version est une implémentation indépendante à partir du besoin fonctionnel. Le fichier de référence local `generateur_entities` reste intact et est exclu de Git, car ses droits de redistribution ne sont pas établis. Ne pas le forcer dans le dépôt public. La licence MIT de ce dépôt concerne la nouvelle implémentation ; les dépendances conservent leurs licences propres.
