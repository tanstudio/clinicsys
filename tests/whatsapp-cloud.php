<?php

use App\Http\Controllers\WhatsAppSettingsController;
use App\Messaging\CloudWhatsApp;
use App\Messaging\Delivery;
use App\Messaging\Inbox;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e) { fwrite(STDERR,get_class($e).': '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine().PHP_EOL); exit(1); });
if (!$app->environment('local','testing')) exit(1);
$checks = 0;
function checkCloud($condition,$message) { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function fakeCloud(Closure $callback): void {
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake($callback);
}
$config = ['access_token'=>'test-access-'.Str::random(40),'app_secret'=>Str::random(40),'verify_token'=>Str::random(48),
    'phone_number_id'=>'123456789','business_account_id'=>'987654321','graph_version'=>'v23.0'];
config(['clinic_messaging.whatsapp'=>$config]);
Http::preventStrayRequests();
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);
$http = function ($uri,$method='GET',$raw=null,$signature=null) use ($kernel) {
    $request = Request::create($uri,$method,[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json'], $raw);
    if ($signature !== null) $request->headers->set('X-Hub-Signature-256',$signature);
    return $kernel->handle($request);
};
$payload = fn ($value) => ['object'=>'whatsapp_business_account','entry'=>[['id'=>$config['business_account_id'],
    'changes'=>[['field'=>'messages','value'=>['metadata'=>['phone_number_id'=>$config['phone_number_id']]]+$value]]]]];
$post = function ($data,$valid=true) use ($http,$config) {
    $raw=json_encode($data,JSON_THROW_ON_ERROR);
    return $http('/webhooks/whatsapp','POST',$raw,'sha256='.hash_hmac('sha256',$raw,$valid ? $config['app_secret'] : 'wrong'));
};
$waPhone = '8529'.random_int(1000000,9999999);
$message = fn ($text,$id=null,$time=null) => ['id'=>$id ?? 'wamid.'.Str::uuid(),'from'=>$waPhone,'timestamp'=>(string)($time ?? time()),'type'=>'text','text'=>['body'=>$text]];
DB::beginTransaction();
try {
    $branch = DB::table('branches')->insertGetId(['name_zh'=>'通道測試甲','name_en'=>'Cloud test A']);
    $other = DB::table('branches')->insertGetId(['name_zh'=>'通道測試乙','name_en'=>'Cloud test B']);
    DB::table('whatsapp_settings')->updateOrInsert(['id'=>1],['default_branch_id'=>$branch]);
    $query='?hub.mode=subscribe&hub.verify_token='.$config['verify_token'].'&hub.challenge=12345';
    $challenge=$http('/webhooks/whatsapp'.$query);
    checkCloud($challenge->getStatusCode()===200 && $challenge->getContent()==='12345','Challenge failed');
    checkCloud($http('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=12345')->getStatusCode()===403,'Invalid challenge accepted');
    $event=$payload(['messages'=>[$message('HELP')]]);
    checkCloud($post($event,false)->getStatusCode()===401,'Forged signature accepted');
    checkCloud(!DB::table('message_threads')->where('branch_id',$branch)->exists(),'Unauthenticated callback wrote records');
    $wrong=$event; $wrong['entry'][0]['changes'][0]['value']['metadata']['phone_number_id']='111';
    checkCloud($post($wrong)->getStatusCode()===200 && !DB::table('message_threads')->where('branch_id',$branch)->exists(),'Wrong channel imported');
    $wrong=$event; $wrong['entry'][0]['id']='111';
    checkCloud($post($wrong)->getStatusCode()===200 && !DB::table('message_threads')->where('branch_id',$branch)->exists(),'Wrong account imported');
    $accepted=$post($event);
    checkCloud($accepted->getStatusCode()===200 && json_decode($accepted->getContent(),true)['processed']===1,'Signed callback failed');
    $thread=DB::table('message_threads')->where('branch_id',$branch)->sole();
    checkCloud($post($event)->getStatusCode()===200 && DB::table('message_entries')->where('thread_id',$thread->id)->count()===2,'Duplicate callback duplicated reply');
    checkCloud(!str_contains($thread->phone,$waPhone),'Contact phone was not encrypted');
    $selected=$message('BOOK@'.$other,null,time()+1);
    checkCloud($post($payload(['messages'=>[$selected]]))->getStatusCode()===200,'Branch switch failed');
    checkCloud($post($payload(['messages'=>[$message('HELP',null,time()+2)]]))->getStatusCode()===200,'Following message failed');
    $otherThread=DB::table('message_threads')->where('branch_id',$other)->sole();
    checkCloud(DB::table('message_entries')->where('thread_id',$otherThread->id)->where('direction','inbound')->count()===2,'Conversation did not remain in selected branch');
    $post($payload(['messages'=>[$message('HUMAN',null,time()+3)]]));
    checkCloud(!DB::table('message_entries')->where('thread_id',$otherThread->id)->where('status','queued')->where('event_key','like','reply:incoming:%')->exists(),'Pending bot reply survived human handoff');
    $before=DB::table('message_entries')->where('thread_id',$otherThread->id)->where('direction','outbound')->count();
    $post($payload(['messages'=>[$message('HELP',null,time()+4)]]));
    checkCloud(DB::table('message_entries')->where('thread_id',$otherThread->id)->where('direction','outbound')->count()===$before,'Bot replied during human handoff');
    DB::table('message_threads')->where('id',$otherThread->id)->update(['automation_paused'=>false]);
    DB::table('message_rules')->insert(['branch_id'=>$other,'keyword'=>'營業時間|opening hours','category'=>'hours','reply'=>'請查閱分店排班。']);
    $post($payload(['messages'=>[$message('opening hours please',null,time()+5)]]));
    checkCloud(Crypt::decryptString(DB::table('message_entries')->where('thread_id',$otherThread->id)->latest('id')->value('body'))==='請查閱分店排班。','FAQ synonym did not match');
    $post($payload(['messages'=>[$message('Something unknown',null,time()+6)]]));
    checkCloud((bool)DB::table('message_threads')->where('id',$otherThread->id)->value('automation_paused'),'Unknown enquiry not handed off');
    $post($payload(['messages'=>[$message('STOP',null,time()+7)]]));
    $post($payload(['messages'=>[$message('START',null,time()-86401)]]));
    checkCloud(!DB::table('message_consents')->where('contact_key',$otherThread->contact_key)->value('marketing'),'Delayed START overwrote opt-out');
    // A fresh contact must not change branches with an expired selection.
    $delayed=$message('BOOK@'.$other,null,time()-86401);
    $delayed['from']='8528'.random_int(1000000,9999999);
    checkCloud($post($payload(['messages'=>[$delayed]]))->getStatusCode()===200,'Expired branch selection rejected');
    $contact=hash_hmac('sha256','+'.$delayed['from'],config('app.key'));
    checkCloud((int)DB::table('whatsapp_routes')->where('contact_key',$contact)->value('branch_id')===$branch,'Expired selection changed reception branch');
    checkCloud(!DB::table('message_threads')->where('contact_key',$contact)->where('branch_id',$other)->exists(),'Expired selection created foreign thread');
    // Rules must not assign an inactive, moved or unauthorized account.
    $agent=DB::table('staff_accounts')->insertGetId(['name'=>'Rule QA','email'=>Str::uuid().'@example.test','password_hash'=>'fixture','branch_id'=>$other,'role'=>'reception','active'=>false]);
    DB::table('message_rules')->insert(['branch_id'=>$other,'keyword'=>'route-test','category'=>'routing','assignee_id'=>$agent,'reply'=>'Reception will follow up.']);
    foreach ([['active'=>false],['active'=>true,'branch_id'=>$branch],['active'=>true,'branch_id'=>$other,'role'=>'doctor']] as $attributes) {
        DB::table('staff_accounts')->where('id',$agent)->update($attributes);
        DB::table('message_threads')->where('id',$otherThread->id)->update(['automation_paused'=>false,'assignee_id'=>null]);
        app(Inbox::class)->receive(['branch_id'=>$other,'phone'=>'+'.$waPhone,'message_id'=>'rule:'.Str::uuid(),'text'=>'route-test']);
        $routed=DB::table('message_threads')->find($otherThread->id);
        checkCloud($routed->assignee_id===null && $routed->automation_paused,'Invalid rule assignee did not fall back to human queue');
    }
    // Replying directly takes human ownership even without an explicit assignment.
    DB::table('staff_accounts')->where('id',$agent)->update(['role'=>'reception']);
    $staffRequest=Request::create('/','POST',['body'=>'Staff reply','request_id'=>(string)Str::uuid()]);
    $staffRequest->setLaravelSession(app('session')->driver('array'));
    $staffRequest->session()->put('staff_id',$agent); $app->instance('request',$staffRequest);
    DB::table('message_threads')->where('id',$otherThread->id)->update(['automation_paused'=>false]);
    Inbox::enqueue($otherThread,'reply:incoming:'.Str::uuid(),'reply','Pending bot answer');
    app(\App\Http\Controllers\InboxController::class)->reply($staffRequest,$otherThread->id);
    checkCloud((bool)DB::table('message_threads')->where('id',$otherThread->id)->value('automation_paused'),'Direct staff reply left bot active');
    checkCloud(!DB::table('message_entries')->where('thread_id',$otherThread->id)->where('status','queued')->where('event_key','like','reply:incoming:%')->exists(),'Direct staff reply left pending bot replies');
    checkCloud(DB::table('message_entries')->where('event_key','staff:'.$otherThread->id.':'.$staffRequest->input('request_id'))->value('status')==='queued','Staff reply was suppressed');
    $calls=[];
    fakeCloud(function ($request) use (&$calls,$config) {
        checkCloud($request->url()==='https://graph.facebook.com/v23.0/123456789/messages','Unexpected provider destination');
        checkCloud($request->hasHeader('Authorization','Bearer '.$config['access_token']),'Missing bearer token');
        checkCloud($request['messaging_product']==='whatsapp' && isset($request['biz_opaque_callback_data']),'Missing Meta fields');
        $calls[]=$request->data();
        return Http::response(['messages'=>[['id'=>'wamid.'.Str::uuid()]]],200);
    });
    // Isolate transport probes from replies queued above and pre-existing local fixtures.
    DB::table('message_entries')->where('status','queued')->update(['due_at'=>now()->addDays(30)]);
    Inbox::enqueue($otherThread,'cloud-test:'.Str::uuid(),'reply','Test reply');
    app(Delivery::class)->consume();
    checkCloud(count($calls)===1 && $calls[0]['type']==='text' && $calls[0]['to']===$waPhone,'Freeform request incorrect');
    $out=DB::table('message_entries')->where('thread_id',$otherThread->id)->latest('id')->first();
    checkCloud($out->status==='accepted' && str_starts_with($out->provider_message_id,'wamid.'),'Provider acceptance not recorded');
    $receipt=['id'=>$out->provider_message_id,'recipient_id'=>$waPhone,'status'=>'read','timestamp'=>(string)time(),'biz_opaque_callback_data'=>$out->provider_reference];
    checkCloud($post($payload(['statuses'=>[$receipt]]))->getStatusCode()===200,'Read callback failed');
    $receipt['status']='sent'; $post($payload(['statuses'=>[$receipt]]));
    checkCloud(DB::table('message_entries')->where('id',$out->id)->value('status')==='read','Late callback regressed read state');
    $receipt['recipient_id']='85290000000'; $receipt['status']='failed';
    $post($payload(['statuses'=>[$receipt]]));
    checkCloud(DB::table('message_entries')->where('id',$out->id)->value('status')==='read','Mismatched receipt changed state');
    DB::table('message_entries')->where('thread_id',$otherThread->id)->where('direction','inbound')
        ->update(['provider_received_at'=>now()->subHours(25)]);
    Inbox::enqueue($otherThread,'cloud-expired:'.Str::uuid(),'reply','Expired reply');
    app(Delivery::class)->consume();
    checkCloud(count($calls)===1 && DB::table('message_entries')->where('thread_id',$otherThread->id)->latest('id')->value('error')==='outside_service_window','Expired conversation sent freeform');
    DB::table('whatsapp_templates')->updateOrInsert(['kind'=>'feedback'],['name'=>'clinic_feedback','language'=>'zh_HK','parameters'=>'["branch_name"]','approved'=>true]);
    Inbox::enqueue($otherThread,'cloud-template:'.Str::uuid(),'feedback','Feedback body');
    app(Delivery::class)->consume();
    checkCloud(count($calls)===2 && $calls[1]['type']==='template','Outside-window notification did not use template');
    checkCloud($calls[1]['template']['components'][0]['parameters'][0]['text']==='通道測試乙','Template binding incorrect');
    DB::table('whatsapp_templates')->where('kind','feedback')->update(['approved'=>false]);
    Inbox::enqueue($otherThread,'cloud-unapproved:'.Str::uuid(),'feedback','Disabled template');
    app(Delivery::class)->consume();
    checkCloud(count($calls)===2 && DB::table('message_entries')->where('thread_id',$otherThread->id)->latest('id')->value('status')==='unconfigured','Unapproved template sent');
    // A status callback can beat the HTTP acknowledgement.
    DB::table('whatsapp_templates')->where('kind','feedback')->update(['approved'=>true]);
    fakeCloud(function ($request) use ($waPhone) {
        app(CloudWhatsApp::class)->receipt(['id'=>'wamid.early','recipient_id'=>$waPhone,'status'=>'delivered','biz_opaque_callback_data'=>$request['biz_opaque_callback_data']]);
        return Http::response(['messages'=>[['id'=>'wamid.early']]],200);
    });
    Inbox::enqueue($otherThread,'cloud-early:'.Str::uuid(),'feedback','Early receipt');
    app(Delivery::class)->consume();
    checkCloud(DB::table('message_entries')->where('provider_message_id','wamid.early')->value('status')==='delivered','HTTP response overwrote early delivery receipt');
    fakeCloud(fn()=>Http::response(['messages'=>[['id'=>'wamid.receipt-guard']]],200));
    Inbox::enqueue($otherThread,'cloud-receipt-guard:'.Str::uuid(),'feedback','Receipt recipient guard');
    app(Delivery::class)->consume();
    app(CloudWhatsApp::class)->receipt(['id'=>'wamid.receipt-guard','recipient_id'=>'85290000000','status'=>'read']);
    checkCloud(DB::table('message_entries')->where('provider_message_id','wamid.receipt-guard')->value('status')==='accepted','Wrong recipient promoted delivery state');
    app(CloudWhatsApp::class)->receipt(['id'=>'wamid.receipt-guard','recipient_id'=>$waPhone,'status'=>'failed','errors'=>[['code'=>131026]]]);
    checkCloud(DB::table('message_entries')->where('provider_message_id','wamid.receipt-guard')->value('error')==='META_131026','Meta failure code not recorded');
    fakeCloud(fn()=>Http::response([],200));
    Inbox::enqueue($otherThread,'cloud-malformed:'.Str::uuid(),'feedback','Malformed acknowledgement');
    app(Delivery::class)->consume();
    checkCloud(DB::table('message_entries')->where('thread_id',$otherThread->id)->latest('id')->value('status')==='review','Malformed provider success treated as acceptance');
    fakeCloud(fn()=>Http::response(['messages'=>[['id'=>'wamid.after-corrupt']]],200));
    $corruptKey='cloud-corrupt:'.Str::uuid();
    Inbox::enqueue($otherThread,$corruptKey,'feedback','Unreadable encrypted payload');
    DB::table('message_entries')->where('event_key',$corruptKey)->update(['body'=>'invalid-ciphertext']);
    Inbox::enqueue($otherThread,'cloud-after-corrupt:'.Str::uuid(),'feedback','Healthy next message');
    app(Delivery::class)->consume();
    checkCloud(DB::table('message_entries')->where('event_key',$corruptKey)->value('error')==='encrypted_payload_invalid','Corrupt message did not enter review');
    checkCloud(DB::table('message_entries')->where('provider_message_id','wamid.after-corrupt')->value('status')==='accepted','Corrupt message blocked later delivery');
    $healthy=DB::table('message_entries')->where('provider_message_id','wamid.after-corrupt')->first();
    foreach (['',str_repeat('x',256)] as $badId) {
        app(CloudWhatsApp::class)->receipt(['id'=>$badId,'recipient_id'=>$waPhone,'status'=>'read','biz_opaque_callback_data'=>$healthy->provider_reference]);
        checkCloud(DB::table('message_entries')->where('id',$healthy->id)->value('status')==='accepted','Invalid receipt ID changed delivery state');
    }
    fakeCloud(fn()=>Http::response([],429,['Retry-After'=>'120']));
    $retryKey='cloud-rate:'.Str::uuid();
    Inbox::enqueue($otherThread,$retryKey,'feedback','Rate limited');
    app(Delivery::class)->consume();
    $limited=DB::table('message_entries')->where('event_key',$retryKey)->first();
    checkCloud($limited->status==='queued' && $limited->error==='rate_limited' && strtotime($limited->due_at)>time(),'Rate limit not delayed');
    DB::table('message_entries')->where('id',$limited->id)->update(['attempts'=>4,'due_at'=>now()]);
    app(Delivery::class)->consume();
    checkCloud(DB::table('message_entries')->where('id',$limited->id)->value('status')==='failed','Rate limit retried without bound');
    foreach ([500,400] as $status) {
        fakeCloud(fn()=>Http::response([], $status));
        $failureKey='cloud-http:'.Str::uuid();
        Inbox::enqueue($otherThread,$failureKey,'feedback','HTTP failure');
        app(Delivery::class)->consume();
        checkCloud(DB::table('message_entries')->where('event_key',$failureKey)->value('status')===($status===500?'review':'failed'),'HTTP failure incorrectly retried');
    }
    $large=$http('/webhooks/whatsapp','POST',str_repeat('x',1048577));
    checkCloud($large->getStatusCode()===413,'Oversized callback accepted');
    config(['clinic_messaging.whatsapp.access_token'=>null]);
    $attempts=Http::recorded()->count();
    Inbox::enqueue($otherThread,'cloud-no-token:'.Str::uuid(),'feedback','Missing credential');
    app(Delivery::class)->consume();
    checkCloud(Http::recorded()->count()===$attempts && DB::table('message_entries')->where('thread_id',$otherThread->id)->latest('id')->value('status')==='unconfigured','Missing credentials sent a request');
    config(['clinic_messaging.whatsapp.access_token'=>$config['access_token']]);
    $settings=app(WhatsAppSettingsController::class);
    $login=function ($branchId) use ($app) {
        $id=DB::table('staff_accounts')->insertGetId(['name'=>'Cloud QA','email'=>Str::uuid().'@example.test','password_hash'=>'fixture','branch_id'=>$branchId,'role'=>'admin']);
        $request=Request::create('/staff/whatsapp'); $request->setLaravelSession(app('session')->driver('array'));
        $request->session()->put('staff_id',$id); $app->instance('request',$request);
    };
    $login($branch);
    try {$settings->index(); throw new RuntimeException('Branch admin accessed global secrets configuration');}
    catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {checkCloud($e->getStatusCode()===403,'Wrong settings denial');}
    $login(null);
    $html=$settings->index()->render();
    checkCloud(str_contains($html,'Meta 官方 Cloud API') && !str_contains($html,$config['access_token']) && !str_contains($html,$config['app_secret']),'Settings leaked secrets');
    $save=Request::create('/','POST',['kind'=>'confirmed','name'=>'clinic_confirmed','language'=>'zh_HK','parameters'=>'reference,starts_at,branch_name','approved'=>1]);
    $settings->template($save);
    checkCloud(json_decode(DB::table('whatsapp_templates')->where('kind','confirmed')->value('parameters'),true)===['reference','starts_at','branch_name'],'Template UI mapping not saved');
    $longName=clone $save;
    $longName->merge(['name'=>str_repeat('a',256)]);
    try {$settings->template($longName); throw new RuntimeException('Oversized template name accepted');}
    catch (Illuminate\Validation\ValidationException $e) {checkCloud(isset($e->errors()['name']),'Wrong template length denial');}
    $save->merge(['parameters'=>'clinical_record']);
    try {$settings->template($save); throw new RuntimeException('Invalid template field accepted');}
    catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {checkCloud($e->getStatusCode()===422,'Wrong template denial');}
    // Exercise the signed webhook through CRM, projection and transport for both regions.
    $base=DB::table('slots')->firstOrFail();
    $doctor=DB::table('practitioners')->insertGetId(['name_zh'=>'端到端測試','name_en'=>'Webhook journey QA']);
    DB::table('message_entries')->where('status','queued')->update(['due_at'=>now()->addDays(30)]);
    fakeCloud(fn()=>Http::response(['messages'=>[['id'=>'wamid.'.Str::uuid()]]],200));
    foreach (['8529'.random_int(1000000,9999999),'86138'.random_int(10000000,99999999)] as $offset=>$number) {
        $slot=DB::table('slots')->insertGetId(['branch_id'=>$branch,'practitioner_id'=>$doctor,'service_id'=>$base->service_id,'starts_at'=>today('Asia/Hong_Kong')->addDays(2)->setHour(14+$offset)]);
        $bookingMessage=$message('BOOK+@'.$branch.' '.$slot.' Webhook QA'); $bookingMessage['from']=$number;
        $event=$payload(['messages'=>[$bookingMessage]]);
        checkCloud($post($event)->getStatusCode()===200,'Signed booking callback failed');
        $booking=\App\Models\Appointment::whereSlotId($slot)->sole();
        checkCloud((bool)$booking->whatsapp_opt_in,'Booking consent missing');
        checkCloud(\App\Models\Patient::findOrFail($booking->patient_id)->phone==='+'.$number,'Webhook patient phone mismatch');
        checkCloud($post($event)->getStatusCode()===200 && \App\Models\Appointment::whereSlotId($slot)->count()===1,'Webhook replay duplicated booking');
        app(\App\Messaging\NotificationQueue::class)->collect();
        app(Delivery::class)->consume();
        $notice=DB::table('message_entries')->where('appointment_id',$booking->id)->where('kind','confirmed')->sole();
        checkCloud($notice->status==='accepted','Webhook booking notification was not accepted');
        checkCloud($post($payload(['statuses'=>[['id'=>$notice->provider_message_id,'status'=>'delivered','recipient_id'=>$number]]]))->getStatusCode()===200
            && DB::table('message_entries')->where('id',$notice->id)->value('status')==='delivered','Webhook booking receipt not reconciled');
        $due=DB::table('message_entries')->where('appointment_id',$booking->id)->where('kind','reminder')->sole();
        checkCloud($due->status==='queued' && strtotime($due->due_at)>time(),'Webhook reminder not scheduled');
    }
    foreach (CloudWhatsApp::KINDS as $kind=>$label) DB::table('whatsapp_templates')->updateOrInsert(['kind'=>$kind],
        ['name'=>'clinic_'.$kind,'language'=>'zh_HK','parameters'=>json_encode(CloudWhatsApp::PARAMETERS[$kind]),'approved'=>true]);
    config(['app.url'=>'https://clinic.example.test']);
    $ready=app(\App\Messaging\WhatsAppReadiness::class);
    $checksResult=$ready->checks();
    checkCloud(count($checksResult)===16 && collect($checksResult)->every('passed',true),'Complete local configuration did not pass preflight');
    checkCloud(\Illuminate\Support\Facades\Artisan::call('clinic:whatsapp-check',['--json'=>true])===0,'Preflight CLI failed');
    $output=\Illuminate\Support\Facades\Artisan::output();
    $decoded=json_decode($output,true,512,JSON_THROW_ON_ERROR);
    checkCloud($decoded['passed']===16 && $decoded['total']===16 && $decoded['live_verified']===false,'Preflight claimed live verification');
    checkCloud(!str_contains($output,$config['access_token']) && !str_contains($output,$config['app_secret']) && !str_contains($output,$config['verify_token']),'Preflight leaked credentials');
    config(['clinic_messaging.whatsapp.verify_token'=>'short']);
    DB::table('whatsapp_settings')->where('id',1)->update(['default_branch_id'=>null]);
    DB::table('whatsapp_templates')->where('kind','marketing')->update(['parameters'=>'{"wrong":"body"}']);
    checkCloud(\Illuminate\Support\Facades\Artisan::call('clinic:whatsapp-check',['--json'=>true])===1,'Incomplete configuration passed preflight');
    $missing=collect($ready->checks())->where('passed',false)->pluck('check')->all();
    checkCloud($missing===['verify_token','reception_branch','template_marketing'],'Preflight did not identify exact blockers');
    echo "PASS WhatsApp Cloud: $checks/$checks checks; signed HTTP callbacks, deduplication, branch continuity, handoff, synonyms, delayed consent, Meta payloads, templates, delivery races, expiry and HQ settings. All HTTP transports faked.\n";
} finally { DB::rollBack(); }
