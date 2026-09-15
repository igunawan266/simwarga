<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warga_profile_id');
            $table->unsignedBigInteger('rt_id');
            $table->unsignedBigInteger('rw_id');
            $table->string('letter_type');
            $table->text('purpose')->nullable();
            $table->string('status')->default('pending_rt');
            $table->string('letter_number')->nullable()->unique();
            $table->date('request_date');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();

            // RT Approval
            $table->timestamp('approved_by_rt_at')->nullable();
            $table->unsignedBigInteger('approved_by_rt_user_id')->nullable();
            $table->longText('rt_qr_token')->nullable();
            $table->string('rt_signature_hash')->nullable();

            // RW Approval
            $table->timestamp('approved_by_rw_at')->nullable();
            $table->unsignedBigInteger('approved_by_rw_user_id')->nullable();
            $table->longText('rw_qr_token')->nullable();
            $table->string('rw_signature_hash')->nullable();

            // Completion
            $table->timestamp('completed_at')->nullable();
            $table->string('pdf_path')->nullable();

            $table->timestamps();

            $table->foreign('warga_profile_id')->references('id')->on('warga_profiles')->onDelete('cascade');
            $table->foreign('rt_id')->references('id')->on('rt_structures')->onDelete('restrict');
            $table->foreign('rw_id')->references('id')->on('rw_structures')->onDelete('restrict');
            $table->foreign('approved_by_rt_user_id')->references('id')->on('users')->onDelete('setNull');
            $table->foreign('approved_by_rw_user_id')->references('id')->on('users')->onDelete('setNull');
            $table->foreign('rejected_by')->references('id')->on('users')->onDelete('setNull');

            $table->index('warga_profile_id');
            $table->index('rt_id');
            $table->index('status');
            $table->index('request_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_requests');
    }
};
