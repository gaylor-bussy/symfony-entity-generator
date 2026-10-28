<?php

declare(strict_types=1);

use EntityGenerator\Schema;

function column(string $type = 'int4', bool $nullable = false, array $extra = []): array
{
    return $extra + ['type' => $type, 'nullable' => $nullable, 'identity' => false, 'sequence' => null, 'generated' => false, 'default' => null, 'length' => null, 'precision' => null, 'scale' => null];
}

function table(array $columns, array $primary = ['id'], array $foreign = [], array $unique = []): array
{
    return ['columns' => $columns, 'primary' => $primary, 'foreign' => $foreign, 'unique' => $unique];
}

function fk(string $column, string $target, string $name = '', string $reference = 'id'): array
{
    return ['name' => $name ?: $column.'_fk', 'columns' => [$column], 'schema' => 'public', 'target' => $target, 'references' => [$reference], 'onDelete' => 'NO ACTION'];
}

function fixture(): Schema
{
    $id = column(extra: ['identity' => true]);
    return new Schema('public', [
        'demo_author' => table(['id' => $id, 'display_name' => column('varchar', extra: ['length' => 120]), 'password_hash' => column('text'), 'mentor_id' => column(nullable: true)], foreign: [fk('mentor_id', 'demo_author')]),
        'demo_article' => table(['id' => $id, 'title' => column('text'), 'author_id' => column(), 'reviewer_id' => column(nullable: true), 'price' => column('numeric', extra: ['precision' => 12, 'scale' => 2]), 'published' => column('bool'), 'uuid' => column('uuid'), 'payload' => column('jsonb', true), 'binary_data' => column('bytea', true), 'published_at' => column('timestamptz', true), 'created_at' => column('timestamp'), 'day' => column('date'), 'large_number' => column('int8')], foreign: [fk('author_id', 'demo_author'), fk('reviewer_id', 'demo_author')], unique: ['article_title_author' => ['title', 'author_id']]),
        'demo_profile' => table(['id' => $id, 'author_id' => column(nullable: true)], foreign: [fk('author_id', 'demo_author')], unique: ['profile_author' => ['author_id']]),
        'demo_passport' => table(['id' => $id, 'author_id' => column()], foreign: [fk('author_id', 'demo_author')], unique: ['passport_author' => ['author_id']]),
        'demo_tag' => table(['id' => $id, 'label' => column('text')]),
        'demo_article_tag' => table(['article_id' => column(), 'tag_id' => column()], ['article_id', 'tag_id'], [fk('article_id', 'demo_article'), fk('tag_id', 'demo_tag')]),
        'demo_follow' => table(['follower_id' => column(), 'followed_id' => column()], ['follower_id', 'followed_id'], [fk('follower_id', 'demo_author'), fk('followed_id', 'demo_author')]),
        'demo_membership' => table(['author_id' => column(), 'tag_id' => column(), 'note' => column('text')], ['author_id', 'tag_id'], [fk('author_id', 'demo_author'), fk('tag_id', 'demo_tag')]),
        'demo_translation' => table(['article_id' => column(), 'locale' => column('varchar', extra: ['length' => 8]), 'title' => column('text')], ['article_id', 'locale'], [fk('article_id', 'demo_article')]),
        'demo_region' => table(['country' => column('varchar', extra: ['length' => 2]), 'code' => column('int4')], ['country', 'code']),
        'demo_office' => table(['id' => $id, 'region_country' => column('varchar', extra: ['length' => 2]), 'region_code' => column()], foreign: [['name' => 'region_fk', 'columns' => ['region_country', 'region_code'], 'schema' => 'public', 'target' => 'demo_region', 'references' => ['country', 'code'], 'onDelete' => 'NO ACTION']], unique: ['office_region' => ['region_country', 'region_code']]),
        'odd table' => table(['id' => $id, 'first-name' => column('text'), 'first_name' => column('text'), 'class' => column('text'), "quote'column" => column('text')]),
        'odd_table' => table(['id' => $id]),
        'demo_serial' => table(['id' => column(extra: ['sequence' => 'public.demo_serial_id_seq', 'default' => "nextval('public.demo_serial_id_seq'::regclass)"]), 'text' => column('text')]),
    ]);
}
