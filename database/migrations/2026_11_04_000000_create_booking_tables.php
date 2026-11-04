<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['branches', 'services', 'practitioners'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('name_zh')->unique();
                $t->string('name_en');
            });
        }
        Schema::create('slots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->constrained();
            $t->foreignId('service_id')->constrained();
            $t->foreignId('practitioner_id')->constrained();
            $t->dateTime('starts_at');
            $t->unique(['practitioner_id', 'starts_at']);
        });
        Schema::create('patients', function (Blueprint $t) {
            $t->id();
            $t->char('identity_key', 64)->unique();
            $t->text('name');
            $t->text('phone');
        });
        Schema::create('appointments', function (Blueprint $t) {
            $t->id();
            $t->uuid('reference')->unique();
            $t->foreignId('slot_id')->unique()->constrained();
            $t->foreignId('patient_id')->constrained();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['appointments','patients','slots','practitioners','services','branches'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
