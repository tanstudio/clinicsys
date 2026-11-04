<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class BranchDirectory
{
    public const KEYS = ['practitioners'=>'practitioner_id', 'services'=>'service_id'];

    public static function entries(string $type, int $branch)
    {
        return DB::table($type)->join('branch_'.$type.' as membership', 'membership.'.self::KEYS[$type], '=', $type.'.id')
            ->where('membership.branch_id',$branch)->select($type.'.*','membership.active');
    }

    public static function requireActive(int $branch, array $slot): void
    {
        foreach (self::KEYS as $type=>$key) {
            $entry = DB::table('branch_'.$type)->where(['branch_id'=>$branch,$key=>$slot[$key]])->lockForUpdate()->first();
            abort_unless($entry && $entry->active,422,'該醫師或服務未於本分店啟用。');
        }
    }
}
