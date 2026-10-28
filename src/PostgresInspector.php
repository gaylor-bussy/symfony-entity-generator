<?php

declare(strict_types=1);

namespace EntityGenerator;

final class PostgresInspector implements SchemaInspector
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public static function connect(string $url): \PDO
    {
        $p = parse_url($url);
        if ($p === false || !in_array($p['scheme'] ?? '', ['postgres', 'postgresql'], true) || empty($p['host']) || empty($p['path'])) {
            throw new \RuntimeException('DATABASE_URL doit être une URL PostgreSQL avec hôte et base.');
        }
        parse_str($p['query'] ?? '', $query);
        if (array_diff(array_keys($query), ['sslmode', 'connect_timeout', 'sslrootcert', 'sslcert', 'sslkey'])) {
            throw new \RuntimeException('Paramètre DATABASE_URL non pris en charge (voir README).');
        }
        $params = array_replace(['host' => trim($p['host'], '[]'), 'port' => $p['port'] ?? 5432, 'dbname' => rawurldecode(ltrim($p['path'], '/')), 'connect_timeout' => 5], $query);
        $dsn = 'pgsql:';
        foreach ($params as $key => $value) {
            if (preg_match('/[;\x00-\x1f]/', (string) $value)) {
                throw new \RuntimeException('Paramètre de connexion invalide.');
            }
            $dsn .= $key.'='.$value.';';
        }
        // Ne jamais imprimer l'URL ou une exception PDO contenant les paramètres.
        try {
            return new \PDO($dsn, rawurldecode($p['user'] ?? ''), rawurldecode($p['pass'] ?? ''), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        } catch (\PDOException) {
            throw new \RuntimeException('Connexion PostgreSQL impossible. Vérifier le service, pdo_pgsql et la configuration.');
        }
    }

    public function inspect(string $schema): Schema
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $tables = [];
            foreach ($this->rows("SELECT c.relname, c.relkind FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=:schema AND c.relkind IN ('r','p') AND NOT c.relispartition ORDER BY c.relname", $schema) as $row) {
                $tables[$row['relname']] = ['columns' => [], 'primary' => [], 'unique' => [], 'foreign' => [], 'unsupported' => $row['relkind'] === 'p' ? ['table partitionnée'] : [], 'warnings' => []];
            }
            foreach ($this->rows(<<<'SQL'
                SELECT c.table_name, c.column_name, c.udt_name AS type, c.udt_schema,
                       c.is_nullable, c.column_default, c.is_identity, c.is_generated,
                       c.character_maximum_length AS length, c.numeric_precision AS precision,
                       c.numeric_scale AS scale,
                       pg_catalog.pg_get_serial_sequence(quote_ident(c.table_schema)||'.'||quote_ident(c.table_name), c.column_name) AS sequence
                FROM information_schema.columns c WHERE c.table_schema=:schema ORDER BY c.table_name,c.ordinal_position
                SQL, $schema) as $c) {
                if (!isset($tables[$c['table_name']])) {
                    continue;
                }
                $tables[$c['table_name']]['columns'][$c['column_name']] = [
                    'type' => $c['udt_schema'] === 'pg_catalog' ? $c['type'] : $c['udt_schema'].'.'.$c['type'],
                    'nullable' => $c['is_nullable'] === 'YES', 'default' => $c['column_default'],
                    'identity' => $c['is_identity'] === 'YES', 'generated' => $c['is_generated'] !== 'NEVER',
                    'sequence' => $c['sequence'], 'length' => $c['length'] === null ? null : (int) $c['length'],
                    'precision' => $c['precision'] === null ? null : (int) $c['precision'], 'scale' => $c['scale'] === null ? null : (int) $c['scale'],
                ];
            }
            $sql = <<<'SQL'
                SELECT c.relname AS source, k.conname, k.contype, nt.nspname AS target_schema,
                       ct.relname AS target, k.confdeltype, k.condeferrable,
                       COALESCE((SELECT json_agg(a.attname ORDER BY u.ord) FROM unnest(k.conkey) WITH ORDINALITY u(num,ord) JOIN pg_catalog.pg_attribute a ON a.attrelid=k.conrelid AND a.attnum=u.num), '[]') AS columns,
                       COALESCE((SELECT json_agg(a.attname ORDER BY u.ord) FROM unnest(k.confkey) WITH ORDINALITY u(num,ord) JOIN pg_catalog.pg_attribute a ON a.attrelid=k.confrelid AND a.attnum=u.num), '[]') AS target_columns
                FROM pg_catalog.pg_constraint k
                JOIN pg_catalog.pg_class c ON c.oid=k.conrelid
                JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
                LEFT JOIN pg_catalog.pg_class ct ON ct.oid=k.confrelid
                LEFT JOIN pg_catalog.pg_namespace nt ON nt.oid=ct.relnamespace
                WHERE n.nspname=:schema ORDER BY c.relname,k.conname
                SQL;
            foreach ($this->rows($sql, $schema) as $k) {
                if (!isset($tables[$k['source']])) {
                    continue;
                }
                $t = &$tables[$k['source']];
                if ($k['condeferrable']) {
                    $t['warnings'][] = 'Contrainte différable '.$k['conname'].' : différabilité conservée en base uniquement.';
                }
                $cols = json_decode($k['columns'], true, flags: JSON_THROW_ON_ERROR);
                if ($k['contype'] === 'p') {
                    $t['primary'] = $cols;
                } elseif ($k['contype'] === 'u') {
                    $t['unique'][$k['conname']] = $cols;
                } elseif ($k['contype'] === 'f') {
                    $t['foreign'][] = ['name' => $k['conname'], 'columns' => $cols, 'target' => $k['target'], 'schema' => $k['target_schema'], 'references' => json_decode($k['target_columns'], true, flags: JSON_THROW_ON_ERROR), 'onDelete' => ['a' => 'NO ACTION', 'r' => 'RESTRICT', 'c' => 'CASCADE', 'n' => 'SET NULL', 'd' => 'SET DEFAULT'][$k['confdeltype']]];
                } else {
                    $t['warnings'][] = 'Contrainte '.$k['conname'].' conservée en base uniquement (CHECK/exclusion).';
                }
                unset($t);
            }
            foreach ($this->rows(<<<'SQL'
                SELECT c.relname, ic.relname AS index_name, i.indisunique,
                       i.indpred IS NOT NULL AS partial, i.indexprs IS NOT NULL AS expression,
                       (SELECT json_agg(a.attname ORDER BY u.ord)
                        FROM unnest(i.indkey) WITH ORDINALITY u(num,ord)
                        JOIN pg_catalog.pg_attribute a ON a.attrelid=i.indrelid AND a.attnum=u.num
                        WHERE u.ord <= i.indnkeyatts) AS columns
                FROM pg_catalog.pg_index i
                JOIN pg_catalog.pg_class c ON c.oid=i.indrelid
                JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
                JOIN pg_catalog.pg_class ic ON ic.oid=i.indexrelid
                WHERE n.nspname=:schema AND i.indisvalid AND NOT EXISTS (
                    SELECT 1 FROM pg_catalog.pg_constraint k
                    WHERE k.conindid=i.indexrelid AND k.conrelid=i.indrelid AND k.contype IN ('p','u','x')
                )
                ORDER BY c.relname,ic.relname
                SQL, $schema) as $index) {
                if (isset($tables[$index['relname']])) {
                    if ($index['indisunique'] && !$index['partial'] && !$index['expression']) {
                        $tables[$index['relname']]['unique'][$index['index_name']] = json_decode($index['columns'], true, flags: JSON_THROW_ON_ERROR);
                    } else {
                        $tables[$index['relname']]['warnings'][] = 'Index autonome '.$index['index_name'].' non exporté ; conservé en base (aucune cardinalité déduite des index partiels).';
                    }
                }
            }
            if ($tables === []) {
                throw new \RuntimeException('Aucune table visible dans le schéma demandé. Vérifier le nom et les droits.');
            }
            $this->pdo->commit();

            return new Schema($schema, $tables);
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function rows(string $sql, string $schema): array
    {
        $q = $this->pdo->prepare($sql);
        $q->execute(['schema' => $schema]);

        return $q->fetchAll(\PDO::FETCH_ASSOC);
    }
}
