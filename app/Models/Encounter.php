<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Encounter extends Model
{
    protected $fillable = ['appointment_id', 'patient_id', 'branch_id', 'recorded_by', 'clinical_record'];

    protected $casts = ['clinical_record' => 'encrypted:array'];
}
