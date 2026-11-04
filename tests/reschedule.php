<?php

use App\Actions\RescheduleAppointment;
use App\Models\Appointment;
use App\Models\Patient;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (!$app->environment('local', 'testing')) {
    exit(1);
}
function check(bool $passed, string $message): void
{
    if (!$passed) {
        throw new RuntimeException($message);
    }
}
DB::beginTransaction();
try {
    $catalog = DB::table('slots')->firstOrFail();
    $doctor = DB::table('practitioners')->insertGetId(['name_zh' => (string) Str::uuid(), 'name_en' => 'QA']);
    $slots = [];
    foreach ([1, 2, 3, -1, 16] as $offset) {
        $slots[] = DB::table('slots')->insertGetId([
            'practitioner_id' => $doctor, 'service_id' => $catalog->service_id, 'branch_id' => $catalog->branch_id,
            'starts_at' => today('Asia/Hong_Kong')->addDays($offset)->setHour(10),
        ]);
    }
    [$old, $target, $occupied, $past, $outside] = $slots;
    $patient = Patient::create(['identity_key' => hash('sha256', Str::uuid()), 'name' => 'QA', 'phone' => '+85291234567']);
    $booking = $patient->appointments()->create(['slot_id' => $old, 'reference' => (string) Str::uuid()]);
    $other = $patient->appointments()->create(['slot_id' => $occupied, 'reference' => (string) Str::uuid()]);
    $move = new RescheduleAppointment();
    foreach ([$occupied, $past, $outside] as $invalid) {
        check(!$move->move($booking, $old, $invalid), 'Invalid destination accepted');
        check($booking->fresh()->slot_id === $old, 'Original slot lost');
    }
    check($move->move($booking, $old, $target), 'Available destination rejected');
    check($move->move($booking, $old, $target), 'Retry failed');
    check(!$move->move($booking, $old, $old), 'Stale form changed the booking');
    check(!Appointment::whereSlotId($old)->exists(), 'Original slot still occupied');
    check($patient->appointments()->whereReference($booking->reference)->sole()->slot_id === $target, 'CRM history mismatch');
    check($other->fresh()->slot_id === $occupied, 'Unrelated booking changed');
    echo "PASS atomic reschedule, occupied and out-of-window slots, original slot release, retry, stale form and CRM reference\n";
} finally {
    DB::rollBack();
}
