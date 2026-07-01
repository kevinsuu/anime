<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anime', function (Blueprint $table): void {
            $table->char('import_hash', 64)->nullable()->after('tags');
        });
    }

    public function down(): void
    {
        Schema::table('anime', function (Blueprint $table): void {
            $table->dropColumn('import_hash');
        });
    }
};
