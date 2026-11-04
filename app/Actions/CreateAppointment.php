<?php

namespace App\Actions;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Slot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateAppointment
{
    public const RULES = [
        'slot_id' => ['required', 'integer'],
        'name' => ['required', 'string', 'max:100'],
        'phone' => ['required', 'regex:/^[2-9][0-9]{7}$/D'],
        'whatsapp_opt_in' => ['sometimes', 'boolean'],
        'email' => ['nullable', 'email', 'max:254'],
    ];

    public function book(array $data, ?string $browserKey = null, ?int $branch = null): ?Appointment
    {
        return DB::transaction(function () use ($data, $browserKey, $branch) {
            $slot = Slot::lockAvailable((int) $data['slot_id']);
            if (!$slot || ($branch !== null && $slot->branch_id !== $branch)) {
                return null;
            }
            $name = Str::squish($data['name']);
            $key = hash_hmac('sha256', Str::lower($name).'|'.$data['phone'], config('app.key'));
            $patient = Patient::firstOrCreate(['identity_key' => $key], ['name' => $name, 'phone' => '+852'.$data['phone']]);
            $appointment = $patient->appointments()->create([
                'slot_id' => $slot->id, 'reference' => (string) Str::uuid(),
                'email' => $data['email'] ?? null, 'browser_key' => $browserKey,
                'whatsapp_opt_in' => $data['whatsapp_opt_in'] ?? false,
            ]);
            $appointment->recordEvent('confirmed', null, $slot->id);

            return $appointment;
        }, 3);
    }
}
