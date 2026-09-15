<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rw_structures', function (Blueprint $table) {
            $table->id();
            $table->integer('rw_number')->unique();
            $table->string('name')->nullable();
            $table->string('kelurahan')->nullable();
            $table->string('kecamatan')->nullable();
            $table->string('kota')->nullable();
            $table->string('provinsi')->nullable();
            $table->unsignedBigInteger('ketua_rw_user_id')->nullable();
            $table->integer('total_rt_units')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('ketua_rw_user_id')->references('id')->on('users')->onDelete('setNull');
            $table->index('rw_number');
            $table->index('ketua_rw_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rw_structures');
    }
};
