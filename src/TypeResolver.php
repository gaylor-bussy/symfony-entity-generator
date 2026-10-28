<?php

declare(strict_types=1);

namespace EntityGenerator;

final class TypeResolver
{
    /** DBAL 3.10 : bigint=string, decimal=string, blob=resource, json=mixed. */
    public function resolve(array $column): array
    {
        $mapping = [
            'int2' => ['smallint', 'int'], 'int4' => ['integer', 'int'], 'int8' => ['bigint', 'string'],
            'float4' => ['float', 'float'], 'float8' => ['float', 'float'], 'numeric' => ['decimal', 'string'],
            'varchar' => ['string', 'string'], 'bpchar' => ['string', 'string'], 'text' => ['text', 'string'],
            'bool' => ['boolean', 'bool'], 'uuid' => ['guid', 'string'], 'bytea' => ['blob', 'mixed'],
            'json' => ['json', 'mixed'], 'jsonb' => ['json', 'mixed'],
            'date' => ['date_immutable', '\\DateTimeImmutable'], 'timestamp' => ['datetime_immutable', '\\DateTimeImmutable'],
            'timestamptz' => ['datetimetz_immutable', '\\DateTimeImmutable'], 'time' => ['time_immutable', '\\DateTimeImmutable'],
        ];
        $type = $column['type'];
        if (!isset($mapping[$type])) {
            throw new \RuntimeException('Type PostgreSQL non pris en charge : '.$type.'. Aucun repli silencieux.');
        }
        if ($type === 'numeric' && (($column['precision'] ?? null) === null || ($column['scale'] ?? 0) < 0 || ($column['scale'] ?? 0) > $column['precision'])) {
            throw new \RuntimeException('numeric sans précision ou à échelle non représentable : mapping manuel requis.');
        }
        [$dbal, $php] = $mapping[$type];
        $args = ['type' => $dbal, 'nullable' => $column['nullable']];
        if (in_array($type, ['varchar', 'bpchar'], true)) {
            if (($column['length'] ?? null) === null) {
                // VARCHAR non borné reste explicite dans le DDL, sans longueur fictive.
                $args['columnDefinition'] = $type === 'varchar' ? 'VARCHAR' : 'CHAR';
            } else {
                $args['length'] = $column['length'];
            }
        }
        if ($type === 'numeric') {
            $args['precision'] = $column['precision'];
            $args['scale'] = $column['scale'];
        }
        if ($type === 'jsonb') {
            $args['options'] = ['jsonb' => true];
        } elseif ($type === 'bpchar') {
            $args['options'] = ['fixed' => true];
        }

        return ['php' => $php, 'args' => $args];
    }
}
