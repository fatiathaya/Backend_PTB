<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // For MySQL, use raw SQL to rename column
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE products CHANGE image image_products VARCHAR(255) NULL');
        } else {
            // For other databases, use renameColumn if supported
            Schema::table('products', function (Blueprint $table) {
                $table->renameColumn('image', 'image_products');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // For MySQL, use raw SQL to rename column back
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE products CHANGE image_products image VARCHAR(255) NULL');
        } else {
            // For other databases, use renameColumn if supported
            Schema::table('products', function (Blueprint $table) {
                $table->renameColumn('image_products', 'image');
            });
        }
    }
};

