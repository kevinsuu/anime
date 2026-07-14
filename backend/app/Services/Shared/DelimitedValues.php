<?php

namespace App\Services\Shared;

final class DelimitedValues
{
    /** @return array<int, string> */
    public static function parse(string $value, string $separator = ','): array
    {
        return array_values(array_filter(
            array_map('trim', explode($separator, $value)),
            fn (string $item): bool => $item !== ''
        ));
    }
}
