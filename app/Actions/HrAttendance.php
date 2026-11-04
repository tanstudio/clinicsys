<?php

namespace App\Actions;

use Carbon\CarbonImmutable;

class HrAttendance
{
    public static function summary(object $shift): array
    {
        if (!$shift->clock_in) {
            return ['late' => null, 'early' => null, 'overtime' => null];
        }
        $arrived = CarbonImmutable::parse($shift->clock_in, 'Asia/Hong_Kong');
        $latest = CarbonImmutable::parse($shift->latest_start, 'Asia/Hong_Kong');
        $earliest = CarbonImmutable::parse($shift->starts_at, 'Asia/Hong_Kong');
        $expectedEnd = $arrived->max($earliest)->min($latest)->addMinutes($shift->required_minutes);
        $left = $shift->clock_out ? CarbonImmutable::parse($shift->clock_out, 'Asia/Hong_Kong') : null;
        return [
            'late' => max(0, (int) $latest->diffInMinutes($arrived)),
            'early' => $left ? max(0, (int) $left->diffInMinutes($expectedEnd)) : null,
            'overtime' => $left ? max(0, (int) $arrived->diffInMinutes($left) - $shift->required_minutes) : null,
        ];
    }
}
