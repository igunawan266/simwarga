<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_sequence_audit', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('letter_request_id');
            $table->unsignedBigInteger('rt_id');
            $table->unsignedBigInteger('rw_id');
            $table->string('generated_letter_number');
            $table->integer('sequence_number');
            $table->unsignedBigInteger('generated_by');
            $table->timestamp('generated_at')->useCurrent();

            $table->foreign('letter_request_id')->references('id')->on('letter_requests')->onDelete('cascade');
            $table->foreign('rt_id')->references('id')->on('rt_structures')->onDelete('restrict');
            $table->foreign('rw_id')->references('id')->on('rw_structures')->onDelete('restrict');
            $table->foreign('generated_by')->references('id')->on('users')->onDelete('restrict');

            $table->index('letter_request_id');
            $table->index('generated_letter_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_sequence_audit');
    }
};
