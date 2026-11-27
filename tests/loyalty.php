<?php

// Customer tiers and session packages: rules from the database, sale, redemption, void, expiry and reports.

use App\Messaging\NotificationQueue;
use App\Support\Loyalty;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
set_exception_handler(function (Throwable $e) { fwrite(STDERR, $e->getMessage().PHP_EOL); exit(1); });
if (!$app->environment('local', 'testing')) {
    exit(1);
}
$app->instance('env', 'testing');
config(['cache.default' => 'array', 'session.driver' => 'array']);
$cookies = [];
function call(string $method, string $url, int $expected, array $data = []): string
{
    global $cookies, $kernel;
    $request = Request::create($url, $method, $data, $cookies, [], ['HTTP_ACCEPT' => 'application/json']);
    $response = $kernel->handle($request);
    foreach ($response->headers->getCookies() as $cookie) {
        $cookies[$cookie->getName()] = $cookie->getValue();
    }
    $kernel->terminate($request, $response);
    if ($response->getStatusCode() !== $expected) {
        throw new RuntimeException("$method $url expected $expected, got ".$response->getStatusCode()."\n".substr($response->getContent(), 0, 600));
    }
    return $response->getContent();
}
function ok(bool $pass, string $message): void
{
    if (!$pass) {
        throw new RuntimeException('FAIL '.$message);
    }
}

