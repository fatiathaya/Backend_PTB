<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('search_histories') && !Schema::hasTable('search_history')) {
            Schema::rename('search_histories', 'search_history');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('search_history') && !Schema::hasTable('search_histories')) {
            Schema::rename('search_history', 'search_histories');
        }
    }
};
