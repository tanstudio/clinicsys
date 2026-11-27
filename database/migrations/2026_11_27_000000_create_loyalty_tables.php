<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Customer tiers, session-pack plans and their ledger. Rules live here, edited in the staff portal.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_tiers', function (Blueprint $t) {
            $t->id();
            $t->string('name', 40);
            $t->unsignedBigInteger('threshold_cents')->default(0);
            $t->unsignedTinyInteger('window_months')->default(12);
            $t->unsignedTinyInteger('discount_percent')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('patient_tiers', function (Blueprint $t) {
            $t->foreignId('patient_id')->primary()->constrained();
            $t->foreignId('tier_id')->constrained('customer_tiers');
            $t->string('reason', 200);
            $t->unsignedBigInteger('set_by');
            $t->timestamp('set_at');
        });
        Schema::create('package_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name', 80);
            $t->foreignId('service_id')->constrained();
            $t->unsignedSmallInteger('sessions');
            $t->unsignedSmallInteger('bonus_sessions')->default(0);
            $t->unsignedBigInteger('price_cents');
            $t->unsignedSmallInteger('validity_days');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('package_plan_branches', function (Blueprint $t) {
            $t->foreignId('plan_id')->constrained('package_plans')->cascadeOnDelete();
            $t->foreignId('branch_id')->constrained();
            $t->primary(['plan_id', 'branch_id']);
        });
        // A sold package copies the plan's values, so a later rule change never alters it.
        Schema::create('patient_packages', function (Blueprint $t) {
            $t->id();
            $t->uuid('reference')->unique();
            $t->foreignId('patient_id')->constrained();
            $t->foreignId('plan_id')->constrained('package_plans');
            $t->foreignId('sold_branch_id')->constrained('branches');
            $t->foreignId('service_id')->constrained();
            $t->string('name', 80);
            $t->unsignedSmallInteger('total_sessions');
            $t->unsignedBigInteger('price_cents');
            $t->json('allowed_branches');
            $t->date('starts_on');
            $t->date('expires_on');
            $t->foreignId('receipt_id')->nullable()->unique()->constrained('receipts')->nullOnDelete();
            $t->unsignedBigInteger('sold_by');
            $t->timestamps();
            $t->index(['patient_id', 'expires_on']);
        });
        // The balance is the sum of the ledger; no stored counter can drift from it.
        Schema::create('package_ledger', function (Blueprint $t) {
            $t->id();
            $t->uuid('reference')->unique();
            $t->foreignId('package_id')->constrained('patient_packages');
            $t->string('kind', 12);
            $t->integer('sessions');
            $t->foreignId('encounter_id')->nullable()->constrained();
            $t->uuid('receipt_reference')->nullable();
            $t->foreignId('branch_id')->constrained();
            $t->unsignedBigInteger('staff_id');
            $t->string('reason', 200)->nullable();
            $t->foreignId('voids_id')->nullable()->unique()->constrained('package_ledger');
            $t->timestamp('created_at');
            $t->index(['package_id', 'kind']);
        });
        Schema::table('receipts', function (Blueprint $t) {
            // A package sale has no appointment or encounter.
            $t->unsignedBigInteger('appointment_id')->nullable()->change();
            $t->unsignedBigInteger('encounter_id')->nullable()->change();
            $t->string('kind', 12)->default('visit');
            $t->string('tier_name', 40)->nullable();
            $t->unsignedTinyInteger('discount_percent')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('receipts', fn (Blueprint $t) => $t->dropColumn(['kind', 'tier_name', 'discount_percent']));
        foreach (['package_ledger', 'patient_packages', 'package_plan_branches', 'package_plans', 'patient_tiers', 'customer_tiers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
