<?php

namespace GhostZero\SemverResolver\Support;

use Closure;

class Arr
{
    public static function union($array, $array1): array
    {
        return array_merge($array, $array1);
    }

    public static function intersection($array, $array1): array
    {
        return array_intersect($array, $array1);
    }

    public static function forOwn(array $state, Closure $param)
    {
        foreach ($state as $key => $value) {
            $param($value, $key, $state);
        }
    }

    public static function uniq(array $array): array
    {
        return array_unique($array);
    }

    public static function mapValues(array $array, Closure $param)
    {
        return array_map($param, $array);
    }

    public static function difference(array $array, array $array_keys): array
    {
        return array_diff($array, $array_keys);
    }

    public static function includes(array $array, string $need): bool
    {
        return in_array($need, $array);
    }

}