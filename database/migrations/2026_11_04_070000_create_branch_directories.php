<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['practitioners'=>'practitioner_id', 'services'=>'service_id'] as $table=>$key) {
            Schema::create('branch_'.$table, function (Blueprint $t) use ($table,$key) {
                $t->foreignId('branch_id')->constrained();
                $t->foreignId($key)->constrained($table);
                $t->boolean('active')->default(true);
                $t->primary(['branch_id',$key]);
            });
            DB::table('branch_'.$table)->insertUsing(['branch_id',$key],DB::table('slots')->distinct()->select('branch_id',$key));
            Schema::table($table, fn (Blueprint $t) => $t->dropUnique(['name_zh']));
        }
    }

    public function down(): void
    {
        foreach (['services','practitioners'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->unique('name_zh'));
            Schema::drop('branch_'.$table);
        }
    }
};
