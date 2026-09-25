<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE rental_tariffs ALTER COLUMN free_weekend_day TYPE INTEGER, ALTER COLUMN free_weekend_day SET DEFAULT 0, ALTER COLUMN free_weekend_day SET NOT NULL');

            return;
        }

        DB::statement('ALTER TABLE rental_tariffs MODIFY free_weekend_day INT NOT NULL DEFAULT 0');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE rental_tariffs ALTER COLUMN free_weekend_day TYPE SMALLINT, ALTER COLUMN free_weekend_day SET DEFAULT 0, ALTER COLUMN free_weekend_day SET NOT NULL');

            return;
        }

        DB::statement('ALTER TABLE rental_tariffs MODIFY free_weekend_day TINYINT(1) NOT NULL DEFAULT 0');
    }
};
