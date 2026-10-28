<?php

declare(strict_types=1);

namespace EntityGenerator;

final class Names
{
    public static function reserved(string $name): bool
    {
        return in_array(strtolower($name), explode(' ', 'class trait interface enum extends implements function fn const public protected private static abstract final readonly var new clone match switch case default break continue if else elseif endif for foreach while do try catch finally throw return yield from true false null self parent int float string bool array object mixed void never iterable callable resource namespace use as insteadof global echo print isset empty unset list exit die eval include include_once require require_once and or xor instanceof goto declare enddeclare endfor endforeach endwhile endswitch enddeclare __halt_compiler'), true);
    }

    public static function base(string $sql, bool $class = false): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $sql);
        $parts = preg_split('/[^a-zA-Z0-9]+/', $ascii === false ? '' : $ascii, -1, PREG_SPLIT_NO_EMPTY);
        $name = implode('', array_map('ucfirst', $parts));
        if ($name === '' || ctype_digit($name[0])) {
            $name = 'Field'.$name;
        }
        if (self::reserved($name)) {
            $name .= $class ? 'Entity' : 'Field';
        }

        return $class ? $name : lcfirst($name);
    }

    public static function allocate(string $base, array &$used): string
    {
        $name = $base;
        $i = 2;
        while (isset($used[strtolower($name)])) {
            $name = $base.$i++;
        }
        $used[strtolower($name)] = true;

        return $name;
    }

    public static function quote(string $identifier): string
    {
        if (str_contains($identifier, '`')) {
            throw new \RuntimeException('Identifiant contenant un backtick non représentable par ce générateur : '.$identifier);
        }
        return '`'.$identifier.'`';
    }

    public static function literal(mixed $value): string
    {
        if (is_array($value)) {
            $list = array_is_list($value);
            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = ($list ? '' : self::literal($key).' => ').self::literal($item);
            }
            return '['.implode(', ', $parts).']';
        }
        return var_export($value, true);
    }
}
