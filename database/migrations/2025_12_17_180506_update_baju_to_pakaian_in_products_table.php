<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        // Normalize category name: "Baju" -> "Pakaian"
        DB::table('products')
            ->where('category', 'Baju')
            ->update(['category' => 'Pakaian']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        // Rollback normalization if needed
        DB::table('products')
            ->where('category', 'Pakaian')
            ->update(['category' => 'Baju']);
    }
};
