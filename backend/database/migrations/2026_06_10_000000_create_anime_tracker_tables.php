<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('google_sub', 191)->unique();
                $table->string('email');
                $table->string('display_name', 160)->nullable();
                $table->text('avatar_url')->nullable();
                $table->string('public_slug', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('anime')) {
            Schema::create('anime', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 160)->index();
                $table->text('description')->nullable();
                $table->text('image_url')->nullable();
                $table->string('source', 32)->default('manual');
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedSmallInteger('season_year')->nullable();
                $table->string('season_code', 16)->nullable();
                $table->date('air_date')->nullable()->index();
                $table->unsignedSmallInteger('episode_count')->nullable();
                $table->string('status', 32)->nullable();
                $table->timestamps();
                $table->index(['season_year', 'season_code']);
            });
        }

        if (! Schema::hasTable('anime_titles')) {
            Schema::create('anime_titles', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('anime_id')->constrained('anime')->cascadeOnDelete();
                $table->string('locale', 16)->index();
                $table->string('title', 240)->index();
                $table->boolean('is_primary')->default(false);
                $table->string('source', 32);
                $table->timestamps();
                $table->unique(['anime_id', 'locale', 'title'], 'uniq_anime_title_locale_title');
            });
        }

        if (! Schema::hasTable('anime_aliases')) {
            Schema::create('anime_aliases', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('anime_id')->constrained('anime')->cascadeOnDelete();
                $table->string('alias', 160)->index();
            });
        }

        if (! Schema::hasTable('anime_external_ids')) {
            Schema::create('anime_external_ids', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('anime_id')->constrained('anime')->cascadeOnDelete();
                $table->string('provider', 32);
                $table->string('external_id', 64);
                $table->text('url')->nullable();
                $table->dateTime('last_synced_at');
                $table->char('payload_hash', 64);
                $table->unique(['provider', 'external_id'], 'uniq_anime_external_provider_id');
            });
        }

        if (! Schema::hasTable('user_anime_list_items')) {
            Schema::create('user_anime_list_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('anime_id')->constrained('anime')->cascadeOnDelete();
                $table->boolean('watched')->default(false);
                $table->unsignedTinyInteger('rating')->nullable();
                $table->text('note')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'anime_id'], 'uniq_user_anime');
                $table->index('anime_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_anime_list_items');
        Schema::dropIfExists('anime_external_ids');
        Schema::dropIfExists('anime_aliases');
        Schema::dropIfExists('anime_titles');
        Schema::dropIfExists('anime');
        Schema::dropIfExists('users');
    }
};
