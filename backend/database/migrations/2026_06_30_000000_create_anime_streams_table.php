<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('anime_streams')) {
            Schema::create('anime_streams', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('anime_id')->constrained('anime')->cascadeOnDelete();
                $table->string('region', 32);
                $table->string('platform', 64);
                $table->text('url')->nullable();
                $table->timestamps();
                $table->unique(['anime_id', 'region', 'platform'], 'uniq_anime_stream');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('anime_streams');
    }
};
