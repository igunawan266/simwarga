<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warga_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('nik')->unique();
            $table->string('nama_lengkap');
            $table->string('tempat_lahir')->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->enum('jenis_kelamin', ['L', 'P'])->nullable();
            $table->string('alamat_jalan')->nullable();
            $table->string('nomor_rumah')->nullable();
            $table->unsignedBigInteger('rt_id');
            $table->unsignedBigInteger('rw_id');
            $table->string('phone')->nullable();
            $table->string('status_dalam_keluarga')->default('Kepala Keluarga');
            $table->boolean('is_head_of_family')->default(true);
            $table->string('no_kk')->nullable();
            $table->string('status_perkawinan')->nullable();
            $table->string('pekerjaan')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('rt_id')->references('id')->on('rt_structures')->onDelete('restrict');
            $table->foreign('rw_id')->references('id')->on('rw_structures')->onDelete('restrict');

            $table->index('rt_id');
            $table->index('rw_id');
            $table->index('is_head_of_family');
            $table->index('nik');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warga_profiles');
    }
};
