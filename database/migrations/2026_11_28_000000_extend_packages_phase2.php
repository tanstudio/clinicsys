<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Phase 2: stored value, refunds, transfers, revenue attribution and balance notices.
// Every new rule is a column head office edits; defaults keep phase 1 packages working unchanged.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('package_plans', function (Blueprint $t) {
            $t->string('kind', 12)->default('sessions');
            $t->unsignedBigInteger('value_cents')->default(0);
            $t->unsignedBigInteger('bonus_value_cents')->default(0);
            $t->boolean('refundable')->default(true);
            $t->unsignedTinyInteger('refund_fee_percent')->default(0);
            $t->boolean('transferable')->default(false);
        });
        Schema::table('package_plans', fn (Blueprint $t) => $t->unsignedBigInteger('service_id')->nullable()->change());
        Schema::table('patient_packages', function (Blueprint $t) {
            $t->string('kind', 12)->default('sessions');
            $t->unsignedBigInteger('total_value_cents')->default(0);
            $t->boolean('refundable')->default(true);
            $t->unsignedTinyInteger('refund_fee_percent')->default(0);
            $t->boolean('transferable')->default(false);
            $t->string('status', 12)->default('active');
            $t->timestamp('closed_at')->nullable();
        });
        Schema::table('patient_packages', fn (Blueprint $t) => $t->unsignedBigInteger('service_id')->nullable()->change());
        Schema::table('package_ledger', function (Blueprint $t) {
            $t->bigInteger('value_cents')->default(0);
            $t->foreignId('related_package_id')->nullable()->constrained('patient_packages');
        });
        Schema::create('package_refunds', function (Blueprint $t) {
            $t->id();
            $t->uuid('reference')->unique();
            $t->foreignId('package_id')->unique()->constrained('patient_packages');
            $t->foreignId('branch_id')->constrained();
            $t->foreignId('patient_id')->constrained();
            $t->unsignedBigInteger('amount_cents');
            $t->unsignedBigInteger('fee_cents');
            $t->string('method', 20);
            $t->string('reason', 200);
            $t->unsignedBigInteger('staff_id');
            $t->timestamp('created_at')->index();
        });
        Schema::create('loyalty_settings', function (Blueprint $t) {
            $t->unsignedTinyInteger('id')->primary();
            // sale: package income belongs to the selling branch when sold; usage: to the branch that redeems it, when used.
            $t->string('revenue_basis', 12)->default('sale');
            $t->boolean('balance_notices')->default(true);
            $t->timestamp('updated_at')->nullable();
        });
        DB::table('loyalty_settings')->insert(['id' => 1, 'revenue_basis' => 'sale', 'balance_notices' => true, 'updated_at' => now()]);
        // Balance notices start with this release: ledger rows written before it are marked as already projected.
        DB::statement("INSERT IGNORE INTO message_projections (source, source_id) SELECT 'package_ledger', id FROM package_ledger");
    }

    public function down(): void
    {
        DB::table('message_projections')->where('source', 'package_ledger')->delete();
        Schema::dropIfExists('loyalty_settings');
        Schema::dropIfExists('package_refunds');
        Schema::table('package_ledger', function (Blueprint $t) {
            $t->dropConstrainedForeignId('related_package_id');
            $t->dropColumn('value_cents');
        });
        Schema::table('patient_packages', fn (Blueprint $t) => $t->dropColumn(['kind', 'total_value_cents', 'refundable', 'refund_fee_percent', 'transferable', 'status', 'closed_at']));
        Schema::table('package_plans', fn (Blueprint $t) => $t->dropColumn(['kind', 'value_cents', 'bonus_value_cents', 'refundable', 'refund_fee_percent', 'transferable']));
    }
};
