<?php

use App\Http\Controllers\CommerceController;
use App\Http\Controllers\OperationsReportController;
use App\Models\Patient;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (!$app->environment('local', 'testing')) exit(1);
set_exception_handler(function (Throwable $e): never {
    fwrite(STDERR, (string) $e.PHP_EOL);
    exit(1);
});

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function denied(callable $action, string $class): void
{
    try {
        $action();
    } catch (Throwable $e) {
        if (!$e instanceof $class) throw $e;
        return;
    }
    throw new RuntimeException("Expected $class");
}
function staff(int $id): void
{
    request()->session()->put('staff_id', $id);
    request()->attributes->remove('clinic_staff');
}

request()->setLaravelSession(app('session.store'));
view()->share('errors', new ViewErrorBag);
DB::beginTransaction();
try {
    $name = (string) Str::uuid();
    $branch = DB::table('branches')->insertGetId(['name_zh' => $name, 'name_en' => 'QA']);
    $other = DB::table('branches')->insertGetId(['name_zh' => $name.'B', 'name_en' => 'QA B']);
    $doctor = DB::table('practitioners')->insertGetId(['name_zh' => $name, 'name_en' => 'QA']);
    $service = DB::table('services')->firstOrFail()->id;
    foreach (['admin' => $branch, 'hq' => null, 'other' => $other, 'hr' => $branch] as $role => $base) {
        $actors[$role] = DB::table('staff_accounts')->insertGetId([
            'name' => 'QA', 'email' => "$role-$name@example.invalid", 'password_hash' => '-', 'active' => true,
            'role' => $role === 'hr' ? 'hr' : 'admin', 'branch_id' => $base,
        ]);
    }
    staff($actors['admin']);
    $patient = Patient::create(['identity_key' => hash('sha256', $name), 'name' => 'QA', 'phone' => '+85291234567']);
    $today = today('Asia/Hong_Kong');
    foreach (['prior', 'visited', 'unbooked', 'cancelled', 'closed', 'other', 'return_again', 'first_timer'] as $i => $kind) {
        $base = $kind === 'other' ? $other : $branch;
        $slot = DB::table('slots')->insertGetId([
            'practitioner_id' => $doctor, 'service_id' => $service, 'branch_id' => $base,
            'starts_at' => ($kind === 'prior' ? $today->copy()->subWeek() : $today->copy())->setHour(8 + $i),
            'closed_at' => $kind === 'closed' ? now() : null,
        ]);
        if (in_array($kind, ['closed', 'unbooked'])) continue;
        $attendee = $kind === 'first_timer'
            ? Patient::create(['identity_key' => hash('sha256', $name.'new'), 'name' => 'New QA', 'phone' => '+85291234568'])
            : $patient;
        $booking = $attendee->appointments()->create(['slot_id' => $slot, 'reference' => (string) Str::uuid()]);
        if ($kind === 'cancelled') {
            DB::table('appointments')->where('id', $booking->id)->update(['cancelled_at' => now()]);
            continue;
        }
        $encounters[$kind] = DB::table('encounters')->insertGetId([
            'branch_id' => $base, 'appointment_id' => $booking->id, 'patient_id' => $attendee->id,
            'clinical_record' => encrypt('{}'), 'recorded_by' => $actors['admin'],
            'created_at' => $kind === 'prior' ? now('UTC')->subWeek() : now('UTC'),
        ]);
    }

    $commerce = new CommerceController();
    $get = fn ($path, $data = []) => Request::create('/staff/'.$path, 'GET', $data);
    $movement = ['reference' => (string) Str::uuid(), 'kind' => 'receive', 'herb' => 'QA', 'grams' => '10.001'];
    $post = fn ($path, $data) => Request::create('/staff/'.$path, 'POST', $data);
    $commerce->move($post('inventory', $movement));
    $commerce->move($post('inventory', $movement));
    // Establish a 9.001 g opening balance and receive another 5 g in the period.
    $oldOut = ['reference' => (string) Str::uuid(), 'grams' => '1', 'kind' => 'dispense'] + $movement;
    $commerce->move($post('inventory', $oldOut));
    DB::table('herbal_movements')->whereIn('reference', [$movement['reference'], $oldOut['reference']])->update(['created_at' => now('UTC')->subWeek()]);
    $commerce->move($post('inventory', ['reference' => (string) Str::uuid(), 'grams' => '5'] + $movement));
    $dispense = ['reference' => (string) Str::uuid(), 'kind' => 'dispense', 'grams' => '3.125', 'encounter_id' => $encounters['visited']] + $movement;
    $commerce->move($post('inventory', $dispense));
    denied(fn () => $commerce->move($post('inventory', ['reference' => (string) Str::uuid(), 'grams' => '11'] + $dispense)), ValidationException::class);
    denied(fn () => $commerce->move($post('inventory', ['reference' => (string) Str::uuid(), 'encounter_id' => $encounters['other']] + $dispense)), RecordNotFoundException::class);
    $stock = DB::table('herbal_stocks')->where('branch_id', $branch)->sole();
    check($stock->milligrams == 10876 && DB::table('herbal_movements')->where('stock_id', $stock->id)->count() === 4, 'Stock balance or ledger mismatch');
    check(str_contains($commerce->inventory($get('inventory'))->render(), '10.876'), 'Inventory page did not show actual stock');
    $pos = $commerce->pos($get('pos'));
    check(str_contains($pos->render(), '確認收款及簽發收據'), 'Open encounter missing');
    check($pos->getData()['patients'][$patient->id]->name === 'QA' && $pos->getData()['encounters']->first()->starts_at, 'Missing patient or appointment context');
    $events = [];
    Event::listen('clinic.receipt.paid', function ($id) use (&$events) { $events[] = $id; });
    $payment = ['encounter_id' => $encounters['visited'], 'description' => 'QA visit', 'amount' => '123.45', 'method' => 'cash'];
    $commerce->collect($post('pos', $payment));
    $commerce->collect($post('pos', $payment));
    denied(fn () => $commerce->collect($post('pos', ['amount' => '1'] + $payment)), HttpException::class);
    $receipt = DB::table('receipts')->where('branch_id', $branch)->sole();
    check($receipt->amount_cents == 12345 && $events == [$receipt->id], 'Receipt or event count mismatch');
    check(str_contains($commerce->receipt($receipt->id)->render(), '123.45'), 'Receipt rendering failed');
    $report = new OperationsReportController();
    $filters = ['from' => $today->toDateString(), 'to' => $today->toDateString(), 'metric' => 'repeat'];
    $view = $report(Request::create('/staff/reports', 'GET', $filters));
    $rows = $view->getData()['summary'];
    check(count($rows) === 1 && $rows[$branch]['revenue'] == 123.45 && $rows[$branch]['usage'] == 3.125, 'Incorrect ledger aggregation');
    check(abs($rows[$branch]['usage_rate'] - 312.5 / 14.001) < 0.00001, 'Inventory usage denominator mismatch');
    check($rows[$branch]['repeat'] == 50 && abs($rows[$branch]['utilization'] - 60) < 0.00001, 'Incorrect repeat or booking rate');
    check(abs($rows[$branch]['efficiency'] - 1.2) < 0.00001 && str_contains($view->render(), '<meter'), 'Efficiency or report chart failed');
    $ledgerFilters = ['from' => $today->toDateString(), 'to' => $today->toDateString()];
    foreach ([$receipt->reference, (string)$receipt->id, DB::table('appointments')->where('id', $receipt->appointment_id)->value('reference')] as $reference) {
        $filtered = $commerce->pos($get('pos', ['reference' => $reference, 'method' => 'cash'] + $ledgerFilters))->getData();
        check($filtered['receipts']->total() === 1 && (int)$filtered['totalCents'] === 12345, 'Receipt reference/date/method mismatch');
    }
    foreach ([['reference'=>'%'], ['reference'=>'0'], ['method'=>'fps']] as $filter) {
        $filtered = $commerce->pos($get('pos', $filter))->getData();
        check($filtered['receipts']->total() === 0 && (int)$filtered['totalCents'] === 0, 'Receipt empty filter mismatch');
    }
    $filteredStock = $commerce->inventory($get('inventory', ['herb_filter'=>'QA','movement_kind'=>'dispense'] + $ledgerFilters))->getData();
    check($filteredStock['movements']->total() === 1 && $filteredStock['movements']->first()->reference === $dispense['reference'], 'Inventory filters mismatch');
    $emptyStock = $commerce->inventory($get('inventory', ['herb_filter'=>'0']))->getData();
    check($emptyStock['movements']->total() === 0 && $emptyStock['stocks']->sole()->milligrams == 10876, 'Ledger filter altered visible stock');
    denied(fn () => $commerce->pos($get('pos', ['from'=>'2026-12-02', 'to'=>'2026-12-01'])), ValidationException::class);
    denied(fn () => $commerce->inventory($get('inventory', ['movement_kind'=>'invalid'])), ValidationException::class);
    denied(fn () => $commerce->pos($get('pos', ['reference'=>['invalid']])), ValidationException::class);
    DB::beginTransaction();
    try {
        $midnight = $today->copy()->startOfDay()->utc();
        foreach ([[$midnight->copy()->subSecond(),0],[$midnight,1],[$midnight->copy()->addDay()->subSecond(),1],[$midnight->copy()->addDay(),0]] as [$at,$count]) {
            DB::table('receipts')->where('id',$receipt->id)->update(['created_at'=>$at]);
            DB::table('herbal_movements')->where('reference',$dispense['reference'])->update(['created_at'=>$at]);
            check($commerce->pos($get('pos',$ledgerFilters))->getData()['receipts']->total() === $count, 'Receipt Hong Kong date boundary');
            check($commerce->inventory($get('inventory',['movement_kind'=>'dispense']+$ledgerFilters))->getData()['movements']->total() === $count, 'Movement Hong Kong date boundary');
        }
    } finally { DB::rollBack(); }
    $csv = $report($get('reports', ['format'=>'csv']+$filters));
    ob_start(); $csv->sendContent(); $bytes = ob_get_clean();
    check(str_starts_with($bytes,"\xEF\xBB\xBF") && $csv->headers->hasCacheControlDirective('no-store'), 'CSV encoding or private cache header');
    $csvRows = array_map('str_getcsv', explode("\n",trim(substr($bytes,3))));
    check(count($csvRows) === 2 && count($csvRows[0]) === 9, 'CSV shape');
    check(array_slice($csvRows[1],3) === ['123.45','60.00','50.00','3.125','22.32','1.20'], 'CSV values differ from screen');
    DB::beginTransaction();
    try {
        DB::table('branches')->where('id',$branch)->update(['name_zh'=>' =1+1']);
        ob_start(); $report($get('reports',['format'=>'csv']+$filters))->sendContent(); $formulaCsv=ob_get_clean();
        check(str_contains($formulaCsv,"' =1+1"), 'CSV branch formula was not escaped');
    } finally { DB::rollBack(); }
    denied(fn () => $report(Request::create('/staff/reports', 'GET', ['branch' => $other] + $filters)), HttpException::class);
    staff($actors['other']);
    check($commerce->pos($get('pos',['reference'=>$receipt->reference]))->getData()['receipts']->total() === 0, 'Cross-branch receipt search');
    check($commerce->inventory($get('inventory',['herb_filter'=>'QA']))->getData()['movements']->total() === 0, 'Cross-branch inventory search');
    denied(fn () => $commerce->receipt($receipt->id), RecordNotFoundException::class);
    denied(fn () => $commerce->collect($post('pos', $payment)), RecordNotFoundException::class);
    $commerce->collect($post('pos', ['encounter_id' => $encounters['other'], 'amount' => '5'] + $payment));
    staff($actors['hq']);
    $hq = $report(Request::create('/staff/reports', 'GET', $filters))->getData()['summary'];
    check($hq[$branch]['revenue'] + $hq[$other]['revenue'] == 128.45, 'Consolidated revenue failed');
    ob_start(); $report($get('reports',['format'=>'csv','branch'=>$other]+$filters))->sendContent(); $scopedCsv=ob_get_clean();
    $scopedRows=array_map('str_getcsv',explode("\n",trim(substr($scopedCsv,3))));
    check(count($scopedRows)===2 && $scopedRows[1][2]===$name.'B' && $scopedRows[1][3]==='5.00' && $scopedRows[1][7]==='', 'HQ scoped CSV or missing-denominator behavior');
    staff($actors['hr']);
    denied(fn () => $commerce->inventory($get('inventory')), HttpException::class);
    denied(fn () => $commerce->pos($get('pos')), HttpException::class);
    denied(fn () => $report($get('reports',['format'=>'csv'])), HttpException::class);
    denied(fn () => $report(Request::create('/staff/reports')), HttpException::class);
    echo "PASS stock/payment integrity, patient context, ledger filters, four Hong Kong date boundaries, empty results, branch/role isolation, six CSV measures, formula escaping, null ratios and HQ exports\n";
} finally {
    DB::rollBack();
}
