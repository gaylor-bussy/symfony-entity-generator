<?php

declare(strict_types=1);

namespace EntityGenerator;

final class Generator
{
    public const VERSION = '1.0.0';
    public array $warnings = [];

    public function generate(Schema $schema, Config $config): array
    {
        $this->warnings = [];
        $tables = array_diff_key($schema->tables, array_flip($config->exclude));
        if ($tables === []) {
            throw new \RuntimeException('Aucune table à générer après exclusions.');
        }
        $errors = [];
        $resolver = new TypeResolver();
        $resolved = [];
        Names::quote($schema->name);
        foreach ($tables as $name => $table) {
            Names::quote($name);
            if (str_contains($name, '.')) {
                $errors[] = "$name : nom de table contenant un point ambigu pour les métadonnées Doctrine.";
            }
            foreach ($table['unsupported'] ?? [] as $reason) {
                $errors[] = "$name : $reason non pris en charge.";
            }
            foreach ($table['warnings'] ?? [] as $reason) {
                $this->warnings[] = "$name : $reason";
            }
            if (!$table['primary']) {
                $errors[] = "$name : aucune clé primaire ; une entité Doctrine exige un identifiant.";
            }
            if (!$table['columns']) {
                $errors[] = "$name : aucune colonne visible ; vérifier les droits de lecture des métadonnées.";
                continue;
            }
            $foreignColumns = [];
            foreach ($table['foreign'] as $fk) {
                if ($fk['schema'] !== $schema->name || !isset($tables[$fk['target']])) {
                    $errors[] = "$name.{$fk['name']} : cible hors schéma ou exclue ; inclure la cible ou exclure la source.";
                    continue;
                }
                $target = $tables[$fk['target']];
                if (!self::sameSet($fk['references'], $target['primary'])) {
                    $errors[] = "$name.{$fk['name']} : Doctrine exige ici une référence à la clé primaire complète.";
                }
                if (array_intersect($foreignColumns, $fk['columns'])) {
                    $errors[] = "$name.{$fk['name']} : colonnes partagées par plusieurs relations non prises en charge.";
                }
                $foreignColumns = array_merge($foreignColumns, $fk['columns']);
                if (array_intersect($table['primary'], $fk['columns']) && count($fk['columns']) !== 1) {
                    $errors[] = "$name.{$fk['name']} : identifiant dérivé d'une FK composite non pris en charge.";
                }
                foreach ($target['foreign'] as $targetFk) {
                    if (array_intersect($target['primary'], $targetFk['columns'])) {
                        $errors[] = "$name.{$fk['name']} : référence à un identifiant lui-même dérivé d'une relation non prise en charge.";
                    }
                }
                $nullable = array_unique(array_map(static fn ($c) => $table['columns'][$c]['nullable'], $fk['columns']));
                if (count($nullable) > 1) {
                    $errors[] = "$name.{$fk['name']} : nullabilité mixte d'une FK composite non prise en charge.";
                }
            }
            foreach ($table['columns'] as $column => $c) {
                Names::quote($column);
                try {
                    $resolved[$name][$column] = $resolver->resolve($c);
                } catch (\RuntimeException $e) {
                    $errors[] = "$name.$column : ".$e->getMessage();
                }
                if ($c['generated'] ?? false) {
                    $errors[] = "$name.$column : colonne calculée non prise en charge.";
                }
                if (($c['identity'] ?? false) || ($c['sequence'] ?? null)) {
                    if (count($table['primary']) !== 1 || $table['primary'][0] !== $column || !in_array($c['type'], ['int2', 'int4', 'int8'], true)) {
                        $errors[] = "$name.$column : génération automatique compatible uniquement avec un identifiant entier simple.";
                    }
                } elseif (($c['default'] ?? null) !== null) {
                    $this->warnings[] = "$name.$column : défaut SQL non transposé en PHP ; fournir une valeur avant persist().";
                    if (in_array($column, $table['primary'], true)) {
                        $errors[] = "$name.$column : identifiant à défaut SQL non pris en charge ; fournir un identifiant sans défaut ou adapter manuellement.";
                    }
                }
                if (in_array($column, $table['primary'], true) && !in_array($c['type'], ['int2', 'int4', 'int8', 'varchar', 'bpchar', 'text', 'uuid'], true)) {
                    $errors[] = "$name.$column : type d'identifiant non représentable par ce générateur.";
                }
            }
        }
        if ($errors) {
            throw new \RuntimeException("Schéma non généré (aucun fichier écrit) :\n- ".implode("\n- ", array_unique($errors)));
        }
        $joins = [];
        foreach ($tables as $name => $table) {
            if (count($table['foreign']) !== 2 || count($table['columns']) !== 2 || count($table['primary']) !== 2) {
                continue;
            }
            [$a, $b] = $table['foreign'];
            if (count($a['columns']) !== 1 || count($b['columns']) !== 1 || !self::sameSet(array_merge($a['columns'], $b['columns']), $table['primary'])) {
                continue;
            }
            $pure = true;
            foreach ($table['columns'] as $c) {
                $pure = $pure && !$c['nullable'] && !($c['default'] ?? null) && !($c['identity'] ?? false);
            }
            foreach ($table['unique'] as $cols) {
                $pure = $pure && self::sameSet($cols, $table['primary']);
            }
            foreach ($tables as $other) {
                foreach ($other['foreign'] as $fk) {
                    if ($fk['target'] === $name) {
                        $pure = false;
                    }
                }
            }
            if ($pure) {
                // ORM 3 refuse les critères internes ManyToMany contenant espace ou '('.
                // Conserver une entité de jointure rend ce schéma utilisable sans stratégie custom.
                if (preg_match('/[ (]/', $schema->name.$name.implode('', array_keys($table['columns'])))) {
                    $this->warnings[] = "$name : jointure conservée en entité (espaces/parenthèses non supportés par le chargement ManyToMany de Doctrine).";
                    continue;
                }
                $joins[$name] = $table;
            }
        }
        $entities = [];
        $classes = array_fill_keys(array_map('strtolower', ['ORM', 'Collection', 'ArrayCollection', 'ApiResource', 'Groups', 'Get', 'GetCollection', 'Post', 'Patch', 'Delete']), true);
        foreach ($tables as $name => $table) {
            if (isset($joins[$name])) {
                continue;
            }
            $entities[$name] = ['class' => Names::allocate(Names::base($name, true), $classes), 'fields' => [], 'relations' => [], 'used' => []];
            $foreign = array_merge([], ...array_column($table['foreign'], 'columns'));
            foreach ($table['columns'] as $column => $c) {
                if (!in_array($column, $foreign, true)) {
                    $entities[$name]['fields'][$column] = ['name' => Names::allocate(Names::base($column), $entities[$name]['used']), 'column' => $c, 'type' => $resolved[$name][$column], 'id' => in_array($column, $table['primary'], true)];
                }
            }
        }
        foreach ($entities as $name => $_) {
            foreach ($tables[$name]['foreign'] as $fk) {
                $target = $fk['target'];
                $base = count($fk['columns']) === 1 ? preg_replace('/_id$/i', '', $fk['columns'][0]) : $fk['name'];
                $owner = Names::allocate(Names::base($base), $entities[$name]['used']);
                $one = false;
                foreach (array_merge([$tables[$name]['primary']], array_values($tables[$name]['unique'])) as $unique) {
                    // Une contrainte unique portant sur un sous-ensemble de la FK suffit aussi.
                    if ($unique && !array_diff($unique, $fk['columns'])) {
                        $one = true;
                    }
                }
                $inverse = Names::allocate(Names::base($name).'Via'.ucfirst($owner).($one ? '' : 'Collection'), $entities[$target]['used']);
                $nullable = $tables[$name]['columns'][$fk['columns'][0]]['nullable'];
                $id = (bool) array_intersect($fk['columns'], $tables[$name]['primary']);
                $entities[$name]['relations'][$owner] = ['kind' => $one ? 'OneToOne' : 'ManyToOne', 'target' => $target, 'inverse' => $inverse, 'owner' => true, 'nullable' => $nullable, 'fk' => $fk, 'id' => $id];
                $entities[$target]['relations'][$inverse] = ['kind' => $one ? 'OneToOne' : 'OneToMany', 'target' => $name, 'inverse' => $owner, 'owner' => false, 'nullable' => true, 'ownerNullable' => $nullable];
            }
        }
        foreach ($joins as $name => $table) {
            [$a, $b] = $table['foreign'];
            $an = Names::allocate(Names::base($b['target']).'Via'.Names::base($name, true).'Owner', $entities[$a['target']]['used']);
            $bn = Names::allocate(Names::base($a['target']).'Via'.Names::base($name, true).'Inverse', $entities[$b['target']]['used']);
            $entities[$a['target']]['relations'][$an] = ['kind' => 'ManyToMany', 'target' => $b['target'], 'inverse' => $bn, 'owner' => true, 'join' => $name, 'fk' => $a, 'otherFk' => $b];
            $entities[$b['target']]['relations'][$bn] = ['kind' => 'ManyToMany', 'target' => $a['target'], 'inverse' => $an, 'owner' => false];
        }
        if ($config->api) {
            foreach (array_merge($config->readFields, $config->writeFields) as $selection) {
                $found = false;
                foreach ($entities as $table => $entity) {
                    foreach ($entity['fields'] as $column => $field) {
                        if ($selection === $table.'.'.$column) {
                            $found = true;
                            if (in_array($selection, $config->writeFields, true) && (($field['column']['identity'] ?? false) || ($field['column']['sequence'] ?? null))) {
                                throw new \RuntimeException('Identifiant généré interdit en écriture API : '.$selection);
                            }
                        }
                    }
                }
                if (!$found) {
                    throw new \RuntimeException('Champ API inconnu ou relation non exposable : '.$selection);
                }
            }
            $this->warnings[] = 'API : seuls les champs explicitement autorisés sont exposés. Authentification, autorisations, validation et DTO restent à configurer.';
        }
        $files = [];
        foreach ($entities as $name => $entity) {
            $files[$entity['class'].'.php'] = $this->render($schema, $config, $name, $tables[$name], $entity, $entities);
        }
        ksort($files);

        return $files;
    }

