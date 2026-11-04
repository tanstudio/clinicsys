<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('staff_accounts', fn (Blueprint $t) => $t->unsignedInteger('session_version')->default(0));
    }
    public function down(): void
    {
        Schema::table('staff_accounts', fn (Blueprint $t) => $t->dropColumn('session_version'));
    }
};
