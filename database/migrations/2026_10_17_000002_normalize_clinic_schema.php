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
            // SQLite rebuilds tables when changing foreign keys. Check references
            // at commit after both referenced tables have been rebuilt.
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }
        Schema::table('users', fn (Blueprint $t) => $t->boolean('active')->default(true));
        Schema::create('owners', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained();
            $t->string('phone', 50)->nullable();
            $t->text('address')->nullable();
            $t->timestamps();
        });
        Schema::create('veterinarians', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained();
            $t->string('license_number')->nullable()->unique();
            $t->string('specialization')->nullable();
            $t->timestamps();
        });
        // Preserve legacy IDs so existing pets and appointments keep their relationships.
        foreach (DB::table('users')->whereIn('role', ['owner', 'veterinarian'])->get() as $user) {
            DB::table($user->role === 'owner' ? 'owners' : 'veterinarians')->insert([
                'id' => $user->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        Schema::table('pets', function (Blueprint $t) {
            $t->dropForeign(['owner_id']);
            $t->foreign('owner_id')->references('id')->on('owners');
        });
        Schema::table('appointments', function (Blueprint $t) {
            $t->dropForeign(['veterinarian_id']);
            $t->foreign('veterinarian_id')->references('id')->on('veterinarians');
            $t->decimal('service_fee', 10, 2)->default(0);
        });
        foreach (DB::table('appointments')->join('services', 'services.id', '=', 'appointments.service_id')->select('appointments.id', 'services.price')->get() as $a) {
            DB::table('appointments')->where('id', $a->id)->update(['service_fee' => $a->price]);
        }
        Schema::table('services', fn (Blueprint $t) => $t->boolean('active')->default(true));
        Schema::table('medicines', function (Blueprint $t) {
            $t->string('unit', 40)->default('unit');
            $t->decimal('unit_price', 10, 2)->default(0);
            $t->boolean('active')->default(true);
        });
        Schema::create('prescriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('medical_record_id')->unique()->constrained();
            $t->text('instructions')->nullable();
            $t->timestamps();
        });
        Schema::create('prescription_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('prescription_id')->constrained();
            $t->foreignId('medicine_id')->constrained();
            $t->unsignedInteger('quantity');
            $t->string('dosage');
            $t->decimal('unit_price', 10, 2);
            $t->timestamps();
            $t->unique(['prescription_id', 'medicine_id']);
        });
        Schema::create('vaccinations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('medical_record_id')->constrained();
            $t->string('vaccine');
            $t->date('administered_at');
            $t->date('next_due_at')->nullable();
            $t->string('batch_number')->nullable();
            $t->timestamps();
        });
        foreach (DB::table('medical_records')->get() as $record) {
            if ($record->prescription) {
                DB::table('prescriptions')->insert(['medical_record_id' => $record->id, 'instructions' => $record->prescription, 'created_at' => $record->created_at, 'updated_at' => $record->updated_at]);
            }
            if ($record->vaccine) {
                DB::table('vaccinations')->insert(['medical_record_id' => $record->id, 'vaccine' => $record->vaccine, 'administered_at' => substr($record->created_at ?? now()->toDateString(), 0, 10), 'next_due_at' => $record->next_vaccination_at, 'created_at' => $record->created_at, 'updated_at' => $record->updated_at]);
            }
        }
        Schema::table('medical_records', fn (Blueprint $t) => $t->renameColumn('prescription', 'legacy_prescription'));
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('appointment_id')->unique()->constrained();
            $t->decimal('service_amount', 10, 2);
            $t->decimal('medicine_amount', 10, 2)->default(0);
            $t->decimal('total', 10, 2);
            $t->date('due_date');
            $t->timestamps();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained();
            $t->foreignId('recorded_by')->constrained('users');
            $t->decimal('amount', 10, 2);
            $t->string('method', 30);
            $t->string('reference')->nullable();
            $t->dateTime('paid_at');
            $t->timestamps();
        });
        Schema::create('inventory_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('medicine_id')->constrained();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->foreignId('prescription_item_id')->nullable()->unique()->constrained();
            $t->integer('quantity_change');
            $t->unsignedInteger('balance_after');
            $t->string('type', 30);
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        foreach (DB::table('medicines')->get() as $medicine) {
            DB::table('inventory_transactions')->insert(['medicine_id' => $medicine->id, 'quantity_change' => $medicine->quantity, 'balance_after' => $medicine->quantity, 'type' => 'opening', 'notes' => 'Opening balance migrated from existing inventory.', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }
        Schema::table('medical_records', fn (Blueprint $t) => $t->renameColumn('legacy_prescription', 'prescription'));
        foreach (['inventory_transactions', 'payments', 'invoices', 'vaccinations', 'prescription_items', 'prescriptions'] as $table) {
            Schema::dropIfExists($table);
        }
        // Map profile IDs back before restoring the legacy foreign keys.
        $pets = DB::table('pets')->join('owners', 'owners.id', '=', 'pets.owner_id')->select('pets.id', 'owners.user_id')->get();
        $appointments = DB::table('appointments')->join('veterinarians', 'veterinarians.id', '=', 'appointments.veterinarian_id')->select('appointments.id', 'veterinarians.user_id')->get();
        Schema::table('pets', fn (Blueprint $t) => $t->dropForeign(['owner_id']));
        Schema::table('appointments', fn (Blueprint $t) => $t->dropForeign(['veterinarian_id']));
        foreach ($pets as $p) {
            DB::table('pets')->where('id', $p->id)->update(['owner_id' => $p->user_id]);
        }
        foreach ($appointments as $a) {
            DB::table('appointments')->where('id', $a->id)->update(['veterinarian_id' => $a->user_id]);
        }
        Schema::table('pets', fn (Blueprint $t) => $t->foreign('owner_id')->references('id')->on('users'));
        Schema::table('appointments', function (Blueprint $t) {
            $t->foreign('veterinarian_id')->references('id')->on('users');
            $t->dropColumn('service_fee');
        });
        Schema::dropIfExists('veterinarians');
        Schema::dropIfExists('owners');
        Schema::table('medicines', fn (Blueprint $t) => $t->dropColumn(['unit', 'unit_price', 'active']));
        Schema::table('services', fn (Blueprint $t) => $t->dropColumn('active'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('active'));
    }
};
