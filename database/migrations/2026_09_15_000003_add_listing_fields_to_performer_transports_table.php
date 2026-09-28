<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Превращает performer_transports в «объявление», оставляя её «машиной».
 *
 * Дефолты подобраны так, чтобы все существующие строки после наката остались
 * ровно в том же состоянии: видимы на витрине (published) и помечены как
 * таксопарковые (taxi). Ручная доработка данных не требуется.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performer_transports', function (Blueprint $table) {
            if (!Schema::hasColumn('performer_transports', 'owner_id')) {
                $table->unsignedBigInteger('owner_id')->nullable()->after('performer_id');
            }
            if (!Schema::hasColumn('performer_transports', 'listing_type')) {
                $table->enum('listing_type', ['taxi', 'general'])->default('taxi');
            }
            if (!Schema::hasColumn('performer_transports', 'source')) {
                $table->enum('source', ['admin', 'owner'])->default('admin');
            }
            if (!Schema::hasColumn('performer_transports', 'moderation_status')) {
                $table->enum('moderation_status', [
                    'pending', 'published', 'rejected', 'paused', 'archived',
                ])->default('published');
            }
            if (!Schema::hasColumn('performer_transports', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable();
            }
            if (!Schema::hasColumn('performer_transports', 'published_at')) {
                $table->timestamp('published_at')->nullable();
            }
            if (!Schema::hasColumn('performer_transports', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable();
            }
            if (!Schema::hasColumn('performer_transports', 'title')) {
                $table->string('title', 180)->nullable();
            }
            if (!Schema::hasColumn('performer_transports', 'description')) {
                $table->text('description')->nullable();
            }
            if (!Schema::hasColumn('performer_transports', 'max_rent_days')) {
                $table->unsignedInteger('max_rent_days')->nullable();
            }
            if (!Schema::hasColumn('performer_transports', 'views_count')) {
                $table->unsignedInteger('views_count')->default(0);
            }
        });

        Schema::table('performer_transports', function (Blueprint $table) {
            foreach ([
                'pt_showcase_idx'   => ['moderation_status', 'listing_type', 'city_id'],
                'pt_owner_idx'      => ['owner_id', 'moderation_status'],
                'pt_moderation_idx' => ['moderation_status', 'submitted_at'],
            ] as $name => $columns) {
                if (!$this->indexExists('performer_transports', $name)) {
                    $table->index($columns, $name);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('performer_transports', function (Blueprint $table) {
            foreach (['pt_showcase_idx', 'pt_owner_idx', 'pt_moderation_idx'] as $name) {
                if ($this->indexExists('performer_transports', $name)) {
                    $table->dropIndex($name);
                }
            }

            $columns = array_filter([
                'owner_id', 'listing_type', 'source', 'moderation_status',
                'rejection_reason', 'published_at', 'submitted_at',
                'title', 'description', 'max_rent_days', 'views_count',
            ], fn ($column) => Schema::hasColumn('performer_transports', $column));

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
