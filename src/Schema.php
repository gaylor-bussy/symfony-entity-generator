<?php

declare(strict_types=1);

namespace EntityGenerator;

/** Métadonnées indépendantes de PDO et de Doctrine, également utilisées par les fixtures. */
final class Schema
{
    public function __construct(public readonly string $name, public readonly array $tables)
    {
    }
}
