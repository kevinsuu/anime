<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // User-defined collections (e.g. "戀愛番", "2026夏季必看")
        if (! Schema::hasTable('user_collections')) {
            Schema::create('user_collections', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('name', 80);
                $table->string('public_slug', 64)->unique()->nullable();
                $table->boolean('is_public')->default(false);
                $table->timestamps();
                $table->unique(['user_id', 'name'], 'uniq_user_collection_name');
            });
        }

        // Many-to-many: list items belong to collections
        if (! Schema::hasTable('collection_items')) {
            Schema::create('collection_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('collection_id')->constrained('user_collections')->cascadeOnDelete();
                $table->foreignId('list_item_id')->constrained('user_anime_list_items')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['collection_id', 'list_item_id'], 'uniq_collection_item');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_items');
        Schema::dropIfExists('user_collections');
    }
};
