<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Appointment extends Model
{
    protected $fillable = ['reference', 'slot_id', 'patient_id', 'email', 'browser_key', 'cancelled_at', 'whatsapp_opt_in'];
    protected $hidden = ['browser_key', 'email'];
    protected $casts = ['email' => 'encrypted', 'whatsapp_opt_in' => 'boolean'];

    public function hasEncounter(): bool
    {
        return DB::table('encounters')->where('appointment_id', $this->id)->exists();
    }

    public function recordEvent(string $event, ?int $from, ?int $to): void
    {
        DB::table('appointment_events')->insert([
            'appointment_id' => $this->id, 'event' => $event,
            'from_slot_id' => $from, 'to_slot_id' => $to, 'created_at' => now(),
        ]);
    }
}
