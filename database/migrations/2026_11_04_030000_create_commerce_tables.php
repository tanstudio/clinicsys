<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('herbal_stocks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->constrained();
            $t->string('herb', 100);
            $t->unsignedBigInteger('milligrams')->default(0);
            $t->unique(['branch_id', 'herb']);
        });
        Schema::create('herbal_movements', function (Blueprint $t) {
            $t->id();
            $t->uuid('reference')->unique();
            $t->foreignId('stock_id')->constrained('herbal_stocks');
            $t->foreignId('encounter_id')->nullable()->constrained();
            $t->string('kind', 12);
            $t->unsignedBigInteger('milligrams');
            $t->string('note', 160)->nullable();
            $t->dateTime('created_at')->index();
        });
        Schema::create('receipts', function (Blueprint $t) {
            $t->id();
            $t->uuid('reference')->unique();
            $t->foreignId('branch_id')->constrained();
            $t->foreignId('patient_id')->constrained();
            $t->foreignId('appointment_id')->unique()->constrained();
            $t->foreignId('encounter_id')->unique()->constrained();
            $t->string('description', 160);
            $t->unsignedBigInteger('amount_cents');
            $t->string('method', 20);
            $t->dateTime('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('herbal_movements');
        Schema::dropIfExists('herbal_stocks');
    }
};
