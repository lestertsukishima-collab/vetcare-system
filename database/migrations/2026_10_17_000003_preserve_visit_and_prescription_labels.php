<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', fn (Blueprint $table) => $table->string('service_name')->nullable());
        Schema::table('prescription_items', function (Blueprint $table) {
            $table->string('medicine_name')->nullable();
            $table->string('medicine_unit', 40)->nullable();
        });
        foreach (DB::table('appointments')->join('services', 'services.id', '=', 'appointments.service_id')->select('appointments.id', 'services.name')->get() as $appointment) {
            DB::table('appointments')->where('id', $appointment->id)->update(['service_name' => $appointment->name]);
        }
        foreach (DB::table('prescription_items')->join('medicines', 'medicines.id', '=', 'prescription_items.medicine_id')->select('prescription_items.id', 'medicines.name', 'medicines.unit')->get() as $item) {
            DB::table('prescription_items')->where('id', $item->id)->update(['medicine_name' => $item->name, 'medicine_unit' => $item->unit]);
        }
    }

    public function down(): void
    {
        Schema::table('prescription_items', fn (Blueprint $table) => $table->dropColumn(['medicine_name', 'medicine_unit']));
        Schema::table('appointments', fn (Blueprint $table) => $table->dropColumn('service_name'));
    }
};
