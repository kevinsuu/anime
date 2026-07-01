<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anime_aliases', function (Blueprint $table): void {
            // Some light-novel adaptations have very long romaji aliases (180+ chars).
            $table->string('alias', 255)->change();
        });
    }

    public function down(): void
    {
        Schema::table('anime_aliases', function (Blueprint $table): void {
            $table->string('alias', 160)->change();
        });
    }
};
