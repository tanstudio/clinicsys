<?php

use App\Actions\CreateAppointment;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\StaffBookingController;
use App\Messaging\Delivery;
use App\Messaging\Inbox;
use App\Messaging\NotificationQueue;
use App\Models\Appointment;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1); });
if (!$app->environment('local', 'testing')) exit(1);
function checkPhone(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
Http::preventStrayRequests();
Http::fake(fn () => Http::response(['messages'=>[['id'=>'wamid.'.Str::uuid()]]],200));
config(['clinic_messaging.whatsapp'=>['access_token'=>'test-token','phone_number_id'=>'123456789','graph_version'=>'v23.0'],
    'clinic_messaging.email_enabled'=>false]);
DB::beginTransaction();
try {
    DB::table('whatsapp_templates')->updateOrInsert(['kind'=>'confirmed'],['name'=>'clinic_confirmed','language'=>'zh_HK',
        'parameters'=>'["reference","starts_at","branch_name"]','approved'=>true]);
    $base = DB::table('slots')->firstOrFail();
    $doctor = DB::table('practitioners')->insertGetId(['name_zh'=>(string)Str::uuid(),'name_en'=>'Phone QA']);
    $slots = [];
    for ($i=0; $i<7; $i++) {
        $slots[] = DB::table('slots')->insertGetId(['branch_id'=>$base->branch_id, 'service_id'=>$base->service_id,
            'practitioner_id'=>$doctor, 'starts_at'=>today('Asia/Hong_Kong')->addDays(3)->setHour(9)->addMinutes(30*$i)]);
    }
    $data = ['name'=>'Phone QA '.Str::uuid(), 'phone'=>'91234567', 'slot_id'=>$slots[0]];
    $legacyKey = hash_hmac('sha256',Str::lower($data['name']).'|91234567',config('app.key'));
    $legacy = Patient::create(['identity_key'=>$legacyKey,'name'=>$data['name'],'phone'=>'+85291234567']);
    $create = app(CreateAppointment::class);
    $first = $create->book($data);
    $second = $create->book(array_replace($data,['phone'=>'+85291234567','slot_id'=>$slots[1]]));
    checkPhone($first->patient_id === $legacy->id && $second->patient_id === $legacy->id, 'HK history split');
    $cn = array_replace($data,['phone'=>'+8613800138000','slot_id'=>$slots[2],'whatsapp_opt_in'=>true]);
    $request = Request::create('/appointments','POST',$cn);
    $request->setLaravelSession(app('session')->driver('array'));
    $result = app(BookingController::class)->store($request,$create);
    checkPhone($result->getStatusCode()===201,'Mainland web booking failed');
    $mainland = Appointment::whereReference($result->getData()->reference)->sole();
    $patient = Patient::findOrFail($mainland->patient_id);
    checkPhone($mainland->patient_id !== $legacy->id && $patient->phone === $cn['phone'], 'Region collision or incorrect phone');
    $staff = DB::table('staff_accounts')->insertGetId(['branch_id'=>$base->branch_id,'name'=>'Phone QA',
        'email'=>Str::uuid().'@example.test','password_hash'=>'disabled','role'=>'reception']);
    $staffRequest = Request::create('/staff/schedule/book','POST',array_replace($cn,['slot_id'=>$slots[3]]));
    $staffRequest->setLaravelSession(app('session')->driver('array'));
    $staffRequest->session()->put('staff_id',$staff);
    $app->instance('request',$staffRequest);
    app(StaffBookingController::class)->book($staffRequest,$create);
    checkPhone(Appointment::whereSlotId($slots[3])->sole()->patient_id === $mainland->patient_id, 'Staff creates duplicate mainland patient');
    app(Inbox::class)->receive(['branch_id'=>$base->branch_id,'phone'=>$cn['phone'],
        'message_id'=>(string)Str::uuid(),'text'=>'BOOK+ '.$slots[4].' '.$cn['name']]);
    checkPhone(Appointment::whereSlotId($slots[4])->sole()->patient_id === $mainland->patient_id, 'WhatsApp creates duplicate mainland patient');
    app(NotificationQueue::class)->collect();
    app(Delivery::class)->consume();
    checkPhone(Http::recorded(fn($r)=>$r->url()==='https://graph.facebook.com/v23.0/123456789/messages'
        && $r['to']===ltrim($cn['phone'],'+') && ($r['template']['name'] ?? null)==='clinic_confirmed'
        && ($r['template']['components'][0]['parameters'][0]['text'] ?? null)===$mainland->reference)->count()===1, 'Mainland notification recipient mismatch');
    foreach (['13800138000','+8612800138000','+861380013800','+85213800138000','+6591234567','+8613800138000x', ['+8613800138000']] as $invalid) {
        $request->replace(array_replace($cn,['phone'=>$invalid,'slot_id'=>$slots[5]]));
        checkPhone(app(BookingController::class)->store($request,$create)->getStatusCode()===422,'Invalid number accepted');
    }
    checkPhone(!Appointment::whereSlotId($slots[5])->exists(),'Invalid request persisted');
    checkPhone(!str_contains($patient->getRawOriginal('phone'),'13800138000'),'Plaintext patient phone');
    echo "PASS phone: legacy HK identity preserved; +86 web/staff/WhatsApp share one encrypted patient; outbound keeps +86; 7 invalid inputs rejected. Transports faked.\n";
} finally { DB::rollBack(); }
