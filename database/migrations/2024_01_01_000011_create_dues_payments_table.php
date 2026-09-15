<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dues_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('billing_id');
            $table->unsignedBigInteger('warga_profile_id');
            $table->decimal('paid_amount', 15, 2);
            $table->date('payment_date');
            $table->string('payment_method')->nullable();
            $table->enum('status', ['pending', 'paid', 'partial', 'overdue'])->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('billing_id')->references('id')->on('dues_billings')->onDelete('cascade');
            $table->foreign('warga_profile_id')->references('id')->on('warga_profiles')->onDelete('cascade');

            $table->unique(['billing_id', 'warga_profile_id']);
            $table->index('warga_profile_id');
            $table->index('status');
            $table->index('payment_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dues_payments');
    }
};
