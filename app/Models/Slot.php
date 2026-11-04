<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Slot extends Model
{
    public static function lockAvailable(int $id): ?self
    {
        $slot = self::whereKey($id)->lockForUpdate()->first();
        $clock = now('Asia/Hong_Kong');
        if (!$slot || $slot->closed_at || $slot->starts_at <= $clock->toDateTimeString()
            || $slot->starts_at >= $clock->copy()->startOfDay()->addDays(14)->toDateTimeString()) {
            return null;
        }

        return DB::table('appointments')->where('slot_id', $id)->whereNull('cancelled_at')->lockForUpdate()->first()
            ? null : $slot;
    }
}
