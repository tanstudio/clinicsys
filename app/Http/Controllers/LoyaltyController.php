<?php

namespace App\Http\Controllers;

use App\Support\Loyalty;
use App\Support\StaffAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tier and package rules (head office) and package operations (branches).
 * Rule values come only from the tables these forms edit.
 */
class LoyaltyController
{
    private function hq(): void
    {
        abort_unless(StaffAccess::hq(), 403);
    }

    public function index()
    {
        $this->hq();
        $plans = DB::table('package_plans as p')->join('services as s', 's.id', '=', 'p.service_id')
            ->select('p.*', 's.name_zh as service')->orderByDesc('p.active')->orderBy('p.name')->get();
        $planBranches = DB::table('package_plan_branches')->get()->groupBy('plan_id')->map(fn ($rows) => $rows->pluck('branch_id')->all());

        return view('loyalty.index', [
            'tiers' => DB::table('customer_tiers')->orderByDesc('active')->orderBy('threshold_cents')->get(),
            'plans' => $plans, 'planBranches' => $planBranches,
            'services' => DB::table('services')->orderBy('name_zh')->pluck('name_zh', 'id'),
            'branches' => DB::table('branches')->orderBy('id')->pluck('name_zh', 'id'),
        ]);
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
        $data = $request->validate([
            'name' => 'required|string|max:80', 'service_id' => 'required|integer|exists:services,id',
            'sessions' => 'required|integer|min:1|max:999', 'bonus_sessions' => 'required|integer|min:0|max:999',
            'price' => 'required|numeric|min:0|max:9999999', 'validity_days' => 'required|integer|min:1|max:3650',
            'branches' => 'required|array|min:1', 'branches.*' => 'integer|distinct|exists:branches,id', 'active' => 'required|boolean',
        ]);
        $row = ['name' => trim($data['name']), 'service_id' => (int) $data['service_id'], 'sessions' => (int) $data['sessions'],
            'bonus_sessions' => (int) $data['bonus_sessions'], 'price_cents' => (int) round($data['price'] * 100),
            'validity_days' => (int) $data['validity_days'], 'active' => (bool) $data['active'], 'updated_at' => now()];
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

    public function sell(Request $request, int $patient)
    {
        StaffAccess::allow('reception');
        $branch = StaffAccess::branch();
        $this->patientInBranch($patient, $branch);
        $data = $request->validate(['plan_id' => 'required|integer', 'method' => 'required|in:cash,card,fps,other']);
        DB::transaction(function () use ($data, $patient, $branch) {
            $plan = DB::table('package_plans')->where('id', $data['plan_id'])->where('active', true)->lockForUpdate()->first();
            $allowed = DB::table('package_plan_branches')->where('plan_id', $data['plan_id'])->pluck('branch_id')->map(fn ($b) => (int) $b)->all();
            if (!$plan || !in_array($branch, $allowed, true)) {
                throw ValidationException::withMessages(['plan_id' => '此方案未在本分店啟用。']);
            }
            $today = CarbonImmutable::today('Asia/Hong_Kong');
            $total = $plan->sessions + $plan->bonus_sessions;
            $receipt = DB::table('receipts')->insertGetId(['reference' => (string) Str::uuid(), 'branch_id' => $branch,
                'patient_id' => $patient, 'appointment_id' => null, 'encounter_id' => null, 'kind' => 'package',
                'description' => '購買療程套票：'.$plan->name, 'amount_cents' => $plan->price_cents,
                'method' => $data['method'], 'created_at' => now('UTC')]);
            $package = DB::table('patient_packages')->insertGetId(['reference' => (string) Str::uuid(), 'patient_id' => $patient,
                'plan_id' => $plan->id, 'sold_branch_id' => $branch, 'service_id' => $plan->service_id, 'name' => $plan->name,
                'total_sessions' => $total, 'price_cents' => $plan->price_cents, 'allowed_branches' => json_encode($allowed),
                'starts_on' => $today->toDateString(), 'expires_on' => $today->addDays($plan->validity_days - 1)->toDateString(),
                'receipt_id' => $receipt, 'sold_by' => StaffAccess::id(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('package_ledger')->insert(['reference' => (string) Str::uuid(), 'package_id' => $package, 'kind' => 'purchase',
                'sessions' => $total, 'branch_id' => $branch, 'staff_id' => StaffAccess::id(), 'created_at' => now('UTC')]);
        }, 3);

        return redirect('/staff/patients/'.$patient)->with('status', '已售出療程套票並簽發收據。');
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

    /** Pays for one finished visit with one session; the HKD 0 receipt keeps the visit out of the unpaid list. */
    public function redeem(Request $request)
    {
        StaffAccess::allow('reception');
        $branch = StaffAccess::branch();
        $data = $request->validate(['encounter_id' => 'required|integer', 'package_id' => 'required|integer']);
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
                throw ValidationException::withMessages(['package_id' => '此套票不適用於這次診症：服務、分店、有效期或餘額不符。']);
            }
            $reference = (string) Str::uuid();
            $id = DB::table('receipts')->insertGetId(['reference' => $reference, 'branch_id' => $branch, 'patient_id' => $visit->patient_id,
                'appointment_id' => $visit->appointment_id, 'encounter_id' => $visit->id, 'kind' => 'redeem',
                'description' => '使用療程套票：'.$package->name.'（剩 '.($package->left - 1).' 次）', 'amount_cents' => 0,
                'method' => 'package', 'created_at' => now('UTC')]);
            DB::table('package_ledger')->insert(['reference' => (string) Str::uuid(), 'package_id' => $package->id, 'kind' => 'redeem',
                'sessions' => -1, 'encounter_id' => $visit->id, 'receipt_reference' => $reference, 'branch_id' => $branch,
                'staff_id' => StaffAccess::id(), 'created_at' => now('UTC')]);

            return $id;
        }, 3);

        return redirect('/staff/pos/'.$receipt);
    }

    /** Reverses one redemption: the session returns and the visit is unpaid again. Both ledger rows stay. */
    public function void(Request $request, int $entry)
    {
        StaffAccess::allow('admin');
        $branch = StaffAccess::branch();
        $data = $request->validate(['reason' => 'required|string|max:200']);
        $patient = DB::transaction(function () use ($entry, $branch, $data) {
            $row = DB::table('package_ledger as l')->join('patient_packages as p', 'p.id', '=', 'l.package_id')
                ->where('l.id', $entry)->where('l.kind', 'redeem')->where('l.branch_id', $branch)->lockForUpdate()
                ->select('l.*', 'p.patient_id')->first();
            abort_unless($row, 404);
            abort_if(DB::table('package_ledger')->where('voids_id', $entry)->exists(), 409, '此扣減已撤銷。');
            DB::table('receipts')->where('reference', $row->receipt_reference)->where('method', 'package')->delete();
            DB::table('package_ledger')->insert(['reference' => (string) Str::uuid(), 'package_id' => $row->package_id, 'kind' => 'void',
                'sessions' => 1, 'encounter_id' => $row->encounter_id, 'receipt_reference' => $row->receipt_reference,
                'branch_id' => $branch, 'staff_id' => StaffAccess::id(), 'reason' => trim($data['reason']),
                'voids_id' => $entry, 'created_at' => now('UTC')]);

            return $row->patient_id;
        }, 3);

        return redirect('/staff/patients/'.$patient)->with('status', '已撤銷套票扣減，診症回到待收款。');
    }
}
