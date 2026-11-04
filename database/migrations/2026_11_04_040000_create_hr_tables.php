<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_profiles', function (Blueprint $t) {
            $t->foreignId('staff_id')->primary()->constrained('staff_accounts');
            $t->text('details');
        });
        Schema::create('hr_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('staff_id')->constrained('staff_accounts');
            $t->string('kind', 24);
            $t->date('effective_on');
            $t->text('details');
            $t->foreignId('author_id')->constrained('staff_accounts');
        });
        Schema::create('hr_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('staff_id')->constrained('staff_accounts');
            $t->string('kind', 24);
            $t->text('filename');
            $t->mediumText('contents');
            $t->timestamp('uploaded_at');
        });
        Schema::create('hr_shifts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('staff_id')->constrained('staff_accounts');
            $t->string('kind', 12);
            $t->dateTime('starts_at');
            $t->dateTime('latest_start');
            $t->unsignedSmallInteger('required_minutes');
            $t->dateTime('clock_in')->nullable();
            $t->dateTime('clock_out')->nullable();
            $t->index(['staff_id', 'starts_at']);
        });
        Schema::create('hr_leave', function (Blueprint $t) {
            $t->id();
            $t->foreignId('staff_id')->constrained('staff_accounts');
            $t->string('kind', 16);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->text('reason');
            $t->string('status', 12)->default('pending');
            $t->foreignId('reviewer_id')->nullable()->constrained('staff_accounts');
            $t->timestamp('reviewed_at')->nullable();
            $t->index(['staff_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        foreach (['hr_leave', 'hr_shifts', 'hr_documents', 'hr_history', 'hr_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
