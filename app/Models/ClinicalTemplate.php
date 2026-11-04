<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicalTemplate extends Model
{
    protected $fillable = ['branch_id', 'created_by', 'name', 'clinical_record'];

    protected $casts = ['name' => 'encrypted', 'clinical_record' => 'encrypted:array'];
}
