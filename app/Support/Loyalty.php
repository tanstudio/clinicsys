<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Customer tiers, session packages and stored value. Every rule comes from the customer_tiers, package_plans and
 * loyalty_settings tables, which head office edits in /staff/loyalty; nothing here hard-codes a tier, price or count.
 */
final class Loyalty
{
    public const KINDS = ['sessions' => '療程次數', 'stored_value' => '儲值'];
    public const BASES = ['sale' => '售出分店（售出時確認）', 'usage' => '使用分店（使用時確認）'];
    public const STATUSES = ['active' => '可使用', 'refunded' => '已退款', 'transferred' => '已轉讓'];

    /** @return object{revenue_basis: string, balance_notices: bool} */
    public static function settings(): object
    {
        $row = DB::table('loyalty_settings')->where('id', 1)->first();

        return (object) ['revenue_basis' => $row->revenue_basis ?? 'sale', 'balance_notices' => (bool) ($row->balance_notices ?? false)];
    }

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

    /** Paid receipts less package refunds in the last $months months. Redemptions are HKD 0 and add nothing. */
    public static function spend(int $patient, int $months): int
    {
        $since = CarbonImmutable::now('UTC')->subMonths($months);
        $paid = (int) DB::table('receipts')->where('patient_id', $patient)->where('created_at', '>=', $since)->sum('amount_cents');
        $refunded = (int) DB::table('package_refunds')->where('patient_id', $patient)->where('created_at', '>=', $since)->sum('amount_cents');

        return max(0, $paid - $refunded);
    }

    /**
     * @return array<int, int> package id => balance: sessions for a session package, cents for stored value.
     * A package moves in one unit only, so the other column is always zero on its ledger rows.
     */
    public static function balances(Collection|array $packages): array
    {
        $ids = collect($packages)->all();
        if ($ids === []) {
            return [];
        }
        $sums = DB::table('package_ledger')->whereIn('package_id', $ids)->groupBy('package_id')
            ->selectRaw('package_id, SUM(sessions) + SUM(value_cents) AS balance')->pluck('balance', 'package_id');

        return collect($ids)->mapWithKeys(fn ($id) => [$id => (int) ($sums[$id] ?? 0)])->all();
    }

    /** The package's full size in its own unit. */
    public static function size(object $package): int
    {
        return $package->kind === 'stored_value' ? (int) $package->total_value_cents : (int) $package->total_sessions;
    }

    /** Human-readable balance in the package's unit. */
    public static function format(object $package, int $amount): string
    {
        return $package->kind === 'stored_value' ? 'HK$ '.number_format($amount / 100, 2) : $amount.' 次';
    }

    /** The paid price of $units of the package: the price spread evenly over sessions or over stored value incl. bonus. */
    public static function worth(object $package, int $units): int
    {
        $size = self::size($package);

        return $size > 0 ? intdiv($package->price_cents * $units, $size) : 0;
    }

    /** @return array{gross: int, fee: int, amount: int} refund of the remaining balance under the package's sold terms */
    public static function refundQuote(object $package, int $left): array
    {
        $gross = self::worth($package, $left);
        $fee = intdiv($gross * $package->refund_fee_percent + 50, 100);

        return ['gross' => $gross, 'fee' => $fee, 'amount' => $gross - $fee];
    }

    public static function today(): string
    {
        return CarbonImmutable::today('Asia/Hong_Kong')->toDateString();
    }

    /** Open, unexpired and allowed at $branch; the ledger decides whether anything is left. */
    public static function usable(object $package, int $branch, string $visitDay): bool
    {
        return $package->status === 'active' && $package->starts_on <= $visitDay && $package->expires_on >= max($visitDay, self::today())
            && in_array($branch, json_decode($package->allowed_branches, true), true);
    }

    /**
     * Packages the patient may use for one visit: session packages of the same service and stored value of any service,
     * allowed at the visit's branch, open, not expired on the visit day or today, and with a balance left.
     */
    public static function eligible(int $patient, int $branch, int $service, string $visitDay): Collection
    {
        $packages = DB::table('patient_packages')->where('patient_id', $patient)->where('status', 'active')
            ->where(fn ($q) => $q->where('service_id', $service)->orWhere('kind', 'stored_value'))
            ->orderBy('expires_on')->orderBy('id')->get()
            ->filter(fn ($p) => self::usable($p, $branch, $visitDay));
        $left = self::balances($packages->pluck('id'));

        return $packages->filter(fn ($p) => $left[$p->id] > 0)->map(fn ($p) => (object) ((array) $p + ['left' => $left[$p->id]]))->values();
    }
}
