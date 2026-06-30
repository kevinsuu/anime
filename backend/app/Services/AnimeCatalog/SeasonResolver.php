<?php

namespace App\Services\AnimeCatalog;

use DateTimeImmutable;
use InvalidArgumentException;

final class SeasonResolver
{
    public static function fromAirDate(?string $airDate): array
    {
        if ($airDate === null || ! preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $airDate, $matches)) {
            return ['year' => null, 'code' => null];
        }

        $month = (int) $matches[2];
        $code = match (true) {
            $month >= 1 && $month <= 3 => 'winter',
            $month >= 4 && $month <= 6 => 'spring',
            $month >= 7 && $month <= 9 => 'summer',
            default => 'fall',
        };

        return ['year' => (int) $matches[1], 'code' => $code];
    }

    public static function months(string $seasonCode): array
    {
        return match ($seasonCode) {
            'winter' => [1, 2, 3],
            'spring' => [4, 5, 6],
            'summer' => [7, 8, 9],
            'fall' => [10, 11, 12],
            default => throw new InvalidArgumentException('Unsupported season code'),
        };
    }

    public static function current(DateTimeImmutable $date): array
    {
        $resolved = self::fromAirDate($date->format('Y-m-d'));

        return [
            'year' => $resolved['year'],
            'code' => $resolved['code'],
        ];
    }
}
