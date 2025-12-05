<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('name');
            $table->string('category'); // Baju, Perabotan, Elektronik, Kulia, Sepatu
            $table->string('condition'); // Baru, Bekas, Sangat Baik, Baik, Cukup
            $table->text('description')->nullable();
            $table->string('location');
            $table->decimal('price', 15, 2);
            $table->string('whatsapp_number');
            $table->string('image')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

