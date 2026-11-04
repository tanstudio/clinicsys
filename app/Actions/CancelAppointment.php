<?php

namespace App\Actions;

use App\Models\Appointment;
use App\Models\Slot;
use Illuminate\Support\Facades\DB;

class CancelAppointment
{
    public function cancel(Appointment $appointment, int $expectedSlot, ?int $branch = null): bool
    {
        return DB::transaction(function () use ($appointment, $expectedSlot, $branch) {
            $booking = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $slot = Slot::findOrFail($booking->slot_id);
            if ($booking->slot_id !== $expectedSlot || ($branch !== null && $slot->branch_id !== $branch)) {
                return false;
            }
            if ($booking->cancelled_at) {
                return true;
            }
            if ($booking->hasEncounter() || $slot->starts_at <= now('Asia/Hong_Kong')->toDateTimeString()) {
                return false;
            }
            $booking->update(['cancelled_at' => now()]);
            $booking->recordEvent('cancelled', $slot->id, null);

            return true;
        }, 3);
    }
}
