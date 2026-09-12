<?php
declare(strict_types=1);

namespace CannonMiner;

use DateTimeImmutable;
use DateTimeZone;

final class UsBankHolidays
{
    /** @return array<string, list<string>> */
    public static function forYear(int $year, DateTimeZone $timezone): array
    {
        $holidays = [];
        $add = static function (DateTimeImmutable $date, string $name) use (&$holidays, $year): void {
            if ((int)$date->format('Y') === $year) $holidays[$date->format('Y-m-d')][] = $name;
        };

        // Adjacent years cover an observed New Year's Day falling on December 31.
        foreach (range($year - 1, $year + 1) as $actualYear) {
            foreach ([
                ['01-01', "New Year's Day"],
                ['06-19', 'Juneteenth National Independence Day'],
                ['07-04', 'Independence Day'],
                ['11-11', 'Veterans Day'],
                ['12-25', 'Christmas Day'],
            ] as [$monthDay, $name]) {
                if ($name === 'Juneteenth National Independence Day' && $actualYear < 2021) continue;
                $actual = new DateTimeImmutable(sprintf('%04d-%s', $actualYear, $monthDay), $timezone);
                $add($actual, $name);
                $weekday = (int)$actual->format('N');
                if ($weekday === 6) $add($actual->modify('-1 day'), $name.' (observed)');
                if ($weekday === 7) $add($actual->modify('+1 day'), $name.' (observed)');
            }
        }

        foreach ([
            ["third monday of january {$year}", 'Martin Luther King Jr. Day'],
            ["third monday of february {$year}", "Washington's Birthday"],
            ["last monday of may {$year}", 'Memorial Day'],
            ["first monday of september {$year}", 'Labor Day'],
            ["second monday of october {$year}", 'Columbus Day'],
            ["fourth thursday of november {$year}", 'Thanksgiving Day'],
        ] as [$description, $name]) {
            $add(new DateTimeImmutable($description, $timezone), $name);
        }

        ksort($holidays);
        return $holidays;
    }
}
