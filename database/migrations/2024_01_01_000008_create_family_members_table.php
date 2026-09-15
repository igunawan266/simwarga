<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('family_card_id');
            $table->unsignedBigInteger('warga_profile_id')->nullable();
            $table->string('nik')->unique()->nullable();
            $table->string('nama_lengkap');
            $table->string('hubungan_keluarga')->nullable();
            $table->enum('jenis_kelamin', ['L', 'P'])->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->string('tempat_lahir')->nullable();
            $table->string('status_perkawinan')->nullable();
            $table->string('pekerjaan')->nullable();
            $table->string('pendidikan')->nullable();
            $table->string('status_dalam_keluarga')->nullable();
            $table->timestamps();

            $table->foreign('family_card_id')->references('id')->on('family_cards')->onDelete('cascade');
            $table->foreign('warga_profile_id')->references('id')->on('warga_profiles')->onDelete('setNull');

            $table->index('family_card_id');
            $table->index('hubungan_keluarga');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_members');
    }
};
