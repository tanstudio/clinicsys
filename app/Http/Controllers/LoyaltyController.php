<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Support\Loyalty;
use App\Support\PatientPhone;
use App\Support\StaffAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tier, package and stored-value rules (head office) and package operations (branches).
 * Rule values come only from the tables these forms edit.
 */
class LoyaltyController
{
    private const METHODS = 'cash,card,fps,other';

    private function hq(): void
    {
        abort_unless(StaffAccess::hq(), 403);
    }

    public function index()
    {
        $this->hq();
        $plans = DB::table('package_plans as p')->leftJoin('services as s', 's.id', '=', 'p.service_id')
            ->select('p.*', 's.name_zh as service')->orderByDesc('p.active')->orderBy('p.kind')->orderBy('p.name')->get();
        $planBranches = DB::table('package_plan_branches')->get()->groupBy('plan_id')->map(fn ($rows) => $rows->pluck('branch_id')->all());

        return view('loyalty.index', [
            'tiers' => DB::table('customer_tiers')->orderByDesc('active')->orderBy('threshold_cents')->get(),
            'plans' => $plans, 'planBranches' => $planBranches, 'settings' => Loyalty::settings(),
            'services' => DB::table('services')->orderBy('name_zh')->pluck('name_zh', 'id'),
            'branches' => DB::table('branches')->orderBy('id')->pluck('name_zh', 'id'),
        ]);
    }

    public function saveSettings(Request $request)
    {
        $this->hq();
        $data = $request->validate(['revenue_basis' => ['required', Rule::in(array_keys(Loyalty::BASES))], 'balance_notices' => 'required|boolean']);
        DB::table('loyalty_settings')->updateOrInsert(['id' => 1], ['revenue_basis' => $data['revenue_basis'],
            'balance_notices' => (bool) $data['balance_notices'], 'updated_at' => now()]);

        return redirect('/staff/loyalty')->with('status', '已儲存套票通用設定。');
    }

    public function saveTier(Request $request, ?int $id = null)
    {
        $this->hq();
        $data = $request->validate([
            'name' => 'required|string|max:40', 'threshold' => 'required|numeric|min:0|max:9999999',
            'window_months' => 'required|integer|min:1|max:60', 'discount_percent' => 'required|integer|min:0|max:100',
            'active' => 'required|boolean',
        ]);
        $row = ['name' => trim($data['name']), 'threshold_cents' => (int) round($data['threshold'] * 100),
            'window_months' => (int) $data['window_months'], 'discount_percent' => (int) $data['discount_percent'],
            'active' => (bool) $data['active'], 'updated_at' => now()];
        if ($id) {
            abort_unless(DB::table('customer_tiers')->where('id', $id)->exists(), 404);
            DB::table('customer_tiers')->where('id', $id)->update($row);
        } else {
            DB::table('customer_tiers')->insert($row + ['created_at' => now()]);
        }

        return redirect('/staff/loyalty')->with('status', '已儲存等級規則。');
    }