    private static function sameSet(array $a, array $b): bool
    {
        sort($a);
        sort($b);
        return $a === $b;
    }

    private function attr(string $name, array $args): string
    {
        $parts = [];
        foreach ($args as $key => $value) {
            $parts[] = $key.': '.Names::literal($value);
        }
        return '#[ORM\\'.$name.'('.implode(', ', $parts).')]';
    }

    private function render(Schema $schema, Config $c, string $tableName, array $table, array $entity, array $entities): string
    {
        $code = "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$c->namespace};\n\nuse Doctrine\\ORM\\Mapping as ORM;\n";
        if (array_filter($entity['relations'], static fn ($r) => in_array($r['kind'], ['OneToMany', 'ManyToMany'], true))) {
            $code .= "use Doctrine\\Common\\Collections\\ArrayCollection;\nuse Doctrine\\Common\\Collections\\Collection;\n";
        }
        if ($c->api) {
            $code .= "use ApiPlatform\\Metadata\\ApiResource;\nuse Symfony\\Component\\Serializer\\Annotation\\Groups;\n";
            foreach ($c->operations as $operation) {
                $code .= "use ApiPlatform\\Metadata\\$operation;\n";
            }
        }
        $code .= "\n// Generated by Symfony Entity Generator ".self::VERSION.".\n#[ORM\\Entity]\n";
        $code .= $this->attr('Table', ['name' => Names::quote($tableName), 'schema' => $schema->name])."\n";
        foreach ($table['unique'] as $name => $columns) {
            $code .= $this->attr('UniqueConstraint', ['name' => Names::quote($name), 'columns' => array_map(Names::quote(...), $columns)])."\n";
        }
        if ($c->api) {
            $ops = implode(', ', array_map(static fn ($op) => 'new '.$op.'()', $c->operations));
            $code .= '#[ApiResource(operations: ['.$ops.'], normalizationContext: '.Names::literal(['groups' => [$c->readGroup]]).', denormalizationContext: '.Names::literal(['groups' => [$c->writeGroup]]).")]\n";
        }
        $code .= "class {$entity['class']}\n{\n";
        $methods = '';
        foreach ($entity['fields'] as $column => $f) {
            $name = $f['name'];
            $method = ucfirst($name);
            $col = $f['column'];
            $generated = ($col['identity'] ?? false) || ($col['sequence'] ?? null);
            if ($f['id']) {
                $code .= "    #[ORM\\Id]\n";
                if ($generated) {
                    $code .= '    '.$this->attr('GeneratedValue', ['strategy' => ($col['identity'] ?? false) ? 'IDENTITY' : 'SEQUENCE'])."\n";
                    if (!($col['identity'] ?? false)) {
                        $code .= '    '.$this->attr('SequenceGenerator', ['sequenceName' => $col['sequence'], 'allocationSize' => 1])."\n";
                    }
                }
            }
            if ($c->api) {
                $groups = [];
                if (in_array($tableName.'.'.$column, $c->readFields, true)) {
                    $groups[] = $c->readGroup;
                }
                if (in_array($tableName.'.'.$column, $c->writeFields, true)) {
                    $groups[] = $c->writeGroup;
                }
                if ($groups) {
                    $code .= '    #[Groups('.Names::literal($groups).")]\n";
                }
            }
            $code .= '    '.$this->attr('Column', ['name' => Names::quote($column)] + $f['type']['args'])."\n";
            $php = $f['type']['php'];
            $nullable = $col['nullable'] || $generated;
            $type = $php === 'mixed' ? 'mixed' : ($nullable ? '?' : '').$php;
            if ($col['type'] === 'bytea') {
                $code .= "    /** @var resource|string|null DBAL hydrate les blobs en ressources. */\n";
            }
            $code .= "    private $type \$$name".($nullable || $php === 'mixed' ? ' = null' : '').";\n\n";
            $methods .= "    public function get$method(): $type\n    {\n        return \$this->$name;\n    }\n\n";
            if (!$generated) {
                $guard = '';
                if ($col['type'] === 'bytea') {
                    $guard = '        if (!is_string($value) && !is_resource($value)'.($col['nullable'] ? ' && $value !== null' : '').") {\n            throw new \\InvalidArgumentException('Un blob attend une chaîne ou une ressource".($col['nullable'] ? ' ou null' : '').".');\n        }\n";
                } elseif (in_array($col['type'], ['json', 'jsonb'], true)) {
                    $guard = (!$col['nullable'] ? "        if (\$value === null) {\n            throw new \\InvalidArgumentException('Cette colonne JSON ne peut pas être SQL NULL.');\n        }\n" : '')."        json_encode(\$value, JSON_THROW_ON_ERROR);\n";
                }
                $methods .= "    public function set$method($type \$value): static\n    {\n$guard        \$this->$name = \$value;\n\n        return \$this;\n    }\n\n";
            }
        }
        $collections = [];
        foreach ($entity['relations'] as $name => $r) {
            $target = $entities[$r['target']]['class'];
            $inverse = $r['inverse'];
            $isCollection = in_array($r['kind'], ['OneToMany', 'ManyToMany'], true);
            $code .= ($r['id'] ?? false) ? "    #[ORM\\Id]\n" : '';
            $args = 'targetEntity: '.$target.'::class, '.($r['owner'] ? 'inversedBy' : 'mappedBy').': '.Names::literal($inverse);
            $code .= '    #[ORM\\'.$r['kind'].'('.$args.")]\n";
            if ($r['owner'] && $r['kind'] !== 'ManyToMany') {
                foreach ($r['fk']['columns'] as $i => $column) {
                    $join = ['name' => Names::quote($column), 'referencedColumnName' => Names::quote($r['fk']['references'][$i]), 'nullable' => $r['nullable']];
                    if (isset($r['fk']['onDelete'])) {
                        $join['onDelete'] = $r['fk']['onDelete'];
                    }
                    $code .= '    '.$this->attr('JoinColumn', $join)."\n";
                }
            } elseif ($r['owner'] && $r['kind'] === 'ManyToMany') {
                // DefaultQuoteStrategy concatène le schéma des JoinTable sans le citer.
                $code .= '    '.$this->attr('JoinTable', ['name' => Names::quote($r['join']), 'schema' => '"'.str_replace('"', '""', $schema->name).'"'])."\n";
                foreach (['fk' => 'JoinColumn', 'otherFk' => 'InverseJoinColumn'] as $fkKey => $attribute) {
                    $fk = $r[$fkKey];
                    $code .= '    '.$this->attr($attribute, ['name' => Names::quote($fk['columns'][0]), 'referencedColumnName' => Names::quote($fk['references'][0]), 'nullable' => false, 'onDelete' => $fk['onDelete'] ?? 'NO ACTION'])."\n";
                }
            }
            if ($isCollection) {
                $collections[] = $name;
                $code .= "    /** @var Collection<int, $target> */\n    private Collection \$$name;\n\n";
                $methods .= $this->collectionMethods($name, $target, $r);
            } else {
                $code .= "    private ?$target \$$name = null;\n\n";
                $methods .= $this->toOneMethods($name, $target, $r);
            }
        }
        if ($collections) {
            $code .= "    public function __construct()\n    {\n";
            foreach ($collections as $name) {
                $code .= "        \$this->$name = new ArrayCollection();\n";
            }
            $code .= "    }\n\n";
        }

        return $code.rtrim($methods)."\n}\n";
    }

