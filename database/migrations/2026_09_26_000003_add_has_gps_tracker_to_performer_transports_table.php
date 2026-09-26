<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performer_transports', function (Blueprint $table) {
            $table->boolean('has_gps_tracker')->nullable()->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('performer_transports', function (Blueprint $table) {
            $table->dropColumn('has_gps_tracker');
        });
    }
};
