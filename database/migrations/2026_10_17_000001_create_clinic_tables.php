<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('owner');
        });
        Schema::create('pets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users');
            $table->string('name');
            $table->string('species');
            $table->string('breed')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('sex');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('medicines', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('reorder_level')->default(10);
            $table->date('expires_at');
            $table->timestamps();
        });
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained();
            $table->foreignId('veterinarian_id')->constrained('users');
            $table->foreignId('service_id')->constrained();
            $table->dateTime('scheduled_at');
            $table->string('status')->default('pending');
            $table->text('reason');
            $table->timestamps();
            $table->index(['veterinarian_id', 'scheduled_at']);
        });
        Schema::create('medical_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->unique()->constrained();
            $table->text('examination');
            $table->text('diagnosis');
            $table->text('treatment');
            $table->text('prescription')->nullable();
            $table->string('vaccine')->nullable();
            $table->date('next_vaccination_at')->nullable();
            $table->timestamps();
        });
        Schema::create('clinic_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['clinic_settings', 'medical_records', 'appointments', 'medicines', 'services', 'pets'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('role'));
    }
};
