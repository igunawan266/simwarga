<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_cards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('head_of_family_id');
            $table->string('no_kk')->unique();
            $table->string('alamat_jalan')->nullable();
            $table->string('nomor_rumah')->nullable();
            $table->unsignedBigInteger('rt_id');
            $table->unsignedBigInteger('rw_id');
            $table->date('tanggal_cetak')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('head_of_family_id')->references('id')->on('warga_profiles')->onDelete('cascade');
            $table->foreign('rt_id')->references('id')->on('rt_structures')->onDelete('restrict');
            $table->foreign('rw_id')->references('id')->on('rw_structures')->onDelete('restrict');

            $table->index('head_of_family_id');
            $table->index('rt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_cards');
    }
};
