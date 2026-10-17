<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescription_items', fn (Blueprint $t) => $t->unsignedInteger('dispensed_quantity')->default(0));
        // Every item saved by earlier versions represented medicine dispensed at the visit.
        DB::table('prescription_items')->update(['dispensed_quantity' => DB::raw('quantity')]);
        Schema::table('payments', function (Blueprint $t) {
            $t->uuid('request_key')->nullable();
            $t->unique(['invoice_id', 'request_key']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->dropUnique(['invoice_id', 'request_key']);
            $t->dropColumn('request_key');
        });
        Schema::table('prescription_items', fn (Blueprint $t) => $t->dropColumn('dispensed_quantity'));
    }
};
