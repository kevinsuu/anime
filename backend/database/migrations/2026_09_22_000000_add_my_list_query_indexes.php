<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_anime_list_items', function (Blueprint $table): void {
            // Serves the common "recently added" list and pagination query.
            $table->index(['user_id', 'created_at', 'id'], 'user_list_user_created_id');

            // Narrows watched/unwatched tabs before joining anime for sorting.
            $table->index(['user_id', 'watched', 'id'], 'user_list_user_watched_id');
        });
    }

    public function down(): void
    {
        Schema::table('user_anime_list_items', function (Blueprint $table): void {
            $table->dropIndex('user_list_user_created_id');
            $table->dropIndex('user_list_user_watched_id');
        });
    }
};
