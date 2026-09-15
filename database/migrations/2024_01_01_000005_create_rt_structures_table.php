<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rt_structures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rw_id');
            $table->integer('rt_number');
            $table->string('name')->nullable();
            $table->unsignedBigInteger('ketua_rt_user_id')->nullable();
            $table->unsignedBigInteger('sekretaris_rt_user_id')->nullable();
            $table->unsignedBigInteger('bendahara_rt_user_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('rw_id')->references('id')->on('rw_structures')->onDelete('cascade');
            $table->foreign('ketua_rt_user_id')->references('id')->on('users')->onDelete('setNull');
            $table->foreign('sekretaris_rt_user_id')->references('id')->on('users')->onDelete('setNull');
            $table->foreign('bendahara_rt_user_id')->references('id')->on('users')->onDelete('setNull');

            $table->unique(['rw_id', 'rt_number']);
            $table->index('rw_id');
            $table->index('ketua_rt_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rt_structures');
    }
};
