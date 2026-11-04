<?php

use App\Actions\CancelAppointment;
use App\Actions\RescheduleAppointment;
use App\Messaging\Delivery;
use App\Messaging\Inbox;
use App\Messaging\NotificationQueue;
use App\Models\Appointment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request as OutgoingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (!$app->environment('local','testing')) exit(1);
function verify(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$secret = Str::random(48);
config(['clinic_messaging.flow_url'=>'https://flow.example.test/trigger', 'clinic_messaging.flow_headers'=>['X-Test-Trigger'=>'fixture'],
    'clinic_messaging.inbound_token'=>$secret, 'clinic_messaging.email_enabled'=>true, 'mail.default'=>'smtp',
    'mail.mailers.smtp'=>['transport'=>'array'], 'mail.from'=>['address'=>'clinic@example.test','name'=>'Clinic']]);
$requests = [];
$limited = 0;
Http::preventStrayRequests();
Http::fake(function (OutgoingRequest $r) use (&$requests, &$limited) {
    verify($r->url() === 'https://flow.example.test/trigger' && $r->hasHeader('X-Test-Trigger','fixture'), 'Invalid Flow request');
    $requests[] = $r->data();
    if ($r['body'] === 'limited' && !$limited++) return Http::response([],429,['Retry-After'=>60]);
    if ($r['body'] === 'unknown') return Http::response([],503);
    return Http::response([],202);
});
DB::beginTransaction();
try {
    $base = DB::table('slots')->firstOrFail();
    $branch = DB::table('branches')->insertGetId(['name_zh'=>(string) Str::uuid(),'name_en'=>'Messaging QA']);
    $doctor = DB::table('practitioners')->insertGetId(['name_zh'=>(string) Str::uuid(),'name_en'=>'Messaging QA']);
    $slots = [];
    foreach ([2,3,4,5] as $offset) {
        $slots[] = DB::table('slots')->insertGetId(['branch_id'=>$branch,'practitioner_id'=>$doctor,'service_id'=>$base->service_id,
            'starts_at'=>today('Asia/Hong_Kong')->addDays($offset)->setHour(14)]);
    }
    $phone = '+8529'.random_int(1000000,9999999);
    $payload = ['phone'=>$phone,'branch_id'=>$branch];
    $incoming = fn ($text,$id=null) => app(Inbox::class)->receive($payload + ['text'=>$text,'message_id'=>$id ?? (string) Str::uuid()]);
    $route = app('router')->getRoutes()->match(Request::create('/webhooks/sleekflow','POST'));
    $invoke = function ($token) use ($app,$route,$payload) {
        $req = Request::create('/webhooks/sleekflow','POST', $payload + ['message_id'=>'test-'.Str::uuid(),'text'=>'Hello']);
        $req->headers->set('Authorization','Bearer '.$token);
        return $app->call($route->getAction('uses'), ['request'=>$req]);
    };
    try { $invoke('invalid'); throw new RuntimeException('Invalid token accepted'); }
    catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { verify($e->getStatusCode()===401,'Expected unauthorized'); }
    verify($invoke($secret)->getData()->route === 'triage','New enquiry should reach triage');
    verify($incoming('human')==='human','No handoff');
    verify($incoming('HELP')==='local','Missing FAQ');
    DB::table('message_rules')->insert(['branch_id'=>$branch,'keyword'=>'hours','category'=>'hours','reply'=>'Open by appointment']);
    $id=(string) Str::uuid();
    verify($incoming('hours please',$id)==='local' && $incoming('hours please',$id)==='duplicate','Webhook not idempotent');
    verify($incoming('BRANCHES')==='local' && $incoming('BOOK@'.$branch)==='local','Missing in-channel branch menu');
    $incoming('BOOK+@'.$branch.' '.$slots[0].' Patient QA');
    $booking = Appointment::whereSlotId($slots[0])->sole();
    verify((bool)$booking->whatsapp_opt_in,'Booking-specific opt-in missing');
    $thread=DB::table('message_threads')->where('branch_id',$branch)->where('patient_id',$booking->patient_id)->sole();
    verify(DB::table('message_entries')->where('thread_id',$thread->id)->where('direction','inbound')->exists(),'Booking transcript not linked');
    $incoming('Follow up');
    verify(DB::table('message_entries')->where('thread_id',$thread->id)->where('direction','inbound')->count()===2,'Verified contact routing mismatch');
    $booking->update(['email'=>'qa@example.test']);
    $queue=app(NotificationQueue::class); $delivery=app(Delivery::class);
    $queue->collect(); $queue->collect();
    $out=fn($kind) => DB::table('message_entries')->where('appointment_id',$booking->id)->where('kind',$kind);
    verify($out('reminder')->count()===2 && $out('confirmed')->count()===2,'Wrong projection cardinality');
    $remindAt=Carbon::parse(DB::table('slots')->find($slots[0])->starts_at,'Asia/Hong_Kong')->subDay()->setTimezone(config('app.timezone'))->toDateTimeString();
    verify($out('reminder')->where('due_at',$remindAt)->count()===2,'Reminder timezone error');
    $delivery->consume();
    verify($out('confirmed')->where('status','accepted')->count()===2,'Missing confirmation');
    verify(count(Mail::mailer('smtp')->getSymfonyTransport()->messages())>0,'No test email');
    $incoming('START');
    Inbox::enqueue($thread,'test-promo:'.$branch,'marketing','promotion');
    $incoming('STOP');
    Inbox::enqueue($thread,'test-rate:'.$branch,'reply','limited');
    Inbox::enqueue($thread,'test-unknown:'.$branch,'reply','unknown');
    $delivery->consume();
    $state=fn($key)=>DB::table('message_entries')->where('event_key',$key.':'.$branch)->value('status');
    verify($state('test-promo')==='suppressed' && $state('test-rate')==='queued' && $state('test-unknown')==='review','Consent/rate-limit/ambiguous handling');
    verify(app(RescheduleAppointment::class)->move($booking,$slots[0],$slots[1]),'Could not reschedule');
    $queue->collect();
    DB::table('message_entries')->where('appointment_id',$booking->id)->where('kind','reminder')->update(['due_at'=>now()]);
    $delivery->consume();
    verify($out('reminder')->where('status','superseded')->count()===2 && $out('reminder')->where('status','accepted')->count()===2,'Stale reminder delivered');
    verify(app(CancelAppointment::class)->cancel($booking,$slots[1]),'Could not cancel');
    $queue->collect(); $delivery->consume();
    verify($out('cancelled')->where('status','accepted')->count()===2,'Missing cancellation');
    $incoming('BOOK '.$slots[2].' Another QA');
    $second=Appointment::whereSlotId($slots[2])->sole();
    $queue->collect(); $delivery->consume();
    verify(DB::table('message_entries')->where('appointment_id',$second->id)->where('kind','confirmed')->value('status')==='suppressed','No opt-in honored');
    $incoming('Shared number');
    $unlinked=DB::table('message_threads')->where('branch_id',$branch)->whereNull('patient_id')->sole();
    verify(Crypt::decryptString(DB::table('message_entries')->where('thread_id',$unlinked->id)->latest('id')->value('body'))==='Shared number','Ambiguous number linked');
    $incoming('BOOK+ '.$slots[3].' Receipt QA');
    $paid = Appointment::whereSlotId($slots[3])->sole();
    $visit=DB::table('encounters')->insertGetId(['appointment_id'=>$paid->id,'patient_id'=>$paid->patient_id,
        'branch_id'=>$branch,'recorded_by'=>1,'clinical_record'=>Crypt::encryptString('fixture'),'created_at'=>now(),'updated_at'=>now()]);
    DB::table('receipts')->insert(['reference'=>(string) Str::uuid(),'branch_id'=>$branch,'patient_id'=>$paid->patient_id,
        'appointment_id'=>$paid->id,'encounter_id'=>$visit,'description'=>'QA','amount_cents'=>12500,'method'=>'cash','created_at'=>now()]);
    $queue->collect(); $queue->collect(); $delivery->consume();
    $entries=DB::table('message_entries')->where('appointment_id',$paid->id);
    verify((clone $entries)->where('kind','payment')->where('status','accepted')->count()===1,'No payment notice');
    verify((clone $entries)->where('kind','feedback')->where('status','queued')->where('due_at','>',now()->addHours(23))->count()===1,'No scheduled feedback');
    $controller = app(\App\Http\Controllers\InboxController::class);
    $account = function ($role,$branchId) use ($app) {
        $id = DB::table('staff_accounts')->insertGetId(['name'=>'QA agent','email'=>Str::uuid().'@example.test',
            'password_hash'=>'fixture','branch_id'=>$branchId,'role'=>$role]);
        $req = Request::create('/staff/inbox','GET');
        $req->setLaravelSession(app('session')->driver('array'));
        $req->session()->put('staff_id',$id);
        $app->instance('request',$req);
        return $id;
    };
    $denied = function ($call, $code) {
        try { $call(); throw new RuntimeException("Expected HTTP $code"); }
        catch (Throwable $e) {
            if ($e instanceof Illuminate\Database\RecordNotFoundException && $code===404) return;
            if ($e instanceof Symfony\Component\HttpKernel\Exception\HttpException && $e->getStatusCode()===$code) return;
            throw $e;
        }
    };
    $agentId = $account('reception',$branch);
    $assign = Request::create('/','POST',['assignee_id'=>$agentId,'status'=>'open','category'=>'feedback']);
    $controller->assign($assign,$thread->id);
    verify(DB::table('message_threads')->where('id',$thread->id)->value('assignee_id')===$agentId,'Assignment not persisted');
    $denied(fn () => $controller->campaign($assign),403);
    $account('reception',$base->branch_id);
    $denied(fn () => $controller->show($thread->id),404);
    $account('doctor',$branch);
    $denied(fn () => $controller->show($thread->id),403);
    $account('admin',null);
    $index=$controller->index(Request::create('/staff/inbox?scope=all'))->getData();
    verify($index['threads']->contains('id',$thread->id),'HQ omitted another branch');
    verify($controller->show($thread->id)->getData()['thread']->id===$thread->id,'HQ cannot inspect branch thread');
    echo "PASS messaging: tenant bearer authentication, duplicate callback, triage, FAQ, agent handoff, in-channel booking, consent, CRM, email and WhatsApp confirmations, reminders, reschedule, cancellation, payment, feedback, campaign opt-out, transport failures, assignment, branch isolation and HQ access. All transports faked.\n";
} finally { DB::rollBack(); }
