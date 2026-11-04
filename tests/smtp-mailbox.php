<?php
use App\Actions\CreateAppointment;
use App\Actions\RescheduleAppointment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error) { fwrite(STDERR, $error->getMessage().PHP_EOL); exit(1); });
function check($condition,$text){ if (!$condition) throw new RuntimeException($text); }
check(DB::connection()->getDatabaseName()==='clinic_smtp_qa','Requires disposable database');
$from = 'clinic@review.test'; $to='patient@review.test';
config(['clinic_messaging.flow_url'=>null,'clinic_messaging.email_enabled'=>true,'mail.default'=>'smtp','mail.mailers.smtp'=>['transport'=>'smtp','host'=>'127.0.0.1','port'=>(int)getenv('TEST_SMTP_PORT'),'timeout'=>5],'mail.from'=>['address'=>$from,'name'=>'Clinic']]);
$api='http://127.0.0.1:'.(int)getenv('TEST_MAILBOX_PORT').'/api/v1/';
function mailbox(int $expected) {
 global $api,$from,$to;
 // Mailpit can acknowledge SMTP DATA before its search index shows the message.
 $until = microtime(true) + 5;
 do {
     $index = json_decode(file_get_contents($api.'messages'), true, flags: JSON_THROW_ON_ERROR);
     if (count($index['messages']) >= $expected) break;
     usleep(100000);
 } while (microtime(true) < $until);
 return array_map(function($summary) use($api,$from,$to){
    $m=json_decode(file_get_contents($api.'message/'.$summary['ID']),true,flags:JSON_THROW_ON_ERROR);
    check($m['To'][0]['Address']===$to && $m['From']['Address']===$from,'Envelope mismatch');
    return $m['Text'];
 },$index['messages']);
}
function tick(){check(Artisan::call('clinic:messages')===0,'Notification command failed');}
$base = Carbon::now('Asia/Hong_Kong')->startOfDay()->addHours(8);
Carbon::setTestNow($base);
$slot = fn($d) => DB::table('slots')->whereDate('starts_at',$base->copy()->addDays($d))->orderBy('id')->firstOrFail();
$old=$slot(3);$new=$slot(4);
$a=app(CreateAppointment::class)->book(['slot_id'=>$old->id,'name'=>'Synthetic mailbox test','phone'=>'91234567','email'=>$to]);
check($a,'No appointment');tick();tick();
$m=mailbox(1);
check(count($m)===1 && str_contains($m[0],$a->reference) && str_contains($m[0],$old->starts_at) && str_contains($m[0],'Appointment confirmed'),'Confirmation mailbox mismatch');
echo "NT-03 PASS: real SMTP; one confirmation after two worker runs; mailbox recipient, reference and original slot match.\n";
check(app(RescheduleAppointment::class)->move($a,$old->id,$new->id),'Move failed');tick();
check(count(mailbox(2))===2,'Missing reschedule mail');
$at=Carbon::parse($new->starts_at,'Asia/Hong_Kong')->subDay();
Carbon::setTestNow($at->copy()->subMinute());tick();
check(count(mailbox(2))===2,'Stale or early reminder');
Carbon::setTestNow($at);tick();tick();
$m=mailbox(3);
check(count($m)===3 && count(array_filter($m,fn($x)=>str_contains($x,'Appointment reminder') && str_contains($x,$new->starts_at) && str_contains($x,$a->reference)))===1,'New reminder wrong');
echo "NT-04 PASS: real SMTP; 0 reminders at T-24h-1min; exactly 1 at T-24h for the rescheduled slot after two worker runs.\n";