    public function savePlan(Request $request, ?int $id = null)
    {
        $this->hq();
        $sessions = $request->input('kind', 'sessions') === 'sessions';
        $data = $request->validate([
            'name' => 'required|string|max:80', 'kind' => ['required', Rule::in(array_keys(Loyalty::KINDS))],
            'service_id' => [$sessions ? 'required' : 'nullable', 'integer', 'exists:services,id'],
            'sessions' => [$sessions ? 'required' : 'nullable', 'integer', 'min:1', 'max:999'],
            'bonus_sessions' => 'nullable|integer|min:0|max:999',
            'value' => [$sessions ? 'nullable' : 'required', 'numeric', 'min:0.01', 'max:9999999'],
            'bonus_value' => 'nullable|numeric|min:0|max:9999999',
            'price' => 'required|numeric|min:0|max:9999999', 'validity_days' => 'required|integer|min:1|max:3650',
            'refundable' => 'required|boolean', 'refund_fee_percent' => 'required|integer|min:0|max:100', 'transferable' => 'required|boolean',
            'branches' => 'required|array|min:1', 'branches.*' => 'integer|distinct|exists:branches,id', 'active' => 'required|boolean',
        ]);
        $row = ['name' => trim($data['name']), 'kind' => $data['kind'],
            'service_id' => $sessions ? (int) $data['service_id'] : null,
            'sessions' => $sessions ? (int) $data['sessions'] : 0, 'bonus_sessions' => $sessions ? (int) ($data['bonus_sessions'] ?? 0) : 0,
            'value_cents' => $sessions ? 0 : (int) round($data['value'] * 100),
            'bonus_value_cents' => $sessions ? 0 : (int) round(($data['bonus_value'] ?? 0) * 100),
            'price_cents' => (int) round($data['price'] * 100), 'validity_days' => (int) $data['validity_days'],
            'refundable' => (bool) $data['refundable'], 'refund_fee_percent' => (int) $data['refund_fee_percent'],
            'transferable' => (bool) $data['transferable'], 'active' => (bool) $data['active'], 'updated_at' => now()];
        DB::transaction(function () use ($id, $row, $data) {
            if ($id) {
                abort_unless(DB::table('package_plans')->where('id', $id)->lockForUpdate()->exists(), 404);
                DB::table('package_plans')->where('id', $id)->update($row);
            } else {
                $id = DB::table('package_plans')->insertGetId($row + ['created_at' => now()]);
            }
            DB::table('package_plan_branches')->where('plan_id', $id)->delete();
            DB::table('package_plan_branches')->insert(array_map(fn ($b) => ['plan_id' => $id, 'branch_id' => (int) $b], $data['branches']));
        });

        return redirect('/staff/loyalty')->with('status', '已儲存套票方案。');
    }

    /** The current branch must have served the patient, as on the CRM pages. */
    private function patientInBranch(int $patient, int $branch): void
    {
        abort_unless(DB::table('appointments as a')->join('slots as s', 's.id', '=', 'a.slot_id')
            ->where('a.patient_id', $patient)->where('s.branch_id', $branch)->exists(), 404);
    }

    private function ledger(int $package, string $kind, int $amount, object $unit, int $branch, array $extra = []): void
    {
        $stored = $unit->kind === 'stored_value';
        DB::table('package_ledger')->insert(['reference' => (string) Str::uuid(), 'package_id' => $package, 'kind' => $kind,
            'sessions' => $stored ? 0 : $amount, 'value_cents' => $stored ? $amount : 0, 'branch_id' => $branch,
            'staff_id' => StaffAccess::id(), 'created_at' => now('UTC')] + $extra);
    }

    public function sell(Request $request, int $patient)
    {
        StaffAccess::allow('reception');
        $branch = StaffAccess::branch();
        $this->patientInBranch($patient, $branch);
        $data = $request->validate(['plan_id' => 'required|integer', 'method' => 'required|in:'.self::METHODS]);
        DB::transaction(function () use ($data, $patient, $branch) {
            $plan = DB::table('package_plans')->where('id', $data['plan_id'])->where('active', true)->lockForUpdate()->first();
            $allowed = DB::table('package_plan_branches')->where('plan_id', $data['plan_id'])->pluck('branch_id')->map(fn ($b) => (int) $b)->all();
            if (!$plan || !in_array($branch, $allowed, true)) {
                throw ValidationException::withMessages(['plan_id' => '此方案未在本分店啟用。']);
            }
            $today = CarbonImmutable::today('Asia/Hong_Kong');
            $stored = $plan->kind === 'stored_value';
            $receipt = DB::table('receipts')->insertGetId(['reference' => (string) Str::uuid(), 'branch_id' => $branch,
                'patient_id' => $patient, 'appointment_id' => null, 'encounter_id' => null, 'kind' => 'package',
                'description' => ($stored ? '購買儲值：' : '購買療程套票：').$plan->name, 'amount_cents' => $plan->price_cents,
                'method' => $data['method'], 'created_at' => now('UTC')]);
            $sold = (object) ['kind' => $plan->kind];
            $package = DB::table('patient_packages')->insertGetId(['reference' => (string) Str::uuid(), 'patient_id' => $patient,
                'plan_id' => $plan->id, 'sold_branch_id' => $branch, 'service_id' => $plan->service_id, 'name' => $plan->name,
                'kind' => $plan->kind, 'total_sessions' => $plan->sessions + $plan->bonus_sessions,
                'total_value_cents' => $plan->value_cents + $plan->bonus_value_cents, 'price_cents' => $plan->price_cents,
                'refundable' => $plan->refundable, 'refund_fee_percent' => $plan->refund_fee_percent, 'transferable' => $plan->transferable,
                'allowed_branches' => json_encode($allowed), 'starts_on' => $today->toDateString(),
                'expires_on' => $today->addDays($plan->validity_days - 1)->toDateString(),
                'receipt_id' => $receipt, 'sold_by' => StaffAccess::id(), 'created_at' => now(), 'updated_at' => now()]);
            $this->ledger($package, 'purchase', $stored ? $plan->value_cents + $plan->bonus_value_cents : $plan->sessions + $plan->bonus_sessions, $sold, $branch);
        }, 3);

        return redirect('/staff/patients/'.$patient)->with('status', '已售出套票並簽發收據。');
    }