    private function collectionMethods(string $name, string $target, array $r): string
    {
        $n = ucfirst($name);
        $i = ucfirst($r['inverse']);
        $many = $r['kind'] === 'ManyToMany';
        $add = $many ? "\$value->add$i(\$this);" : "\$value->set$i(\$this);";
        $remove = $many ? "\$value->remove$i(\$this);" : "if (\$value->get$i() === \$this) {\n                \$value->set$i(null);\n            }";
        $guard = !$many && !$r['ownerNullable'] ? "        if (\$this->{$name}->contains(\$value) && \$value->get$i() === \$this) {\n            throw new \\LogicException('Relation obligatoire : réaffecter cet objet avant de le retirer.');\n        }\n" : '';

        return "    /** @return Collection<int, $target> */\n    public function get$n(): Collection\n    {\n        return \$this->$name;\n    }\n\n    public function add$n($target \$value): static\n    {\n        if (!\$this->{$name}->contains(\$value)) {\n            \$this->{$name}->add(\$value);\n            $add\n        }\n\n        return \$this;\n    }\n\n    public function remove$n($target \$value): static\n    {\n$guard        if (\$this->{$name}->removeElement(\$value)) {\n            $remove\n        }\n\n        return \$this;\n    }\n\n";
    }

    private function toOneMethods(string $name, string $target, array $r): string
    {
        $n = ucfirst($name);
        $i = ucfirst($r['inverse']);
        $type = ($r['nullable'] ? '?' : '').$target;
        $body = '';
        if ($r['kind'] === 'ManyToOne') {
            $body = "        \$previous = \$this->$name;\n        \$this->$name = \$value;\n        \$previous?->remove$i(\$this);\n        \$value?->add$i(\$this);\n";
        } else {
            $body = "        if (\$value !== null && \$value->get$i() !== null && \$value->get$i() !== \$this) {\n            throw new \\LogicException('Relation OneToOne déjà occupée : détacher avant de réaffecter.');\n        }\n";
            if (!$r['owner'] && !$r['ownerNullable']) {
                $body .= "        if (\$this->$name !== null && \$this->{$name}->get$i() === \$this) {\n            throw new \\LogicException('Relation obligatoire : réaffecter le côté propriétaire avant de détacher.');\n        }\n";
            }
            $body .= "        \$previous = \$this->$name;\n        \$this->$name = \$value;\n        if (\$previous !== null && \$previous->get$i() === \$this) {\n            \$previous->set$i(null);\n        }\n        if (\$value !== null && \$value->get$i() !== \$this) {\n            \$value->set$i(\$this);\n        }\n";
        }

        return "    public function get$n(): ?$target\n    {\n        return \$this->$name;\n    }\n\n    public function set$n($type \$value): static\n    {\n        if (\$this->$name === \$value) {\n            return \$this;\n        }\n$body\n        return \$this;\n    }\n\n";
    }
}
