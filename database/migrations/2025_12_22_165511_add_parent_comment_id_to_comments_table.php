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
        Schema::table('comments', function (Blueprint $table) {
            $table->foreignId('parent_comment_id')->nullable()->after('user_id')->constrained('comments')->onDelete('cascade');
            $table->index(['parent_comment_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropForeign(['parent_comment_id']);
            $table->dropIndex(['parent_comment_id', 'created_at']);
            $table->dropColumn('parent_comment_id');
        });
    }
};
