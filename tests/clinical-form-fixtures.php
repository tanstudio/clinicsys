<?php

use App\Models\Patient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
[$script, $mode, $run] = $argv;
if (!$app->environment('local', 'testing') || !Str::isUuid($run)) exit(1);
$email = 'clinical-form.'.$run.'@example.test';
$identity = hash('sha256', 'clinical-form:'.$run);

if ($mode === 'create') {
    $result = DB::transaction(function () use ($email, $identity) {
        $branch = DB::table('branches')->orderBy('id')->value('id');
        $slots = DB::table('slots')->where('branch_id', $branch)->whereNull('closed_at')
            ->whereNotIn('id', DB::table('appointments')->whereNull('cancelled_at')->select('slot_id'))
            ->limit(2)->pluck('id');
        if ($slots->count() !== 2) throw new RuntimeException('Two local fixture slots required.');
        $password = Str::random(36);
        DB::table('staff_accounts')->insert(['name' => '本機測試醫師', 'email' => $email,
            'branch_id' => $branch, 'role' => 'doctor', 'password_hash' => Hash::make($password)]);
        $patient = Patient::create(['identity_key' => $identity, 'name' => '本機測試患者', 'phone' => '+85291234567']);
        $appointments = [];
        foreach ($slots as $slot) {
            $appointments[] = $patient->appointments()->create(['slot_id' => $slot, 'reference' => (string) Str::uuid()])->id;
        }
        return compact('email', 'password', 'appointments');
    });
    echo json_encode($result, JSON_THROW_ON_ERROR);
} elseif ($mode === 'cleanup') {
    DB::transaction(function () use ($email, $identity) {
        $patient = Patient::where('identity_key', $identity)->first();
        if ($patient) {
            $encounters = DB::table('encounters')->where('patient_id', $patient->id)->pluck('id');
            DB::table('message_projections')->where('source', 'encounters')->whereIn('source_id', $encounters)->delete();
            DB::table('message_threads')->where('patient_id', $patient->id)->delete();
            DB::table('encounters')->whereIn('id', $encounters)->delete();
            DB::table('appointments')->where('patient_id', $patient->id)->delete();
            $patient->delete();
        }
        $accounts = DB::table('staff_accounts')->where('email', $email)->pluck('id');
        DB::table('staff_access_log')->whereIn('staff_id', $accounts)->delete();
        DB::table('staff_accounts')->whereIn('id', $accounts)->delete();
    });
} else {
    exit(1);
}