    public function setTier(Request $request, int $patient)
    {
        $this->hq();
        $branch = StaffAccess::branch();
        $this->patientInBranch($patient, $branch);
        $data = $request->validate(['tier_id' => 'nullable|integer|exists:customer_tiers,id', 'reason' => 'required_with:tier_id|nullable|string|max:200']);
        if (empty($data['tier_id'])) {
            DB::table('patient_tiers')->where('patient_id', $patient)->delete();
        } else {
            DB::table('patient_tiers')->upsert([['patient_id' => $patient, 'tier_id' => (int) $data['tier_id'],
                'reason' => trim($data['reason']), 'set_by' => StaffAccess::id(), 'set_at' => now('UTC')]], ['patient_id'], ['tier_id', 'reason', 'set_by', 'set_at']);
        }

        return redirect('/staff/patients/'.$patient)->with('status', '已更新會員等級。');
    }

    /**
     * Pays for one finished visit with one session, or with an amount of stored value.
     * The HKD 0 receipt keeps the visit out of the unpaid list.
     */
    public function redeem(Request $request)
    {
        StaffAccess::allow('reception');
        $branch = StaffAccess::branch();
        $data = $request->validate(['encounter_id' => 'required|integer', 'package_id' => 'required|integer',
            'amount' => 'nullable|numeric|min:0.01|max:9999999']);
        $receipt = DB::transaction(function () use ($data, $branch) {
            $visit = DB::table('encounters as e')->join('appointments as a', 'a.id', '=', 'e.appointment_id')
                ->join('slots as s', 's.id', '=', 'a.slot_id')->where('e.id', $data['encounter_id'])->where('e.branch_id', $branch)
                ->whereNull('a.cancelled_at')->lockForUpdate()->select('e.id', 'e.patient_id', 'e.appointment_id', 's.service_id', 's.starts_at')->first();
            abort_unless($visit, 404);
            if (DB::table('receipts')->where('encounter_id', $visit->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['encounter_id' => '此診症已收款或已使用套票。']);
            }
            DB::table('patient_packages')->where('id', $data['package_id'])->lockForUpdate()->first();
            $package = Loyalty::eligible($visit->patient_id, $branch, $visit->service_id, substr($visit->starts_at, 0, 10))
                ->firstWhere('id', (int) $data['package_id']);
            if (!$package) {
                throw ValidationException::withMessages(['package_id' => '此套票不適用於這次診症：服務、分店、有效期、狀態或餘額不符。']);
            }
            if ($package->kind === 'stored_value') {
                $use = (int) round(($data['amount'] ?? 0) * 100);
                if ($use <= 0) {
                    throw ValidationException::withMessages(['amount' => '請輸入本次以儲值支付的金額。']);
                }
                if ($use > $package->left) {
                    throw ValidationException::withMessages(['amount' => '儲值餘額不足：餘額 '.Loyalty::format($package, $package->left).'。']);
                }
                $description = '使用儲值：'.$package->name.' 扣 '.Loyalty::format($package, $use).'（餘額 '.Loyalty::format($package, $package->left - $use).'）';
            } else {
                $use = 1;
                $description = '使用療程套票：'.$package->name.'（剩 '.($package->left - 1).' 次）';
            }
            $reference = (string) Str::uuid();
            $id = DB::table('receipts')->insertGetId(['reference' => $reference, 'branch_id' => $branch, 'patient_id' => $visit->patient_id,
                'appointment_id' => $visit->appointment_id, 'encounter_id' => $visit->id, 'kind' => 'redeem',
                'description' => $description, 'amount_cents' => 0, 'method' => 'package', 'created_at' => now('UTC')]);
            $this->ledger($package->id, 'redeem', -$use, $package, $branch, ['encounter_id' => $visit->id, 'receipt_reference' => $reference]);

            return $id;
        }, 3);

        return redirect('/staff/pos/'.$receipt);
    }

