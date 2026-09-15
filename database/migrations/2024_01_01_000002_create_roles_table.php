<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('display_name')->nullable();
            $table->timestamps();
        });

        // Insert default roles
        \DB::table('roles')->insert([
            ['name' => 'warga', 'display_name' => 'Warga', 'created_at' => now()],
            ['name' => 'admin', 'display_name' => 'System Administrator', 'created_at' => now()],
            ['name' => 'rw', 'display_name' => 'Ketua RW', 'created_at' => now()],
            ['name' => 'ketua_rt', 'display_name' => 'Ketua RT', 'created_at' => now()],
            ['name' => 'sekretaris_rt', 'display_name' => 'Sekretaris RT', 'created_at' => now()],
            ['name' => 'bendahara_rt', 'display_name' => 'Bendahara RT', 'created_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
