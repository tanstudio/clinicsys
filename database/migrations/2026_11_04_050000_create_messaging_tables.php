<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('message_threads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->constrained();
            $t->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $t->char('contact_key', 64)->index();
            $t->char('thread_key', 64)->unique();
            $t->text('phone');
            $t->string('category')->default('general');
            $t->string('status')->default('open');
            $t->unsignedBigInteger('assignee_id')->nullable();
            $t->timestamps();
        });
        Schema::create('message_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('thread_id')->constrained('message_threads')->cascadeOnDelete();
            $t->string('event_key')->unique();
            $t->string('direction');
            $t->string('channel');
            $t->string('kind');
            $t->text('body');
            $t->text('recipient')->nullable();
            $t->string('status')->index();
            $t->unsignedInteger('attempts')->default(0);
            $t->dateTime('due_at')->index();
            $t->dateTime('claimed_at')->nullable();
            $t->unsignedBigInteger('appointment_event_id')->nullable();
            $t->unsignedBigInteger('appointment_id')->nullable();
            $t->string('error')->nullable();
            $t->timestamps();
        });
        Schema::create('message_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->constrained();
            $t->string('keyword', 100);
            $t->string('category');
            $t->text('reply')->nullable();
            $t->unsignedBigInteger('assignee_id')->nullable();
            $t->unique(['branch_id','keyword']);
        });
        Schema::create('message_consents', function (Blueprint $t) {
            $t->char('contact_key', 64)->primary();
            $t->boolean('marketing');
            $t->timestamp('updated_at');
        });
        Schema::create('message_projections', function (Blueprint $t) {
            $t->string('source', 32);
            $t->unsignedBigInteger('source_id');
            $t->primary(['source', 'source_id']);
        });
    }

    public function down(): void
    {
        foreach (['message_projections','message_consents','message_rules','message_entries','message_threads'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