DB::beginTransaction();
try {
    $s = Str::random(8);
    $branchA = DB::table('branches')->insertGetId(['name_zh' => "套票測試A$s", 'name_en' => "A$s"]);
    $branchB = DB::table('branches')->insertGetId(['name_zh' => "套票測試B$s", 'name_en' => "B$s"]);
    $acup = DB::table('services')->insertGetId(['name_zh' => "針灸$s", 'name_en' => "Acu$s"]);
    $herb = DB::table('services')->insertGetId(['name_zh' => "內科$s", 'name_en' => "Med$s"]);
    $doctor = DB::table('practitioners')->insertGetId(['name_zh' => "醫師$s", 'name_en' => "Dr$s"]);
    $password = Str::random(24);
    $account = fn ($role, $branch) => DB::table('staff_accounts')->insertGetId(['name' => "$role$s", 'email' => "$role.".($branch ?? 'hq').".$s@example.test",
        'password_hash' => Hash::make($password), 'role' => $role, 'branch_id' => $branch]);
    $staff = ['hq' => $account('admin', null), 'adminA' => $account('admin', $branchA), 'receptionA' => $account('reception', $branchA),
        'receptionB' => $account('reception', $branchB)];
    $login = function ($who) use ($staff, $password) {
        // The login route allows 10 attempts a minute; this suite switches accounts more often than that.
        cache()->store('array')->flush();
        call('POST', '/staff/login', 302, ['email' => DB::table('staff_accounts')->where('id', $staff[$who])->value('email'), 'password' => $password]);
    };
    $patient = App\Models\Patient::create(['identity_key' => hash('sha256', "loyalty$s"), 'name' => '套票測試病人', 'phone' => '+85291119999']);
    $today = Loyalty::today();
    $visit = function ($branch, $service, $minutes) use ($doctor, $patient, $today) {
        $slot = DB::table('slots')->insertGetId(['branch_id' => $branch, 'service_id' => $service, 'practitioner_id' => $doctor,
            'starts_at' => $today.' '.sprintf('%02d:%02d:00', 8 + intdiv($minutes, 60), $minutes % 60)]);
        $appointment = $patient->appointments()->create(['slot_id' => $slot, 'reference' => (string) Str::uuid()]);
        return DB::table('encounters')->insertGetId(['appointment_id' => $appointment->id, 'branch_id' => $branch, 'patient_id' => $patient->id,
            'recorded_by' => 1, 'clinical_record' => encrypt('{}'), 'created_at' => now(), 'updated_at' => now()]);
    };
    $e1 = $visit($branchA, $acup, 0); $e2 = $visit($branchA, $acup, 30); $e3 = $visit($branchA, $herb, 60); $e4 = $visit($branchB, $acup, 90);

    // Rules: head office only.
    $login('receptionA'); call('GET', '/staff/loyalty', 403);
    $login('adminA'); call('GET', '/staff/loyalty', 403);
    call('POST', '/staff/loyalty/tiers', 403, ['name' => 'X', 'threshold' => 1, 'window_months' => 12, 'discount_percent' => 1, 'active' => 1]);
    $login('hq'); call('GET', '/staff/loyalty', 200);
    call('POST', '/staff/loyalty/tiers', 302, ['name' => "銀$s", 'threshold' => '100', 'window_months' => '12', 'discount_percent' => '5', 'active' => '1']);
    call('POST', '/staff/loyalty/tiers', 302, ['name' => "金$s", 'threshold' => '1000', 'window_months' => '12', 'discount_percent' => '10', 'active' => '1']);
    $silver = DB::table('customer_tiers')->where('name', "銀$s")->value('id');
    $gold = DB::table('customer_tiers')->where('name', "金$s")->value('id');
    call('POST', '/staff/loyalty/plans', 302, ['name' => "針灸套票$s", 'service_id' => (string) $acup, 'sessions' => '2', 'bonus_sessions' => '1',
        'price' => '300', 'validity_days' => '30', 'branches' => [(string) $branchA], 'active' => '1']);
    $plan = DB::table('package_plans')->where('name', "針灸套票$s")->value('id');
    ok(str_contains(call('GET', '/staff/loyalty', 200), "針灸套票$s"), 'Rule page lists the plan');
    ok(Loyalty::tier($patient->id)['tier']?->name !== "銀$s", 'No tier before any spend');

    // Sale at branch A: a package receipt, a ledger row, the plan's values copied.
    $login('receptionA');
    call('POST', "/staff/patients/{$patient->id}/packages", 302, ['plan_id' => (string) $plan, 'method' => 'cash']);
    $package = DB::table('patient_packages')->where('patient_id', $patient->id)->first();
    ok($package && $package->total_sessions === 3 && $package->expires_on === Carbon\CarbonImmutable::parse($today)->addDays(29)->toDateString(), 'Sold package values');
    $sale = DB::table('receipts')->find($package->receipt_id);
    ok($sale->kind === 'package' && $sale->amount_cents === 30000 && $sale->appointment_id === null, 'Package sale receipt');
    ok(Loyalty::balances([$package->id])[$package->id] === 3, 'Balance after sale');
    ok(Loyalty::tier($patient->id)['tier']?->id === $silver, 'Spend of HKD 300 reaches the 100 threshold');
    ok(str_contains(call('GET', '/staff/pos', 200), '購買療程套票'), 'POS lists the package sale receipt');
    ok(str_contains(call('GET', "/staff/pos/{$sale->id}", 200), '療程套票購買'), 'Package sale receipt page');

    // A later rule change never alters a sold package.
    $login('hq');
    call('POST', "/staff/loyalty/plans/$plan", 302, ['name' => "針灸套票$s", 'service_id' => (string) $acup, 'sessions' => '9', 'bonus_sessions' => '0',
        'price' => '999', 'validity_days' => '5', 'branches' => [(string) $branchA], 'active' => '1']);
    ok(DB::table('patient_packages')->where('id', $package->id)->value('total_sessions') === 3, 'Sold package keeps its sessions');

    // Redemption: only a matching visit, once.
    $login('receptionA');
    $pos = call('GET', '/staff/pos', 200);
    ok(str_contains($pos, '以療程套票支付') && str_contains($pos, "#$e1") && str_contains($pos, "銀$s"), 'POS offers redemption and shows the tier');
    call('POST', '/staff/pos/redeem', 422, ['encounter_id' => (string) $e3, 'package_id' => (string) $package->id]);
    call('POST', '/staff/pos/redeem', 302, ['encounter_id' => (string) $e1, 'package_id' => (string) $package->id]);
    $redeemed = DB::table('receipts')->where('encounter_id', $e1)->first();
    ok($redeemed->amount_cents === 0 && $redeemed->method === 'package' && $redeemed->kind === 'redeem', 'HKD 0 redemption receipt');
    ok(Loyalty::balances([$package->id])[$package->id] === 2, 'Balance after redemption');
    call('POST', '/staff/pos/redeem', 422, ['encounter_id' => (string) $e1, 'package_id' => (string) $package->id]);
    call('POST', '/staff/pos', 409, ['encounter_id' => (string) $e1, 'description' => 'x', 'amount' => '1', 'method' => 'cash']);

    // A paid visit records the tier that applied.
    call('POST', '/staff/pos', 302, ['encounter_id' => (string) $e2, 'description' => '診金', 'amount' => '95', 'method' => 'fps']);
    $paid = DB::table('receipts')->where('encounter_id', $e2)->first();
    ok($paid->tier_name === "銀$s" && $paid->discount_percent === 5, 'Receipt keeps the tier snapshot');

    // Another branch cannot use a package limited to branch A.
    $login('receptionB');
    call('POST', '/staff/pos/redeem', 422, ['encounter_id' => (string) $e4, 'package_id' => (string) $package->id]);

    // Void: admin only, once; the session and the unpaid visit come back.
    $entry = DB::table('package_ledger')->where('package_id', $package->id)->where('kind', 'redeem')->value('id');
    $login('receptionA');
    call('POST', "/staff/packages/ledger/$entry/void", 403, ['reason' => 'x']);
    $login('adminA');
    call('POST', "/staff/packages/ledger/$entry/void", 302, ['reason' => '扣錯套票']);
    ok(Loyalty::balances([$package->id])[$package->id] === 3, 'Balance after void');
    ok(!DB::table('receipts')->where('encounter_id', $e1)->exists(), 'Visit is unpaid again');
    call('POST', "/staff/packages/ledger/$entry/void", 409, ['reason' => 'again']);
    ok(str_contains(call('GET', "/staff/patients/{$patient->id}", 200), '撤銷扣減'), 'CRM lists the void');

    // Expiry stops redemption.
    DB::table('patient_packages')->where('id', $package->id)->update(['expires_on' => Carbon\CarbonImmutable::parse($today)->subDay()->toDateString()]);
    $login('receptionA');
    call('POST', '/staff/pos/redeem', 422, ['encounter_id' => (string) $e1, 'package_id' => (string) $package->id]);

    // Manual tier: head office sets and clears it.
    $login('hq');
    call('POST', '/staff/branch', 302, ['branch_id' => (string) $branchA]);
    call('POST', "/staff/patients/{$patient->id}/tier", 302, ['tier_id' => (string) $gold, 'reason' => '長期客戶']);
    $manual = Loyalty::tier($patient->id);
    ok($manual['manual'] && $manual['tier']->id === $gold, 'Manual tier wins');
    call('POST', "/staff/patients/{$patient->id}/tier", 302, ['tier_id' => '', 'reason' => '']);
    ok(Loyalty::tier($patient->id)['tier']?->id === $silver, 'Cleared manual tier falls back to spend');
    $login('adminA');
    call('POST', "/staff/patients/{$patient->id}/tier", 403, ['tier_id' => (string) $gold, 'reason' => 'x']);

    // Reports and notifications.
    $login('hq');
    $report = call('GET', "/staff/reports?from=$today&to=$today&branch=$branchA", 200);
    ok(str_contains($report, '療程套票') && str_contains($report, '300.00'), 'Report shows package sales');
    app(NotificationQueue::class)->collect();
    ok(!DB::table('message_entries')->where('event_key', 'receipts:'.$sale->id.':wa')->exists(), 'No payment notice for a package sale');

    echo "PASS loyalty: head-office rules, spend tiers and manual override, package sale with copied values, redemption limited by service, branch, expiry and balance, one receipt per visit, tier snapshot on receipts, admin-only void, reports and no notice for package receipts\n";
} finally {
    DB::rollBack();
}
