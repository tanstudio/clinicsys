<?php

use App\Models\Encounter;
use App\Models\Patient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
[$script, $mode, $run] = $argv;
if (!$app->environment('local', 'testing') || !Str::isUuid($run)) exit(1);
$key = 'Operations-'.$run;
$email = $key.'@example.test';
if ($mode === 'create') {
    $fixture = DB::transaction(function () use ($key, $email) {
        $branch = DB::table('branches')->insertGetId(['name_zh'=>'本機營運測試 '.$key, 'name_en'=>$key]);
        $practitioner = DB::table('practitioners')->insertGetId(['name_zh'=>$key,'name_en'=>$key]);
        $service = DB::table('services')->insertGetId(['name_zh'=>$key,'name_en'=>$key]);
        $password = Str::random(36);
        $staff = DB::table('staff_accounts')->insertGetId(['name'=>'本機測試行政', 'email'=>$email,
            'password_hash'=>Hash::make($password), 'role'=>'admin', 'branch_id'=>$branch]);
        $patient = Patient::create(['identity_key'=>hash('sha256',$key),'name'=>'本機測試患者','phone'=>'+85291234567']);
        $day = today('Asia/Hong_Kong')->toDateString();
        $created = today('Asia/Hong_Kong')->setHour(12)->utc();
        for ($i=0; $i<28; $i++) {
            $slot = DB::table('slots')->insertGetId(['branch_id'=>$branch,'practitioner_id'=>$practitioner,
                'service_id'=>$service,'starts_at'=>today('Asia/Hong_Kong')->setHour(8)->addMinutes(30*$i)]);
            $appointment = $patient->appointments()->create(['slot_id'=>$slot,'reference'=>(string)Str::uuid()]);
            $encounter = Encounter::create(['appointment_id'=>$appointment->id,'patient_id'=>$patient->id,'branch_id'=>$branch,
                'recorded_by'=>$staff,'clinical_record'=>['symptoms'=>[],'notes'=>'','diagnosis'=>'合成測試紀錄','prescription'=>'','plan'=>'']]);
            if ($i===27) continue;
            $reference = (string)Str::uuid();
            $receipt = DB::table('receipts')->insertGetId(['reference'=>$reference,'branch_id'=>$branch,'patient_id'=>$patient->id,
                'appointment_id'=>$appointment->id,'encounter_id'=>$encounter->id,'description'=>'本機測試診金',
                'amount_cents'=>$i===26 ? 24500 : 12500,'method'=>$i===26 ? 'fps' : 'cash','created_at'=>$created]);
        }
        $stock = DB::table('herbal_stocks')->insertGetId(['branch_id'=>$branch,'herb'=>'本機測試藥材','milligrams'=>25000]);
        for ($i=0; $i<27; $i++) {
            DB::table('herbal_movements')->insert(['reference'=>(string)Str::uuid(),'stock_id'=>$stock,
                'kind'=>$i===26 ? 'dispense' : 'receive','milligrams'=>1000,'created_at'=>$created]);
        }
        return compact('email','password','day','reference','receipt');
    });
    echo json_encode($fixture, JSON_THROW_ON_ERROR);
} elseif ($mode === 'cleanup') {
    DB::transaction(function () use ($key, $email) {
        $branch = DB::table('branches')->where('name_en',$key)->value('id');
        if (!$branch) return;
        foreach (['receipts','encounters'] as $table) {
            DB::table('message_projections')->where('source',$table)->whereIn('source_id', DB::table($table)->where('branch_id',$branch)->select('id'))->delete();
        }
        DB::table('message_threads')->where('branch_id',$branch)->delete();
        DB::table('herbal_movements')->whereIn('stock_id', DB::table('herbal_stocks')->where('branch_id',$branch)->select('id'))->delete();
        DB::table('herbal_stocks')->where('branch_id',$branch)->delete();
        DB::table('receipts')->where('branch_id',$branch)->delete();
        DB::table('encounters')->where('branch_id',$branch)->delete();
        DB::table('appointments')->whereIn('slot_id', DB::table('slots')->where('branch_id',$branch)->select('id'))->delete();
        Patient::where('identity_key',hash('sha256',$key))->delete();
        DB::table('slots')->where('branch_id',$branch)->delete();
        DB::table('staff_access_log')->where('branch_id',$branch)->delete();
        DB::table('staff_accounts')->where('email',$email)->delete();
        DB::table('branches')->where('id',$branch)->delete();
        foreach (['services','practitioners'] as $table) DB::table($table)->where('name_en',$key)->delete();
    });
    echo "Cleaned synthetic operations fixture\n";
} else { exit(1); }
