<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';
require __DIR__.'/fixtures.php';

use EntityGenerator\Config;
use EntityGenerator\Generator;
use EntityGenerator\Names;
use EntityGenerator\SafeWriter;
use EntityGenerator\Schema;
use EntityGenerator\TypeResolver;

$passed = 0;
$failed = 0;
function check(bool $condition, string $message = 'Assertion échouée'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function raises(callable $callback, string $contains): void
{
    try {
        $callback();
    } catch (Throwable $e) {
        check(str_contains($e->getMessage(), $contains), 'Erreur inattendue : '.$e->getMessage());
        return;
    }
    throw new RuntimeException('Exception attendue : '.$contains);
}
function test(string $name, callable $callback): void
{
    global $passed, $failed;
    try {
        $callback();
        ++$passed;
        echo "OK $name\n";
    } catch (Throwable $e) {
        ++$failed;
        echo "ÉCHEC $name : {$e->getMessage()}\n{$e->getTraceAsString()}\n";
    }
}

$root = dirname(__DIR__).'/var/tests-'.bin2hex(random_bytes(5));
mkdir($root, 0775, true);
mkdir($root.'/entities');
$files = (new Generator())->generate(fixture(), new Config(namespace: 'Demo\\Entity'));
foreach ($files as $name => $code) {
    file_put_contents($root.'/entities/'.$name, $code);
    require $root.'/entities/'.$name;
}

test('Syntaxe des entités et génération déterministe', function () use ($files) {
    foreach ($files as $code) {
        token_get_all($code, TOKEN_PARSE);
    }
    check($files === (new Generator())->generate(fixture(), new Config(namespace: 'Demo\\Entity')));
    check(!isset($files['DemoArticleTag.php']));
    check(isset($files['DemoMembership.php'], $files['DemoTranslation.php'], $files['DemoRegion.php']));
    check(isset($files['OddTable.php'], $files['OddTable2.php']));
});
test('Identifiants générés sans setter et composite avec setters', function () {
    check(!method_exists(Demo\Entity\DemoAuthor::class, 'setId'));
    check(method_exists(Demo\Entity\DemoRegion::class, 'setCountry'));
    check(method_exists(Demo\Entity\DemoMembership::class, 'setAuthor'));
});
test('Deux FK vers la même cible, synchronisation et réaffectation obligatoire', function () {
    $a = new Demo\Entity\DemoAuthor();
    $b = new Demo\Entity\DemoAuthor();
    $article = new Demo\Entity\DemoArticle();
    $article->setAuthor($a)->setReviewer($b);
    check($a->getDemoArticleViaAuthorCollection()->contains($article));
    check($b->getDemoArticleViaReviewerCollection()->contains($article));
    raises(fn () => $a->removeDemoArticleViaAuthorCollection($article), 'obligatoire');
    check($a->getDemoArticleViaAuthorCollection()->contains($article));
    $article->setAuthor($b);
    check(!$a->getDemoArticleViaAuthorCollection()->contains($article));
    check($b->getDemoArticleViaAuthorCollection()->contains($article));
    $b->removeDemoArticleViaReviewerCollection($article);
    check($article->getReviewer() === null);
});
test('Relation autoréférente ManyToOne', function () {
    $mentor = new Demo\Entity\DemoAuthor();
    $pupil = new Demo\Entity\DemoAuthor();
    $mentor->addDemoAuthorViaMentorCollection($pupil);
    check($pupil->getMentor() === $mentor);
    $pupil->setMentor(null);
    check($mentor->getDemoAuthorViaMentorCollection()->isEmpty());
});
test('OneToOne nullable, remplacement et occupation', function () {
    $author = new Demo\Entity\DemoAuthor();
    $other = new Demo\Entity\DemoAuthor();
    $profile = new Demo\Entity\DemoProfile();
    $author->setDemoProfileViaAuthor($profile);
    check($profile->getAuthor() === $author);
    raises(fn () => $other->setDemoProfileViaAuthor($profile), 'occupée');
    check($profile->getAuthor() === $author);
    $profile->setAuthor(null);
    check($author->getDemoProfileViaAuthor() === null);
    $profile->setAuthor($other);
    check($other->getDemoProfileViaAuthor() === $profile);
});
test('OneToOne obligatoire : pas de désynchronisation lors du refus', function () {
    $a = new Demo\Entity\DemoAuthor();
    $b = new Demo\Entity\DemoAuthor();
    $passport = new Demo\Entity\DemoPassport();
    $passport->setAuthor($a);
    raises(fn () => $a->setDemoPassportViaAuthor(null), 'obligatoire');
    check($passport->getAuthor() === $a && $a->getDemoPassportViaAuthor() === $passport);
    $passport->setAuthor($b);
    check($a->getDemoPassportViaAuthor() === null && $b->getDemoPassportViaAuthor() === $passport);
});
test('ManyToMany bidirectionnel et absence de doublons', function () {
    $article = new Demo\Entity\DemoArticle();
    $tag = new Demo\Entity\DemoTag();
    $article->addDemoTagViaDemoArticleTagOwner($tag)->addDemoTagViaDemoArticleTagOwner($tag);
    check(count($tag->getDemoArticleViaDemoArticleTagInverse()) === 1);
    $tag->removeDemoArticleViaDemoArticleTagInverse($article);
    check($article->getDemoTagViaDemoArticleTagOwner()->isEmpty());
});
test('ManyToMany autoréférent', function () {
    $a = new Demo\Entity\DemoAuthor();
    $b = new Demo\Entity\DemoAuthor();
    $a->addDemoAuthorViaDemoFollowOwner($b);
    check($b->getDemoAuthorViaDemoFollowInverse()->contains($a));
    $b->removeDemoAuthorViaDemoFollowInverse($a);
    check($a->getDemoAuthorViaDemoFollowOwner()->isEmpty());
});
test('Types précis et refus des types inconnus', function () {
    $r = new TypeResolver();
    check($r->resolve(column('int8'))['php'] === 'string');
    check($r->resolve(column('json'))['php'] === 'mixed');
    check($r->resolve(column('timestamptz'))['args']['type'] === 'datetimetz_immutable');
    raises(fn () => $r->resolve(column('hstore')), 'non pris en charge');
    raises(fn () => $r->resolve(column('numeric')), 'sans précision');
    $a = new Demo\Entity\DemoArticle();
    $a->setPayload('JSON scalaire')->setBinaryData('binary');
    raises(fn () => $a->setBinaryData(42), 'blob');
    $a->setPrice('123456.78')->setLargeNumber('9223372036854775807');
    check($a->getPrice() === '123456.78');
});
test('Contrainte unique composite ne devient pas OneToOne simple', function () use ($files) {
    check(str_contains($files['DemoArticle.php'], '#[ORM\\ManyToOne(targetEntity: DemoAuthor::class'));
    check(str_contains($files['DemoOffice.php'], '#[ORM\\OneToOne(targetEntity: DemoRegion::class'));
});
test('Refus explicite des schémas non représentables', function () {
    $g = new Generator();
    raises(fn () => $g->generate(new Schema('public', ['bad' => table(['name' => column('text')], [])]), new Config()), 'aucune clé primaire');
    raises(fn () => $g->generate(fixture(), new Config(exclude: ['demo_author'])), 'cible hors schéma ou exclue');
    $tables = fixture()->tables;
    $tables['demo_article']['foreign'][0]['references'] = ['display_name'];
    raises(fn () => $g->generate(new Schema('public', $tables), new Config()), 'clé primaire complète');
});
test('Noms réservés des imports, ponctuation et namespace invalide', function () {
    $s = new Schema('public', ['collection' => table(['id' => column()]), 'api_resource' => table(['id' => column()]), 'class' => table(['id' => column()])]);
    $files = (new Generator())->generate($s, new Config());
    check(isset($files['Collection2.php'], $files['ApiResource2.php'], $files['ClassEntity.php']));
    raises(fn () => new Config(namespace: 'App\\class'), 'réservé');
    raises(fn () => Names::quote('bad`name'), 'backtick');
});
test('Écriture protégée, dry-run, conflit, force et conservation des orphelins', function () use ($root) {
    $writer = new SafeWriter();
    $dir = $root.'/writer';
    $first = ['A.php' => '<?php class A {}'];
    check($writer->write($dir, $first, [], true)['A.php'] === 'créé');
    check(!file_exists($dir));
    $writer->write($dir, $first, []);
    check($writer->write($dir, $first, [])['A.php'] === 'inchangé');
    $manual = '<?php class A { public int $manual; }';
    file_put_contents($dir.'/A.php', $manual);
    $next = ['A.php' => '<?php class A { public string $generated; }', 'B.php' => '<?php class B {}'];
    check($writer->write($dir, $next, [], true)['A.php'] === 'ignoré (conflit manuel)');
    raises(fn () => $writer->write($dir, $next, []), 'Conflit manuel');
    check(!file_exists($dir.'/B.php') && file_get_contents($dir.'/A.php') === $manual);
    $writer->write($dir, $next, [], false, true);
    check((bool) array_filter(glob($dir.'/.entity-generator-backups/*/A.php'), fn ($path) => file_get_contents($path) === $manual));
    $writer->write($dir, ['B.php' => $next['B.php']], []);
    check(is_file($dir.'/A.php'));
    file_put_contents($dir.'/.entity-generator-transaction.json', '{}');
    raises(fn () => $writer->write($dir, $next, [], true), 'Transaction interrompue');
});
test('Fichier inconnu, manifeste corrompu et syntaxe invalide protégés', function () use ($root) {
    $writer = new SafeWriter();
    $dir = $root.'/unknown';
    mkdir($dir);
    file_put_contents($dir.'/A.php', '<?php // personnel');
    raises(fn () => $writer->write($dir, ['A.php' => '<?php class A {}'], []), 'Conflit manuel');
    file_put_contents($dir.'/.entity-generator.json', '{');
    raises(fn () => $writer->write($dir, ['A.php' => '<?php class A {}'], []), 'Syntax error');
    raises(fn () => $writer->write($root.'/invalid', ['A.php' => '<?php class {'], []), 'syntax error');
    check(!is_dir($root.'/invalid'));
});
test('Verrou exclusif : une génération concurrente est refusée', function () use ($root) {
    $dir = $root.'/locked';
    mkdir($dir);
    $lock = fopen($dir.'/.entity-generator.lock', 'c');
    check(flock($lock, LOCK_EX | LOCK_NB));
    try {
        raises(fn () => (new SafeWriter())->write($dir, ['A.php' => '<?php class A {}'], []), 'autre génération');
        check(!file_exists($dir.'/A.php'));
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
});
test('Validation réelle des métadonnées Doctrine sans connexion serveur', function () use ($root, $files) {
    check(class_exists(Doctrine\ORM\ORMSetup::class), 'Installer composer install avec les dépendances de développement.');
    $config = Doctrine\ORM\ORMSetup::createAttributeMetadataConfiguration([$root.'/entities'], true);
    $connection = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '16.0', 'dbname' => 'offline_demo']);
    $em = new Doctrine\ORM\EntityManager($connection, $config);
    $metadata = $em->getMetadataFactory()->getAllMetadata();
    check(count($metadata) === count($files));
    $errors = (new Doctrine\ORM\Tools\SchemaValidator($em))->validateMapping();
    check($errors === [], json_encode($errors, JSON_PRETTY_PRINT));
    $odd = $em->getClassMetadata(Demo\Entity\OddTable::class);
    check($odd->getTableName() === 'odd table' && $odd->table['quoted']);
    check(!$connection->isConnected(), 'La validation des mappings doit rester hors ligne.');
});
test('API Platform désactivé par défaut et listes explicites', function () use ($root, $files) {
    check(!str_contains(implode('', $files), 'ApiResource'));
    $config = new Config(namespace: 'Demo\\Api', api: true, operations: ['Get', 'GetCollection', 'Post', 'Patch', 'Delete'], readFields: ['demo_author.id', 'demo_author.display_name'], writeFields: ['demo_author.display_name']);
    $api = (new Generator())->generate(fixture(), $config);
    $dir = $root.'/api';
    mkdir($dir);
    foreach ($api as $name => $code) {
        token_get_all($code, TOKEN_PARSE);
        file_put_contents($dir.'/'.$name, $code);
        require $dir.'/'.$name;
    }
    $reflection = new ReflectionClass(Demo\Api\DemoAuthor::class);
    $resource = $reflection->getAttributes(ApiPlatform\Metadata\ApiResource::class)[0]->newInstance();
    check(count($resource->getOperations()) === 5);
    check($reflection->getProperty('passwordHash')->getAttributes(Symfony\Component\Serializer\Annotation\Groups::class) === []);
    $serializer = new Symfony\Component\Serializer\Serializer([new Symfony\Component\Serializer\Normalizer\ObjectNormalizer(new Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory(new Symfony\Component\Serializer\Mapping\Loader\AttributeLoader()))]);
    $author = (new Demo\Api\DemoAuthor())->setDisplayName('Fictif')->setPasswordHash('secret fictif');
    $data = $serializer->normalize($author, context: ['groups' => ['entity:read']]);
    check(array_keys($data) === ['id', 'displayName']);
    raises(fn () => (new Generator())->generate(fixture(), new Config(api: true, readFields: ['demo_author.typo'])), 'Champ API inconnu');
    raises(fn () => (new Generator())->generate(fixture(), new Config(api: true, writeFields: ['demo_author.id'])), 'Identifiant généré interdit');
    raises(fn () => new Config(api: true, operations: ['Put']), 'Opérations API');
});

echo "\n$passed test(s) réussi(s), $failed échec(s). Artefacts : $root\n";
exit($failed ? 1 : 0);
