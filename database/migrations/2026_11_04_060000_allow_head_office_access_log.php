<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('staff_access_log', fn (Blueprint $table) => $table->unsignedBigInteger('branch_id')->nullable()->change());
    }

    public function down(): void
    {
        // A rollback requires archiving any headquarters-only audit records first.
        Schema::table('staff_access_log', fn (Blueprint $table) => $table->unsignedBigInteger('branch_id')->nullable(false)->change());
    }
};
