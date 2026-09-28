<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            if (!Schema::hasColumn('rental_applications', 'owner_id')) {
                // Денормализация: кабинет владельца иначе делал бы join через
                // объявление на каждый список, а объявление может сменить владельца.
                $table->unsignedBigInteger('owner_id')->nullable();
            }
            if (!Schema::hasColumn('rental_applications', 'desired_start_date')) {
                $table->date('desired_start_date')->nullable();
            }
            if (!Schema::hasColumn('rental_applications', 'desired_end_date')) {
                $table->date('desired_end_date')->nullable();
            }
            if (!Schema::hasColumn('rental_applications', 'price_tier_id')) {
                $table->unsignedBigInteger('price_tier_id')->nullable();
            }
            if (!Schema::hasColumn('rental_applications', 'calculated_total')) {
                $table->decimal('calculated_total', 10, 2)->nullable();
            }
            if (!Schema::hasColumn('rental_applications', 'source')) {
                $table->enum('source', ['landing', 'admin'])->default('landing');
            }
            if (!Schema::hasColumn('rental_applications', 'status_changed_by')) {
                $table->string('status_changed_by', 20)->nullable();
            }
            if (!Schema::hasColumn('rental_applications', 'status_changed_at')) {
                $table->timestamp('status_changed_at')->nullable();
            }
        });

        Schema::table('rental_applications', function (Blueprint $table) {
            foreach ([
                'ra_owner_idx'   => ['owner_id', 'status_id', 'created_at'],
                'ra_listing_idx' => ['performer_transport_id', 'created_at'],
            ] as $name => $columns) {
                if (!$this->indexExists('rental_applications', $name)) {
                    $table->index($columns, $name);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            foreach (['ra_owner_idx', 'ra_listing_idx'] as $name) {
                if ($this->indexExists('rental_applications', $name)) {
                    $table->dropIndex($name);
                }
            }

            $columns = array_filter([
                'owner_id', 'desired_start_date', 'desired_end_date', 'price_tier_id',
                'calculated_total', 'source', 'status_changed_by', 'status_changed_at',
            ], fn ($column) => Schema::hasColumn('rental_applications', $column));

            if ($columns) {
                $table->dropColumn($columns);
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        return count(Schema::getConnection()
            ->select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index])) > 0;
    }
};
