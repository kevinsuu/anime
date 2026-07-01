<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('anime_themes')) {
            Schema::create('anime_themes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('anime_id')->constrained('anime')->cascadeOnDelete();
                $table->string('type', 16);   // OP / ED
                $table->string('title', 240);
                $table->string('artist', 240)->default('');
                $table->unsignedTinyInteger('sort_order')->default(0);
            });
        }

        if (! Schema::hasTable('anime_trailers')) {
            Schema::create('anime_trailers', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('anime_id')->constrained('anime')->cascadeOnDelete();
                $table->text('url');
                $table->text('thumbnail')->nullable();
                $table->unsignedTinyInteger('sort_order')->default(0);
            });
        }

        if (! Schema::hasTable('anime_cast')) {
            Schema::create('anime_cast', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('anime_id')->constrained('anime')->cascadeOnDelete();
                $table->string('character', 160);
                $table->string('actor', 160);
                $table->unsignedTinyInteger('sort_order')->default(0);
            });
        }

        if (! Schema::hasTable('anime_staff')) {
            Schema::create('anime_staff', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('anime_id')->constrained('anime')->cascadeOnDelete();
                $table->string('role', 120);
                $table->string('name', 240);
                $table->unsignedTinyInteger('sort_order')->default(0);
            });
        }

        if (! Schema::hasTable('anime_links')) {
            Schema::create('anime_links', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('anime_id')->constrained('anime')->cascadeOnDelete();
                $table->string('category', 64)->default('');
                $table->string('label', 120);
                $table->text('url');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('anime_links');
        Schema::dropIfExists('anime_staff');
        Schema::dropIfExists('anime_cast');
        Schema::dropIfExists('anime_trailers');
        Schema::dropIfExists('anime_themes');
    }
};
