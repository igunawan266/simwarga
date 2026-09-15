<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dues_billings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rt_id');
            $table->unsignedBigInteger('rw_id');
            $table->integer('billing_month');
            $table->integer('billing_year');
            $table->decimal('amount_per_house', 15, 2);
            $table->date('due_date')->nullable();
            $table->enum('status', ['draft', 'active', 'closed'])->default('draft');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('rt_id')->references('id')->on('rt_structures')->onDelete('cascade');
            $table->foreign('rw_id')->references('id')->on('rw_structures')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('restrict');

            $table->unique(['rt_id', 'billing_month', 'billing_year']);
            $table->index('rt_id');
            $table->index('status');
            $table->index(['billing_month', 'billing_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dues_billings');
    }
};