    /** Reverses one redemption: the balance returns and the visit is unpaid again. Both ledger rows stay. */
    public function void(Request $request, int $entry)
    {
        StaffAccess::allow('admin');
        $branch = StaffAccess::branch();
        $data = $request->validate(['reason' => 'required|string|max:200']);
        $patient = DB::transaction(function () use ($entry, $branch, $data) {
            $row = DB::table('package_ledger as l')->join('patient_packages as p', 'p.id', '=', 'l.package_id')
                ->where('l.id', $entry)->where('l.kind', 'redeem')->where('l.branch_id', $branch)->lockForUpdate()
                ->select('l.*', 'p.patient_id', 'p.status')->first();
            abort_unless($row, 404);
            abort_if(DB::table('package_ledger')->where('voids_id', $entry)->exists(), 409, '此扣減已撤銷。');
            abort_if($row->status !== 'active', 409, '套票已退款或轉讓，不能撤銷扣減。');
            DB::table('receipts')->where('reference', $row->receipt_reference)->where('method', 'package')->delete();
            DB::table('package_ledger')->insert(['reference' => (string) Str::uuid(), 'package_id' => $row->package_id, 'kind' => 'void',
                'sessions' => -$row->sessions, 'value_cents' => -$row->value_cents, 'encounter_id' => $row->encounter_id,
                'receipt_reference' => $row->receipt_reference, 'branch_id' => $branch, 'staff_id' => StaffAccess::id(),
                'reason' => trim($data['reason']), 'voids_id' => $entry, 'created_at' => now('UTC')]);

            return $row->patient_id;
        }, 3);

        return redirect('/staff/patients/'.$patient)->with('status', '已撤銷套票扣減，診症回到待收款。');
    }

    /** Locks one open package that the current branch may serve, with its balance. */
    private function openPackage(int $id, int $branch): object
    {
        $package = DB::table('patient_packages')->where('id', $id)->lockForUpdate()->first();
        abort_unless($package, 404);
        $this->patientInBranch($package->patient_id, $branch);
        if (!Loyalty::usable($package, $branch, Loyalty::today())) {
            throw ValidationException::withMessages(['package' => '此套票已過期、已結束或不適用於本分店。']);
        }
        $package->left = Loyalty::balances([$id])[$id];
        if ($package->left <= 0) {
            throw ValidationException::withMessages(['package' => '此套票沒有餘額。']);
        }

        return $package;
    }

