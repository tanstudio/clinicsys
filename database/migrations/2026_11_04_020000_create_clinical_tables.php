<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('encounters', function (Blueprint $t) {
            $t->id();
            $t->foreignId('appointment_id')->unique()->constrained();
            $t->foreignId('branch_id')->constrained();
            $t->foreignId('patient_id')->constrained();
            $t->unsignedBigInteger('recorded_by');
            $t->longText('clinical_record');
            $t->timestamps();
            $t->index(['branch_id', 'patient_id']);
        });
        Schema::create('clinical_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->constrained();
            $t->unsignedBigInteger('created_by');
            $t->text('name');
            $t->longText('clinical_record');
            $t->timestamps();
        });
        Schema::create('crm_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->constrained();
            $t->foreignId('patient_id')->constrained();
            $t->text('tags');
            $t->text('segment')->nullable();
            $t->unique(['branch_id','patient_id']);
        });
        Schema::create('patient_followups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->constrained();
            $t->foreignId('patient_id')->constrained();
            $t->date('due_on');
            $t->text('note');
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['patient_followups','crm_profiles','clinical_templates','encounters'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
