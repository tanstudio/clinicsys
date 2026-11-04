<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('slots', fn (Blueprint $t) => $t->timestamp('closed_at')->nullable());
        Schema::table('appointments', function (Blueprint $t) {
            $t->timestamp('cancelled_at')->nullable();
            $t->text('email')->nullable();
            $t->char('browser_key', 64)->nullable()->index();
            $t->index('slot_id', 'appointments_slot_lookup');
        });
        Schema::table('appointments', function (Blueprint $t) {
            $t->unsignedBigInteger('active_slot_id')
                ->virtualAs('CASE WHEN cancelled_at IS NULL THEN slot_id ELSE NULL END')->unique();
        });
        Schema::table('appointments', fn (Blueprint $t) => $t->dropUnique(['slot_id']));
        Schema::create('appointment_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $t->string('event', 24);
            $t->foreignId('from_slot_id')->nullable()->constrained('slots');
            $t->foreignId('to_slot_id')->nullable()->constrained('slots');
            $t->timestamp('created_at');
        });
        DB::table('appointment_events')->insertUsing(
            ['appointment_id','event','to_slot_id','created_at'],
            DB::table('appointments')->selectRaw("id, 'confirmed', slot_id, COALESCE(created_at, CURRENT_TIMESTAMP)")
        );
    }

    public function down(): void
    {
        // Restoring the old unique index can fail if a cancelled slot has been rebooked.
        Schema::table('appointments', fn (Blueprint $t) => $t->unique('slot_id'));
        Schema::dropIfExists('appointment_events');
        Schema::table('appointments', function (Blueprint $t) {
            $t->dropUnique(['active_slot_id']);
            $t->dropIndex(['browser_key']);
            $t->dropIndex('appointments_slot_lookup');
        });
        Schema::table('appointments', fn (Blueprint $t) => $t->dropColumn(['active_slot_id','email','browser_key']));
        Schema::table('appointments', fn (Blueprint $t) => $t->dropColumn('cancelled_at'));
        Schema::table('slots', fn (Blueprint $t) => $t->dropColumn('closed_at'));
    }
};
