<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rt_id')->nullable();
            $table->unsignedBigInteger('rw_id')->nullable();
            $table->integer('last_sequence_number')->default(0);
            $table->integer('last_sequence_month')->nullable();
            $table->integer('last_sequence_year')->nullable();
            $table->timestamps();

            $table->foreign('rt_id')->references('id')->on('rt_structures')->onDelete('cascade');
            $table->foreign('rw_id')->references('id')->on('rw_structures')->onDelete('cascade');

            $table->unique(['rt_id', 'rw_id']);
            $table->index('rt_id');
            $table->index('rw_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_sequences');
    }
};
