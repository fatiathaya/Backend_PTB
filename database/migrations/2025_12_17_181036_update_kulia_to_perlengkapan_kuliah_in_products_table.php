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

        // Normalize category name: "Kulia" -> "Perlengkapan Kuliah"
        DB::table('products')
            ->where('category', 'Kulia')
            ->update(['category' => 'Perlengkapan Kuliah']);
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
            ->where('category', 'Perlengkapan Kuliah')
            ->update(['category' => 'Kulia']);
    }
};
