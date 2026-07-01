<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anime', function (Blueprint $table): void {
            $table->json('tags')->nullable()->after('status');
            $table->string('air_date_text', 120)->nullable()->after('air_date');
        });
    }

    public function down(): void
    {
        Schema::table('anime', function (Blueprint $table): void {
            $table->dropColumn(['tags', 'air_date_text']);
        });
    }
};
