<?php

use App\Actions\CancelAppointment;
use App\Actions\CreateAppointment;
use App\Actions\RescheduleAppointment;
use App\Http\Controllers\StaffBookingController;
use App\Models\Appointment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (!$app->environment('local','testing')) exit(1);

function verify($passed, string $message): void {
    if (!$passed) throw new RuntimeException($message);
}

DB::beginTransaction();
try {
    $branches = DB::table('branches')->limit(2)->pluck('id');
    verify(count($branches) === 2,'Seed two branches before this test');
    $service = DB::table('services')->value('id');
    $doctor = DB::table('practitioners')->insertGetId(['name_zh' => Str::uuid(), 'name_en' => 'QA']);
    DB::table('branch_practitioners')->insert(['branch_id'=>$branches[0], 'practitioner_id'=>$doctor]);
    DB::table('branch_services')->insertOrIgnore(['branch_id'=>$branches[0], 'service_id'=>$service]);
    $staff = DB::table('staff_accounts')->insertGetId(['branch_id' => $branches[0], 'email' => Str::uuid().'@example.invalid',
        'password_hash' => 'disabled', 'name' => 'QA', 'role' => 'reception']);
    $request = Request::create('/staff/schedule', 'POST');
    $app->instance('request', $request);
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put('staff_id', $staff);
    $controller = new StaffBookingController();
    $date = today('Asia/Hong_Kong')->addDays(2)->toDateString();
    $request->merge(['date' => $date,'time' => '10:00','practitioner_id' => $doctor,'service_id' => $service]);
    $controller->addSlot($request);
    $slot = DB::table('slots')->where('practitioner_id',$doctor)->firstOrFail();
    $foreign = DB::table('slots')->insertGetId(['branch_id' => $branches[1],'practitioner_id' => $doctor,
        'service_id' => $service, 'starts_at' => $date.' 10:30']);
    $create = new CreateAppointment();
    $data = ['slot_id' => $slot->id,'name' => 'QA '.Str::uuid(), 'phone' => '91234567', 'email' => 'qa@example.invalid','whatsapp_opt_in' => true];
    $request->merge(['state'=>'closed']);
    $controller->availability($request, $slot->id);
    verify($create->book($data) === null, 'Closed slot booked');
    $request->merge(['state'=>'open']);
    $controller->availability($request, $slot->id);
    verify($create->book($data, null, $branches[1]) === null, 'Foreign branch slot booked');
    $booking = $create->book($data,null,$branches[0]);
    verify($booking && $booking->email === $data['email'] && $booking->getRawOriginal('email') !== $data['email'], 'Email encryption failed');
    verify($booking->whatsapp_opt_in === true,'Opt-in lost');
    $other = $create->book(array_merge($data,['slot_id'=>$foreign]));
    $request->merge(['patient_id'=>$booking->patient_id]);
    $rows = $controller->api($request)->getData()->appointments;
    verify(count($rows) === 1 && $rows[0]->reference === $booking->reference,'Cross-branch API leak');
    verify(!(new RescheduleAppointment())->move($booking,$slot->id,$foreign,$branches[0]),'Moved into a foreign branch');
    verify(!(new CancelAppointment())->cancel($booking,$slot->id,$branches[1]),'Cancelled from foreign branch');

    DB::table('encounters')->insert(['appointment_id'=>$booking->id,'branch_id'=>$branches[0],
        'patient_id'=>$booking->patient_id,'recorded_by'=>$staff,'clinical_record'=>encrypt('QA')]);
    verify(!(new CancelAppointment())->cancel($booking,$slot->id,$branches[0]),'Encounter booking cancelled');
    verify((new CancelAppointment())->cancel($other,$foreign,$branches[1]),'Unable to cancel active booking');
    verify((new CancelAppointment())->cancel($other,$foreign,$branches[1]),'Cancellation retry failed');
    verify($other->fresh()->slot_id === $foreign,'Cancelled slot link lost');
    $replacement = $create->book(array_merge($data,['slot_id'=>$foreign]));
    verify($replacement !== null,'Cancelled slot not released');
    verify(DB::table('appointment_events')->where('appointment_id',$other->id)->orderBy('id')->pluck('event')->all() === ['confirmed','cancelled'], 'Duplicate or missing events');
    verify(Appointment::whereSlotId($foreign)->count()===2,'CRM history lost');
    echo "PASS staff publish/close/reopen, booking capacity, encrypted email, opt-in, branch-scoped API and mutation checks, encounter protection, cancellation retries and history\n";
} finally {
    DB::rollBack();
}
