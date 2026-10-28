<?php

declare(strict_types=1);

namespace EntityGenerator;

final class Config
{
    public function __construct(
        public readonly string $schema = 'public',
        public readonly string $output = 'generated/Entity',
        public readonly string $namespace = 'App\\Entity',
        public readonly array $exclude = ['doctrine_migration_versions'],
        public readonly bool $api = false,
        public readonly array $operations = ['Get', 'GetCollection'],
        public readonly array $readFields = [],
        public readonly array $writeFields = [],
        public readonly string $readGroup = 'entity:read',
        public readonly string $writeGroup = 'entity:write',
    ) {
        if ($schema === '' || $output === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $namespace)) {
            throw new \InvalidArgumentException('Schéma, sortie ou namespace invalide.');
        }
        foreach (explode('\\', $namespace) as $part) {
            if (Names::reserved($part)) {
                throw new \InvalidArgumentException('Mot réservé dans le namespace.');
            }
        }
        if ($api && ($operations === [] || array_diff($operations, ['Get', 'GetCollection', 'Post', 'Patch', 'Delete']))) {
            throw new \InvalidArgumentException('Opérations API autorisées : Get, GetCollection, Post, Patch, Delete.');
        }
        if ($readGroup === '' || $writeGroup === '' || $readGroup === $writeGroup) {
            throw new \InvalidArgumentException('Les groupes de lecture et écriture doivent être distincts et non vides.');
        }
    }

    public static function environment(bool $api): self
    {
        $env = static fn (string $name, string $default): string => ($v = getenv($name)) === false ? ($_ENV[$name] ?? $default) : $v;
        $list = static fn (string $v): array => array_values(array_unique(array_filter(array_map('trim', explode(',', $v)), static fn ($s) => $s !== '')));

        return new self(
            schema: $env('DB_SCHEMA', 'public'),
            output: $env('ENTITY_OUTPUT', 'generated/Entity'),
            namespace: $env('ENTITY_NAMESPACE', 'App\\Entity'),
            exclude: $list($env('EXCLUDE_TABLES', 'doctrine_migration_versions')),
            api: $api,
            operations: $list($env('API_OPERATIONS', 'Get,GetCollection')),
            readFields: $list($env('API_READ_FIELDS', '')),
            writeFields: $list($env('API_WRITE_FIELDS', '')),
            readGroup: $env('API_READ_GROUP', 'entity:read'),
            writeGroup: $env('API_WRITE_GROUP', 'entity:write'),
        );
    }
}
