<?php

declare(strict_types=1);

namespace EntityGenerator;

interface SchemaInspector
{
    public function inspect(string $schema): Schema;
}
