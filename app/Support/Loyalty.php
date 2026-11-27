<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Customer tiers and session packages. Every rule comes from the customer_tiers and package_plans tables,
 * which head office edits in /staff/loyalty; nothing here hard-codes a tier, price or count.
 */
final class Loyalty
{
    /**
     * The patient's tier: a manual setting wins; otherwise the active tier with the highest threshold that the
     * patient's paid receipts reach within that tier's own rolling window.
     *
     * @return array{tier: ?object, spend_cents: int, manual: bool, reason: ?string}
     */
    public static function tier(int $patient): array
    {
        $manual = DB::table('patient_tiers as p')->join('customer_tiers as t', 't.id', '=', 'p.tier_id')
            ->where('p.patient_id', $patient)->select('t.*', 'p.reason')->first();
        $spend = self::spend($patient, 12);
        if ($manual) {
            return ['tier' => $manual, 'spend_cents' => $spend, 'manual' => true, 'reason' => $manual->reason];
        }
        $reached = DB::table('customer_tiers')->where('active', true)->orderByDesc('threshold_cents')->orderByDesc('id')->get()
            ->first(fn ($tier) => self::spend($patient, $tier->window_months) >= $tier->threshold_cents);

        return ['tier' => $reached, 'spend_cents' => $spend, 'manual' => false, 'reason' => null];
    }

    /** Paid receipts in the last $months months. Package redemptions are HKD 0 and add nothing. */
    public static function spend(int $patient, int $months): int
    {
        return (int) DB::table('receipts')->where('patient_id', $patient)
            ->where('created_at', '>=', CarbonImmutable::now('UTC')->subMonths($months))->sum('amount_cents');
    }

    /** @return array<int, int> package id => remaining sessions */
    public static function balances(Collection|array $packages): array
    {
        $ids = collect($packages)->all();
        if ($ids === []) {
            return [];
        }
        $sums = DB::table('package_ledger')->whereIn('package_id', $ids)->groupBy('package_id')
            ->selectRaw('package_id, SUM(sessions) AS left_sessions')->pluck('left_sessions', 'package_id');

        return collect($ids)->mapWithKeys(fn ($id) => [$id => (int) ($sums[$id] ?? 0)])->all();
    }

    public static function today(): string
    {
        return CarbonImmutable::today('Asia/Hong_Kong')->toDateString();
    }

    /**
     * Packages the patient may redeem for one visit: same service, allowed at the visit's branch,
     * not expired on the visit day or today, and with sessions left.
     */
    public static function eligible(int $patient, int $branch, int $service, string $visitDay): Collection
    {
        $packages = DB::table('patient_packages')->where('patient_id', $patient)->where('service_id', $service)
            ->where('starts_on', '<=', $visitDay)->where('expires_on', '>=', max($visitDay, self::today()))
            ->orderBy('expires_on')->orderBy('id')->get()
            ->filter(fn ($p) => in_array($branch, json_decode($p->allowed_branches, true), true));
        $left = self::balances($packages->pluck('id'));

        return $packages->filter(fn ($p) => $left[$p->id] > 0)->map(fn ($p) => (object) ((array) $p + ['left' => $left[$p->id]]))->values();
    }
}
