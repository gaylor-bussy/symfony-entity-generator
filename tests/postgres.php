<?php

declare(strict_types=1);

// Ce test n'accepte QUE la base de démonstration locale, jamais DATABASE_URL.
require dirname(__DIR__).'/bootstrap.php';

use EntityGenerator\Config;
use EntityGenerator\Generator;
use EntityGenerator\PostgresInspector;
use EntityGenerator\SafeWriter;

$url = getenv('TEST_DATABASE_URL') ?: 'postgresql://demo:demo@127.0.0.1:55432/entity_generator_demo?sslmode=disable';
$parts = parse_url($url);
if (($parts['host'] ?? '') !== '127.0.0.1' || ($parts['port'] ?? 0) !== 55432 || ($parts['path'] ?? '') !== '/entity_generator_demo') {
    fwrite(STDERR, "Test refusé : seule la base entity_generator_demo sur 127.0.0.1:55432 est autorisée.\n");
    exit(1);
}
$connection = null;
try {
    $pdo = PostgresInspector::connect($url);
    $schema = (new PostgresInspector($pdo))->inspect('public');
    $generator = new Generator();
    $files = $generator->generate($schema, new Config(namespace: 'Integration\\Entity'));
    if (count($files) !== 12 || isset($files['DemoArticleTag.php']) || !isset($files['DemoMembership.php'])) {
        throw new RuntimeException('Détection des tables et jointures inattendue.');
    }
    $dir = dirname(__DIR__).'/var/postgres-'.bin2hex(random_bytes(5));
    (new SafeWriter())->write($dir, $files, ['integration' => true]);
    foreach ($files as $name => $_) {
        require $dir.'/'.$name;
    }
    $config = Doctrine\ORM\ORMSetup::createAttributeMetadataConfiguration([$dir], true);
    $connection = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'port' => 55432, 'dbname' => 'entity_generator_demo', 'user' => rawurldecode($parts['user'] ?? 'demo'), 'password' => rawurldecode($parts['pass'] ?? 'demo'), 'serverVersion' => '16.0']);
    $em = new Doctrine\ORM\EntityManager($connection, $config);
    $metadata = $em->getMetadataFactory()->getAllMetadata();
    $errors = (new Doctrine\ORM\Tools\SchemaValidator($em))->validateMapping();
    if ($errors) {
        throw new RuntimeException(json_encode($errors, JSON_PRETTY_PRINT));
    }
    // Vérifie aussi que Doctrine peut construire le DDL (sans l'exécuter).
    (new Doctrine\ORM\Tools\SchemaTool($em))->getCreateSchemaSql($metadata);
    echo "OK introspection PostgreSQL, 12 entités, mappings Doctrine et construction du DDL\n";

    $connection->beginTransaction();
    $author = (new Integration\Entity\DemoAuthor())->setDisplayName('Auteur fictif')->setPasswordHash('exemple sans secret');
    $article = (new Integration\Entity\DemoArticle())->setTitle('Article fictif')->setAuthor($author)->setReviewer($author)
        ->setPrice('1234567890.12')->setPublished(true)->setUuid('123e4567-e89b-12d3-a456-426614174000')
        ->setPayload('scalaire JSON')->setBinaryData("\x00\x01fictif")
        ->setPublishedAt(new DateTimeImmutable('2026-01-01T12:00:00+00:00'))
        ->setCreatedAt(new DateTimeImmutable('2026-01-01T12:00:00'))
        ->setDay(new DateTimeImmutable('2026-01-01'))->setLargeNumber('9223372036854775807');
    $tag = (new Integration\Entity\DemoTag())->setLabel('Test');
    $article->addDemoTagViaDemoArticleTagOwner($tag);
    $profile = (new Integration\Entity\DemoProfile())->setAuthor($author);
    $membership = (new Integration\Entity\DemoMembership())->setAuthor($author)->setTag($tag)->setNote('Attribut de la jointure conservé');
    $translation = (new Integration\Entity\DemoTranslation())->setArticle($article)->setLocale('fr')->setTitle('Fictif');
    $region = (new Integration\Entity\DemoRegion())->setCountry('FR')->setCode(42);
    $office = new Integration\Entity\DemoOffice();
    $officeMeta = $em->getClassMetadata($office::class);
    $officeSetter = 'set'.ucfirst(array_key_first($officeMeta->associationMappings));
    $office->$officeSetter($region);
    $serial = (new Integration\Entity\DemoSerial())->setText('Séquence fictive');
    foreach ([$author, $tag, $article, $profile, $membership, $translation, $region, $office, $serial] as $object) {
        $em->persist($object);
    }
    $em->flush();
    $articleId = $article->getId();
    $em->clear();
    $loaded = $em->find(Integration\Entity\DemoArticle::class, $articleId);
    if ($loaded->getPrice() !== '1234567890.12' || $loaded->getLargeNumber() !== '9223372036854775807' || $loaded->getPayload() !== 'scalaire JSON' || !$loaded->getPublished() || !is_resource($loaded->getBinaryData()) || count($loaded->getDemoTagViaDemoArticleTagOwner()) !== 1) {
        throw new RuntimeException('Échec de l’aller-retour DBAL/Doctrine.');
    }
    $connection->rollBack();
    echo "OK persist/flush/relecture : relations, composite, identité, séquence, decimal, bigint, bytea, JSON et dates (transaction annulée)\n";

    // Schéma neuf créé par ce test seulement : noms atypiques, contraintes homonymes,
    // unicité provenant d'un index autonome et jointure dans un schéma avec espaces.
    $edgeSchema = 'demo Weird '.bin2hex(random_bytes(4));
    $qs = '"'.str_replace('"', '""', $edgeSchema).'"';
    $pdo->exec('CREATE SCHEMA '.$qs);
    try {
        $pdo->exec('CREATE TABLE '.$qs.'."Owner Table" (id integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, "display\'name" text NOT NULL)');
        $pdo->exec('CREATE TABLE '.$qs.'."Child Table" (id integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, "owner id" integer, CONSTRAINT same_fk FOREIGN KEY ("owner id") REFERENCES '.$qs.'."Owner Table"(id))');
        $pdo->exec('CREATE UNIQUE INDEX child_owner_unique ON '.$qs.'."Child Table" ("owner id")');
        $pdo->exec('CREATE TABLE '.$qs.'."Other Child" (id integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, "owner id" integer, CONSTRAINT same_fk FOREIGN KEY ("owner id") REFERENCES '.$qs.'."Owner Table"(id))');
        $pdo->exec('CREATE TABLE '.$qs.'."Join Table" (owner_id integer NOT NULL REFERENCES '.$qs.'."Owner Table"(id), other_id integer NOT NULL REFERENCES '.$qs.'."Other Child"(id), PRIMARY KEY (owner_id,other_id))');
        $edgeFiles = (new Generator())->generate((new PostgresInspector($pdo))->inspect($edgeSchema), new Config(schema: $edgeSchema, namespace: 'Edge\\Entity'));
        $edgeDir = $dir.'/edge';
        (new SafeWriter())->write($edgeDir, $edgeFiles, []);
        foreach ($edgeFiles as $name => $_) {
            require $edgeDir.'/'.$name;
        }
        $edgeEm = new Doctrine\ORM\EntityManager($connection, Doctrine\ORM\ORMSetup::createAttributeMetadataConfiguration([$edgeDir], true));
        $edgeMetadata = $edgeEm->getMetadataFactory()->getAllMetadata();
        if (count($edgeMetadata) !== 4 || (new Doctrine\ORM\Tools\SchemaValidator($edgeEm))->validateMapping()) {
            throw new RuntimeException('Métadonnées du schéma atypique invalides.');
        }
        $childMeta = $edgeEm->getClassMetadata(Edge\Entity\ChildTable::class);
        if (!$childMeta->associationMappings['ownerId']->isOneToOne()) {
            throw new RuntimeException('Index unique autonome non détecté.');
        }
        $owner = (new Edge\Entity\OwnerTable())->setDisplayName('Nom fictif');
        $other = (new Edge\Entity\OtherChild())->setOwnerId($owner);
        $child = (new Edge\Entity\ChildTable())->setOwnerId($owner);
        $link = (new Edge\Entity\JoinTable())->setOwner($owner)->setOther($other);
        $connection->beginTransaction();
        foreach ([$owner, $other, $child, $link] as $object) {
            $edgeEm->persist($object);
        }
        $edgeEm->flush();
        $id = $owner->getId();
        $edgeEm->clear();
        $loaded = $edgeEm->find(Edge\Entity\OwnerTable::class, $id);
        if ($loaded->getDisplayName() !== 'Nom fictif' || count($loaded->getJoinTableViaOwnerCollection()) !== 1) {
            throw new RuntimeException('Citation du schéma atypique incorrecte.');
        }
        $connection->rollBack();
        echo "OK schéma avec espaces, noms atypiques, contraintes homonymes, index unique et jointure conservée en entité\n";
    } finally {
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        // Suppression limitée au schéma fictif aléatoire créé juste au-dessus.
        $pdo->exec('DROP SCHEMA '.$qs.' CASCADE');
    }
    $connection->close();
} catch (Throwable $e) {
    if ($connection !== null && $connection->isTransactionActive()) {
        $connection->rollBack();
    }
    fwrite(STDERR, 'ÉCHEC PostgreSQL : '.$e->getMessage()."\n".$e->getTraceAsString()."\n");
    exit(1);
}
