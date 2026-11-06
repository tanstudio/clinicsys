<?php

use App\Actions\CreateAppointment;
use App\Models\Encounter;
use App\Models\Patient;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$mode = $argv[1] ?? '';
$expected = $mode === 'create' ? 'clinic_recovery_source' : 'clinic_recovery_restored';
if (!$app->environment('testing') || DB::connection()->getDatabaseName() !== $expected
    || config('database.connections.mysql.host') !== '127.0.0.1') exit(1);
function recoveryCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$note = 'Synthetic recovery clinical record';
$file = "%PDF-1.4\nSynthetic recovery attachment\n%%EOF";
if ($mode === 'create') {
    $slot = DB::table('slots')->where('starts_at','>',now('Asia/Hong_Kong')->addDay())->firstOrFail();
    $a = app(CreateAppointment::class)->book(['name'=>'Synthetic recovery patient','phone'=>'+8613800138000','slot_id'=>$slot->id]);
    Encounter::create(['appointment_id'=>$a->id,'patient_id'=>$a->patient_id,'branch_id'=>$slot->branch_id,
        'recorded_by'=>1,'clinical_record'=>['diagnosis'=>$note]]);
    $staff = DB::table('staff_accounts')->insertGetId(['email'=>'recovery@example.test','name'=>'Synthetic recovery staff',
        'password_hash'=>'disabled','role'=>'hr','branch_id'=>$slot->branch_id]);
    DB::table('hr_documents')->insert(['staff_id'=>$staff,'kind'=>'contract',
        'filename'=>Crypt::encryptString('fixture.pdf'),'contents'=>Crypt::encryptString($file),'uploaded_at'=>now()]);
} elseif ($mode === 'verify') {
    $source = config('database.connections.mysql');
    $source['database'] = 'clinic_recovery_source';
    config(['database.connections.recovery_source'=>$source]);
    $tables = DB::select('SHOW TABLES');
    foreach ($tables as $row) {
        $table = array_values((array)$row)[0];
        $before = DB::connection('recovery_source')->table($table)->get()->map(fn($r)=>json_encode($r))->sort()->values()->all();
        $after = DB::table($table)->get()->map(fn($r)=>json_encode($r))->sort()->values()->all();
        recoveryCheck($before === $after,'Restored rows differ in '.$table);
    }
    $patient = Patient::sole();
    recoveryCheck($patient->name === 'Synthetic recovery patient' && $patient->phone === '+8613800138000','Patient recovery failed');
    recoveryCheck(Encounter::sole()->clinical_record['diagnosis'] === $note,'Clinical recovery failed');
    $document = DB::table('hr_documents')->sole();
    recoveryCheck(Crypt::decryptString($document->contents) === $file,'Attachment recovery failed');
    recoveryCheck(Crypt::decryptString($document->filename) === 'fixture.pdf','Filename recovery failed');
    try {
        (new Encrypter(random_bytes(32),'AES-256-CBC'))->decryptString($document->contents);
        throw new RuntimeException('Wrong encryption key accepted');
    } catch (Illuminate\Contracts\Encryption\DecryptException) { /* Expected. */ }
    echo 'Verified '.count($tables).' of '.count($tables)." restored tables.\n";
} else { exit(1); }
