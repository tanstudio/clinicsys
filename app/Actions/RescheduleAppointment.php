<?php

namespace App\Actions;

use App\Models\Appointment;
use App\Models\Slot;
use Illuminate\Support\Facades\DB;

class RescheduleAppointment
{
    /** Call after the caller has authorized access to this appointment. */
    public function move(Appointment $appointment, int $expectedSlot, int $newSlot, ?int $branch = null): bool
    {
        return DB::transaction(function () use ($appointment, $expectedSlot, $newSlot, $branch) {
            $booking = Appointment::whereKey($appointment->getKey())->lockForUpdate()->firstOrFail();
            $original = Slot::findOrFail($booking->slot_id);
            if ($booking->cancelled_at || ($branch !== null && $original->branch_id !== $branch)
                || $original->starts_at <= now('Asia/Hong_Kong')->toDateTimeString()) {
                return false;
            }
            if ($booking->slot_id === $newSlot) {
                return true;
            }
            if ($booking->hasEncounter() || $booking->slot_id !== $expectedSlot || !($destination = Slot::lockAvailable($newSlot))
                || ($branch !== null && $destination->branch_id !== $branch)) {
                return false;
            }
            $booking->update(['slot_id' => $newSlot]);
            $booking->recordEvent('rescheduled', $original->id, $newSlot);

            return true;
        }, 3);
    }
}
