<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_transactions', function (Blueprint $table) {
            $table->id();
            $table->enum('scope', ['rt', 'rw']);
            $table->unsignedBigInteger('rt_id')->nullable();
            $table->unsignedBigInteger('rw_id');
            $table->enum('type', ['income', 'expense']);
            $table->string('category');
            $table->decimal('amount', 15, 2);
            $table->text('description')->nullable();
            $table->date('transaction_date');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('rt_id')->references('id')->on('rt_structures')->onDelete('cascade');
            $table->foreign('rw_id')->references('id')->on('rw_structures')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('restrict');

            $table->index('scope');
            $table->index('rt_id');
            $table->index('rw_id');
            $table->index('type');
            $table->index(['transaction_date', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_transactions');
    }
};
