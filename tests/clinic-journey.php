<?php

use App\Messaging\NotificationQueue;
use App\Models\Appointment;
use App\Models\Encounter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
if (!$app->environment('local', 'testing')) {
    exit(1);
}
config(['session.driver' => 'array', 'cache.default' => 'array', 'mail.default' => 'array']);
Http::preventStrayRequests();

function ensureJourney(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function journeyRequest(string $method, string $uri, array $fields = [], int $expected = 200): string
{
    static $cookies = [], $csrf = '';
    $request = Request::create($uri, $method, $fields + ['_token' => $csrf], $cookies, [],
        ['HTTP_ACCEPT' => $expected >= 400 ? 'application/json' : 'text/html']);
    $kernel = app(Kernel::class);
    $response = $kernel->handle($request);
    $body = (string) $response->getContent();
    foreach ($response->headers->getCookies() as $cookie) {
        $cookies[$cookie->getName()] = $cookie->getValue();
    }
    if (preg_match('/name="csrf-token" content="([^"]+)"/', $body, $match)) {
        $csrf = $match[1];
    }
    $kernel->terminate($request, $response);
    ensureJourney($expected === $response->getStatusCode(), "$method $uri expected $expected, got ".$response->getStatusCode()."\n".substr($body, 0, 500));
    return $body;
}

DB::beginTransaction();
try {
    $run = 'Journey-'.Str::random(12);
    $staffEmail = $run.'-staff@example.test';
    $patientEmail = $run.'-patient@example.test';
    $branch = DB::table('branches')->insertGetId(['name_zh' => $run, 'name_en' => $run]);
    $practitioner = DB::table('practitioners')->insertGetId(['name_zh' => $run, 'name_en' => $run]);
    $service = DB::table('services')->insertGetId(['name_zh' => $run, 'name_en' => $run]);
    DB::table('staff_accounts')->insert(['branch_id' => $branch, 'name' => $run, 'email' => $staffEmail,
        'password_hash' => Hash::make('synthetic-journey-secret'), 'role' => 'admin']);
    $visitTime = CarbonImmutable::tomorrow('Asia/Hong_Kong')->addDay()->setHour(10);
    $slot = DB::table('slots')->insertGetId(['branch_id' => $branch, 'practitioner_id' => $practitioner,
        'service_id' => $service, 'starts_at' => $visitTime->toDateTimeString()]);

    journeyRequest('GET','/');
    $available = json_decode(journeyRequest('GET','/availability'), true, flags: JSON_THROW_ON_ERROR);
    ensureJourney(in_array($slot, array_column($available['slots'], 'id'), true), 'Fixture slot absent from public availability.');
    $details = ['name' => $run, 'phone' => '91234567', 'email' => $patientEmail, 'whatsapp_opt_in' => 1, 'slot_id' => $slot];
    $booked = json_decode(journeyRequest('POST','/appointments',$details,201),true,flags: JSON_THROW_ON_ERROR);
    journeyRequest('POST','/appointments',$details,409);
    $appointment = Appointment::whereReference($booked['reference'])->sole();
    ensureJourney($appointment->whatsapp_opt_in && $appointment->email === $patientEmail, 'Booking consent or email lost.');
    $event = DB::table('appointment_events')->where('appointment_id',$appointment->id)->sole();
    ensureJourney($event->event === 'confirmed', 'Booking confirmation event missing.');
    // Collect before the visit so the 24-hour reminder is scheduled while the visit is in the future.
    $queue = new NotificationQueue();
    $queue->collect();

    Carbon::setTestNow($visitTime->addMinutes(10)->utc());
    journeyRequest('GET','/staff/login');
    journeyRequest('POST','/staff/login',['email' => $staffEmail,'password' => 'synthetic-journey-secret'],302);
    $crmUri = '/staff/patients/'.$appointment->patient_id;
    $crm = journeyRequest('GET',$crmUri);
    ensureJourney(str_contains($crm, $booked['reference']) && str_contains($crm,$patientEmail), 'Booking missing from CRM.');
    $herb = $run.' herb';
    $record = ['symptoms' => ['疲倦'], 'diagnosis' => 'Synthetic assessment', 'prescription' => $herb.' 2.5g', 'plan' => 'Review'];
    $newUri = '/staff/encounters/new/'.$appointment->id;
    journeyRequest('POST',$newUri,$record,302);
    journeyRequest('POST',$newUri,$record,409);
    $encounter = Encounter::where('appointment_id',$appointment->id)->sole();
    ensureJourney($encounter->patient_id === $appointment->patient_id && $encounter->branch_id === $branch, 'Encounter ownership diverged.');
    ensureJourney(str_contains(journeyRequest('GET','/staff/encounters/'.$encounter->id),$record['prescription']), 'Recorded prescription missing.');

    $received = ['kind' => 'receive','reference' => (string)Str::uuid(), 'herb' => $herb, 'grams' => '12.500'];
    $dispensed = ['kind' => 'dispense','reference' => (string)Str::uuid(), 'herb' => $herb, 'grams' => '2.500','encounter_id' => $encounter->id];
    journeyRequest('POST','/staff/inventory',$received,302);
    journeyRequest('POST','/staff/inventory',$dispensed,302);
    journeyRequest('POST','/staff/inventory',$dispensed,302);
    $stock = DB::table('herbal_stocks')->where(['branch_id'=>$branch, 'herb'=>$herb])->sole();
    $movement = DB::table('herbal_movements')->where(['encounter_id'=>$encounter->id, 'kind'=>'dispense'])->sole();
    ensureJourney((int)$stock->milligrams === 10000 && $movement->stock_id === $stock->id && (int)$movement->milligrams === 2500, 'Dispensing or retry changed balances.');

    $payment = ['encounter_id' => $encounter->id,'description' => 'Synthetic visit','amount'=>'321.45','method'=>'fps'];
    journeyRequest('POST','/staff/pos',$payment,302);
    journeyRequest('POST','/staff/pos',$payment,302);
    journeyRequest('POST','/staff/pos',array_replace($payment,['amount'=>'222.22']),409);
    $receipt = DB::table('receipts')->where('encounter_id',$encounter->id)->sole();
    ensureJourney($receipt->appointment_id === $appointment->id && $receipt->patient_id === $appointment->patient_id
        && $receipt->branch_id === $branch && (int)$receipt->amount_cents === 32145, 'Receipt ownership or amount diverged.');
    ensureJourney(str_contains(journeyRequest('GET','/staff/pos/'.$receipt->id),$booked['reference']), 'Receipt omits booking reference.');

    $day = $visitTime->toDateString();
    $report = journeyRequest('GET',"/staff/reports?from=$day&to=$day&metric=usage");
    ensureJourney(str_contains($report,'HK$ 321.45') && str_contains($report,$herb) && str_contains($report,'2.500'), 'Report omits revenue or herb usage.');
    ensureJourney(preg_match('/<th>'.preg_quote($run,'/').'<\/th>\s*<td>321\.45<\/td><td>100\.00<\/td><td>0\.00<\/td><td>2\.500<\/td><td>20\.00<\/td><td>2\.00<\/td>/', $report) === 1,
        'Report row disagrees with one booking, one visit, 20% stock usage and two visits per scheduled hour.');

    $queue->collect();
    $queue->collect();
    $thread = DB::table('message_threads')->where(['branch_id'=>$branch,'patient_id'=>$appointment->patient_id])->sole();
    ensureJourney(Crypt::decryptString($thread->phone) === '+852'.$details['phone'], 'WhatsApp thread targets wrong phone.');
    $entries = DB::table('message_entries')->where('appointment_id',$appointment->id)->get();
    $channels = $entries->map(fn ($entry) => $entry->kind.'/'.$entry->channel)->sort()->values()->all();
    ensureJourney($channels === ['confirmed/email','confirmed/whatsapp','feedback/whatsapp','payment/email','payment/whatsapp','reminder/email','reminder/whatsapp'],
        'Unexpected queue entries: '.implode(', ', $channels));
    foreach ($entries as $entry) {
        ensureJourney($entry->status === 'queued' && $entry->thread_id === $thread->id && (int)$entry->attempts === 0, 'Wrong thread or delivery attempted.');
        if ($entry->channel === 'email') {
            ensureJourney(Crypt::decryptString($entry->recipient) === $patientEmail, 'Wrong email recipient.');
        }
        if ($entry->kind === 'reminder') {
            ensureJourney($entry->appointment_event_id === $event->id && $entry->due_at === $visitTime->subDay()->utc()->toDateTimeString(), 'Reminder timing or source incorrect.');
        }
        if ($entry->kind === 'payment') {
            ensureJourney(str_contains(Crypt::decryptString($entry->body), $receipt->reference), 'Payment message omits receipt reference.');
        }
        if ($entry->kind === 'feedback') {
            ensureJourney($entry->event_key === 'encounters:'.$encounter->id.':wa', 'Feedback source incorrect.');
        }
    }
    ensureJourney(str_contains(journeyRequest('GET',$crmUri),'/staff/inbox/'.$thread->id), 'CRM is missing the payment/feedback conversation.');
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    $failed = true;
} finally {
    Carbon::setTestNow();
    DB::rollBack();
}
if (isset($run)) {
    ensureJourney(!DB::table('branches')->where('name_en',$run)->exists(),'Test fixture persisted after rollback.');
}
if (isset($failed)) {
    exit(1);
}
echo "PASS booking → CRM → encounter → dispensing → receipt → six report measures → seven queued notifications; retries deduplicated, fixture rolled back\n";
