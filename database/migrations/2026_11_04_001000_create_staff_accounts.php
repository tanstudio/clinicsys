<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('staff_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('role');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('staff_access_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff_accounts');
            $table->foreignId('branch_id')->constrained();
            $table->string('method', 12);
            $table->string('route');
            $table->unsignedSmallInteger('status');
            $table->timestamp('created_at');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('staff_access_log');
        Schema::dropIfExists('staff_accounts');
    }
};
