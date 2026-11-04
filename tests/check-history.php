<?php

use App\Models\Appointment;
use App\Models\Patient;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('local', 'testing') || !str_starts_with($argv[1], 'QA-')) {
    exit(1);
}
$appointment = Appointment::whereReference($argv[2])->firstOrFail();
$patient = Patient::findOrFail($appointment->patient_id);
if ($patient->name !== $argv[1]) {
    exit(1);
}
echo json_encode(['count' => $patient->appointments()->count(), 'name' => $patient->name,
    'phone' => $patient->phone, 'slot' => $appointment->slot_id]);
Illuminate\Support\Facades\DB::transaction(function () use ($appointment, $patient) {
    $appointment->delete();
    if (!$patient->appointments()->exists()) {
        $patient->delete();
    }
});