    /** Refunds the whole remaining balance at the sold price, less the refund fee the package was sold with. */
    public function refund(Request $request, int $package)
    {
        StaffAccess::allow('admin');
        $branch = StaffAccess::branch();
        $data = $request->validate(['method' => 'required|in:'.self::METHODS, 'reason' => 'required|string|max:200']);
        $patient = DB::transaction(function () use ($package, $branch, $data) {
            $item = $this->openPackage($package, $branch);
            if (!$item->refundable) {
                throw ValidationException::withMessages(['package' => '此套票按售出條款不設退款。']);
            }
            $quote = Loyalty::refundQuote($item, $item->left);
            DB::table('package_refunds')->insert(['reference' => (string) Str::uuid(), 'package_id' => $item->id, 'branch_id' => $branch,
                'patient_id' => $item->patient_id, 'amount_cents' => $quote['amount'], 'fee_cents' => $quote['fee'],
                'method' => $data['method'], 'reason' => trim($data['reason']), 'staff_id' => StaffAccess::id(), 'created_at' => now('UTC')]);
            $this->ledger($item->id, 'refund', -$item->left, $item, $branch, ['reason' => trim($data['reason'])]);
            DB::table('patient_packages')->where('id', $item->id)->update(['status' => 'refunded', 'closed_at' => now('UTC'), 'updated_at' => now()]);

            return $item->patient_id;
        }, 3);

        return redirect('/staff/patients/'.$patient)->with('status', '已退款並結束套票。');
    }

    /**
     * Moves the whole remaining balance to another patient of this branch, identified by name and phone as in bookings.
     * The new package keeps the sold terms; its price is the paid share of the balance, so a later refund stays correct.
     */
    public function transfer(Request $request, int $package)
    {
        StaffAccess::allow('admin');
        $branch = StaffAccess::branch();
        $data = $request->validate(['name' => 'required|string|max:80', 'phone' => ['required', 'string', 'regex:'.PatientPhone::PATTERN],
            'reason' => 'required|string|max:200']);
        $patient = DB::transaction(function () use ($package, $branch, $data) {
            $item = $this->openPackage($package, $branch);
            if (!$item->transferable) {
                throw ValidationException::withMessages(['package' => '此套票按售出條款不可轉讓。']);
            }
            $to = Patient::where('identity_key', PatientPhone::patientKey($data['name'], $data['phone']))->value('id');
            if (!$to || $to === $item->patient_id || !DB::table('appointments as a')->join('slots as s', 's.id', '=', 'a.slot_id')
                ->where('a.patient_id', $to)->where('s.branch_id', $branch)->exists()) {
                throw ValidationException::withMessages(['phone' => '找不到本分店的受讓患者：請核對姓名及電話，受讓人須曾在本分店預約。']);
            }
            $stored = $item->kind === 'stored_value';
            $copy = DB::table('patient_packages')->insertGetId(['reference' => (string) Str::uuid(), 'patient_id' => $to,
                'plan_id' => $item->plan_id, 'sold_branch_id' => $item->sold_branch_id, 'service_id' => $item->service_id,
                'name' => $item->name, 'kind' => $item->kind, 'total_sessions' => $stored ? 0 : $item->left,
                'total_value_cents' => $stored ? $item->left : 0, 'price_cents' => Loyalty::worth($item, $item->left),
                'refundable' => $item->refundable, 'refund_fee_percent' => $item->refund_fee_percent, 'transferable' => $item->transferable,
                'allowed_branches' => $item->allowed_branches, 'starts_on' => Loyalty::today(), 'expires_on' => $item->expires_on,
                'receipt_id' => null, 'sold_by' => StaffAccess::id(), 'created_at' => now(), 'updated_at' => now()]);
            $reason = trim($data['reason']);
            $this->ledger($item->id, 'transfer_out', -$item->left, $item, $branch, ['reason' => $reason, 'related_package_id' => $copy]);
            $this->ledger($copy, 'transfer_in', $item->left, $item, $branch, ['reason' => $reason, 'related_package_id' => $item->id]);
            DB::table('patient_packages')->where('id', $item->id)->update(['status' => 'transferred', 'closed_at' => now('UTC'), 'updated_at' => now()]);

            return $item->patient_id;
        }, 3);

        return redirect('/staff/patients/'.$patient)->with('status', '已把套票餘額轉讓給受讓患者。');
    }
}
