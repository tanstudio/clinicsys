<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('message_threads', function (Blueprint $t) {
            $t->boolean('automation_paused')->default(false);
        });
        Schema::table('message_entries', function (Blueprint $t) {
            $t->string('provider_message_id')->nullable()->unique();
            $t->uuid('provider_reference')->nullable()->unique();
            $t->dateTime('provider_received_at')->nullable();
            $t->text('template_data')->nullable();
        });
        Schema::create('whatsapp_settings', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->foreignId('default_branch_id')->nullable()->constrained('branches')->nullOnDelete();
        });
        Schema::create('whatsapp_routes', function (Blueprint $t) {
            $t->char('contact_key',64)->primary();
            $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $t->dateTime('last_received_at')->nullable();
        });
        Schema::create('whatsapp_templates', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 32)->unique();
            $t->string('name');
            $t->string('language', 20);
            $t->json('parameters');
            $t->boolean('approved')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
        Schema::dropIfExists('whatsapp_routes');
        Schema::dropIfExists('whatsapp_settings');
        Schema::table('message_entries', function (Blueprint $t) {
            $t->dropUnique(['provider_message_id']);
            $t->dropUnique(['provider_reference']);
            $t->dropColumn(['provider_message_id','provider_reference','provider_received_at','template_data']);
        });
        Schema::table('message_threads', fn (Blueprint $t) => $t->dropColumn('automation_paused'));
    }
};
