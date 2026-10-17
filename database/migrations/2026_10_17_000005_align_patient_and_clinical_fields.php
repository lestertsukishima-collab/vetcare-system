<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }
        Schema::table('pets', function (Blueprint $table) {
            $table->decimal('weight', 8, 2)->nullable();
            $table->string('color', 100)->nullable();
        });
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('appointments', fn (Blueprint $table) => $table->dropIndex('appointments_veterinarian_id_scheduled_at_index'));
        }
        Schema::table('appointments', fn (Blueprint $table) => $table->renameColumn('scheduled_at', 'appointment_date'));
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('appointments', fn (Blueprint $table) => $table->index(['veterinarian_id', 'appointment_date']));
        } else {
            // Keep the index in place while it may support the veterinarian foreign key.
            Schema::table('appointments', fn (Blueprint $table) => $table->renameIndex('appointments_veterinarian_id_scheduled_at_index', 'appointments_veterinarian_id_appointment_date_index'));
        }
        Schema::table('medical_records', function (Blueprint $table) {
            $table->renameColumn('examination', 'symptoms');
            $table->unsignedBigInteger('pet_id')->nullable();
            $table->unsignedBigInteger('veterinarian_id')->nullable();
            $table->text('notes')->nullable();
        });
        foreach (DB::table('medical_records')->join('appointments', 'appointments.id', '=', 'medical_records.appointment_id')
            ->select('medical_records.id', 'appointments.pet_id', 'appointments.veterinarian_id')->get() as $record) {
            DB::table('medical_records')->where('id', $record->id)->update(['pet_id' => $record->pet_id, 'veterinarian_id' => $record->veterinarian_id]);
        }
        Schema::table('medical_records', function (Blueprint $table) {
            $table->unsignedBigInteger('pet_id')->nullable(false)->change();
            $table->unsignedBigInteger('veterinarian_id')->nullable(false)->change();
            $table->foreign('pet_id')->references('id')->on('pets');
            $table->foreign('veterinarian_id')->references('id')->on('veterinarians');
        });
        Schema::table('vaccinations', function (Blueprint $table) {
            $table->renameColumn('vaccine', 'vaccine_name');
            $table->renameColumn('administered_at', 'date_given');
            $table->renameColumn('next_due_at', 'next_due_date');
            $table->unsignedBigInteger('pet_id')->nullable();
            $table->unsignedBigInteger('veterinarian_id')->nullable();
        });
        foreach (DB::table('vaccinations')->join('medical_records', 'medical_records.id', '=', 'vaccinations.medical_record_id')
            ->select('vaccinations.id', 'medical_records.pet_id', 'medical_records.veterinarian_id')->get() as $vaccination) {
            DB::table('vaccinations')->where('id', $vaccination->id)->update(['pet_id' => $vaccination->pet_id, 'veterinarian_id' => $vaccination->veterinarian_id]);
        }
        Schema::table('vaccinations', function (Blueprint $table) {
            $table->unsignedBigInteger('pet_id')->nullable(false)->change();
            $table->unsignedBigInteger('veterinarian_id')->nullable(false)->change();
            $table->foreign('pet_id')->references('id')->on('pets');
            $table->foreign('veterinarian_id')->references('id')->on('veterinarians');
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }
        Schema::table('vaccinations', function (Blueprint $table) {
            $table->dropForeign(['pet_id']);
            $table->dropForeign(['veterinarian_id']);
        });
        Schema::table('vaccinations', function (Blueprint $table) {
            $table->dropColumn(['pet_id', 'veterinarian_id']);
            $table->renameColumn('vaccine_name', 'vaccine');
            $table->renameColumn('date_given', 'administered_at');
            $table->renameColumn('next_due_date', 'next_due_at');
        });
        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropForeign(['pet_id']);
            $table->dropForeign(['veterinarian_id']);
        });
        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropColumn(['pet_id', 'veterinarian_id', 'notes']);
            $table->renameColumn('symptoms', 'examination');
        });
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('appointments', fn (Blueprint $table) => $table->dropIndex('appointments_veterinarian_id_appointment_date_index'));
        } else {
            Schema::table('appointments', fn (Blueprint $table) => $table->renameIndex('appointments_veterinarian_id_appointment_date_index', 'appointments_veterinarian_id_scheduled_at_index'));
        }
        Schema::table('appointments', fn (Blueprint $table) => $table->renameColumn('appointment_date', 'scheduled_at'));
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('appointments', fn (Blueprint $table) => $table->index(['veterinarian_id', 'scheduled_at']));
        }
        Schema::table('pets', fn (Blueprint $table) => $table->dropColumn(['weight', 'color']));
    }
};
