<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Patient extends Model
{
    public $timestamps = false;
    protected $fillable = ['identity_key', 'name', 'phone'];
    protected $hidden = ['identity_key'];
    protected $casts = ['name' => 'encrypted', 'phone' => 'encrypted'];

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}
