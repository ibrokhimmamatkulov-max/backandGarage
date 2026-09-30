<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Импорт справочников машин из дампов 3taxi_driver (database/seeders/CarDumps).
 *
 *     php artisan db:seed --class=CarReferenceSeeder --force
 *
 * Как работает:
 *  1. Каждый дамп заливается во временную таблицу-копию (tmp_<таблица>).
 *  2. Проверка: не поменяется ли смысл id, на которые уже ссылаются
 *     объявления (модель, кузов, опции, топливо). Справочники этого проекта
 *     и 3taxi_driver пополнялись независимо, и с какого-то id одинаковые
 *     номера означают разные записи — upsert по такому id молча превратил бы
 *     чужое объявление в другую машину. Если расхождения есть — список
 *     выводится, без явного подтверждения импорт не выполняется
 *     (в неинтерактивном запуске — всегда отказ).
 *  3. Upsert по id. Строки, которых нет в дампе, не удаляются — на них
 *     могут ссылаться объявления.
 *
 *  4. После успешного импорта папка CarDumps удаляется. Нет папки — сидер
 *     ничего не делает (поэтому его безопасно держать в DatabaseSeeder).
 *
 * Только MySQL (INSERT ... ON DUPLICATE KEY UPDATE, временные таблицы).
 */
class CarReferenceSeeder extends Seeder
{
    private const DUMPS_DIR = __DIR__ . '/CarDumps';

    /**
     * Порядок — от независимых справочников к зависимым.
     * Значение — колонки дампа, которых нет в таблице проекта (отбрасываются).
     */
    private const TABLES = [
        'category_cars' => [],
        'class_cars'    => [],
        'body_types'    => [],
        'car_brands'    => [],
        'car_options'   => ['is_visible' => 'tinyint(1) NULL'],
        'model_cars'    => [],
    ];

    public function run(): void
    {
        if (!is_dir(self::DUMPS_DIR)) {
            $this->command->info('Папки CarDumps нет — справочники уже импортированы, пропуск.');

            return;
        }

        $this->loadStaging();

        try {
            $conflicts = $this->findConflicts();

            if ($conflicts !== [] && !$this->confirmConflicts($conflicts)) {
                $this->command->warn('Импорт отменён, данные не изменены.');

                return;
            }

            DB::transaction(function () {
                foreach (array_keys(self::TABLES) as $table) {
                    $this->upsertFromStaging($table);
                }
            });
        } finally {
            foreach (array_keys(self::TABLES) as $table) {
                DB::statement("DROP TEMPORARY TABLE IF EXISTS tmp_{$table}");
            }
        }

        $this->deleteDumps();
    }

    /**
     * Дампы одноразовые: после полного импорта папка удаляется.
     * Сюда попадаем только после успешной транзакции — при отмене или
     * ошибке папка остаётся, импорт можно повторить.
     */
    private function deleteDumps(): void
    {
        if (File::deleteDirectory(self::DUMPS_DIR)) {
            $this->command->info('  Папка CarDumps удалена.');

            return;
        }

        $this->command->warn('  Импорт выполнен, но папку CarDumps удалить не удалось (нет прав?): ' . self::DUMPS_DIR);
    }

    /**
     * DDL временных таблиц идёт до транзакции: ALTER TABLE в MySQL
     * делает неявный COMMIT.
     */
    private function loadStaging(): void
    {
        foreach (self::TABLES as $table => $extraColumns) {
            DB::statement("DROP TEMPORARY TABLE IF EXISTS tmp_{$table}");
            DB::statement("CREATE TEMPORARY TABLE tmp_{$table} LIKE {$table}");

            foreach ($extraColumns as $column => $definition) {
                DB::statement("ALTER TABLE tmp_{$table} ADD COLUMN {$column} {$definition}");
            }

            DB::unprepared($this->readDump($table));

            $count = DB::table("tmp_{$table}")->count();
            $this->command->line("  {$table}: в дампе {$count} строк");
        }
    }

    /**
     * Дамп — один INSERT вида "insert into 3taxi_driver.<таблица> (...) values ...".
     * Имя схемы источника заменяется на временную таблицу.
     */
    private function readDump(string $table): string
    {
        $path = self::DUMPS_DIR . "/{$table}.sql";

        if (!is_file($path)) {
            throw new RuntimeException("Нет дампа {$path}");
        }

        $sql = preg_replace(
            '/^\s*insert\s+into\s+(?:`?\w+`?\.)?`?' . $table . '`?\s*\(/i',
            "insert into tmp_{$table} (",
            file_get_contents($path),
            1,
            $replaced
        );

        if ($replaced !== 1) {
            throw new RuntimeException("Дамп {$path}: ожидался один INSERT INTO {$table}");
        }

        return $sql;
    }

    /**
     * Ссылки из живых объявлений на id, которые в дампе означают другое.
     *
     * @return array<int, array{what: string, id: int, refs: int, current: string, dump: string}>
     */
    private function findConflicts(): array
    {
        // Модель сравнивается по названию бренда + модели, а не по car_brand_id:
        // id брендов тоже разошлись.
        $models = DB::select("
            SELECT 'model_cars' AS what, m.id, COUNT(*) AS refs,
                   CONCAT(COALESCE(b.name, '?'), ' / ', COALESCE(m.car_model, m.name)) AS current,
                   CONCAT(COALESCE(tb.name, '?'), ' / ', COALESCE(t.car_model, t.name)) AS dump
            FROM performer_transports pt
            JOIN model_cars m      ON m.id = pt.car_model_id
            JOIN tmp_model_cars t  ON t.id = m.id
            LEFT JOIN car_brands b      ON b.id = m.car_brand_id
            LEFT JOIN tmp_car_brands tb ON tb.id = t.car_brand_id
            WHERE pt.deleted_at IS NULL
              AND NOT (b.name <=> tb.name AND COALESCE(m.car_model, m.name) <=> COALESCE(t.car_model, t.name))
            GROUP BY m.id, current, dump
        ");

        $bodyTypes = DB::select("
            SELECT 'body_types' AS what, bt.id, COUNT(*) AS refs, bt.name AS current, t.name AS dump
            FROM performer_transports pt
            JOIN body_types bt     ON bt.id = pt.body_type_id
            JOIN tmp_body_types t  ON t.id = bt.id
            WHERE pt.deleted_at IS NULL AND NOT (bt.name <=> t.name)
            GROUP BY bt.id, bt.name, t.name
        ");

        // Опции: и доп. опции объявления, и тип топлива — оба из car_options.
        $options = DB::select("
            SELECT 'car_options' AS what, o.id, COUNT(*) AS refs, o.name AS current, t.name AS dump
            FROM (
                SELECT option_id AS id FROM performer_transport_options WHERE deleted_at IS NULL
                UNION ALL
                SELECT fuel_type_id FROM performer_transports WHERE deleted_at IS NULL
            ) ref
            JOIN car_options o     ON o.id = ref.id
            JOIN tmp_car_options t ON t.id = o.id
            WHERE NOT (o.name <=> t.name AND o.model <=> t.model)
            GROUP BY o.id, o.name, t.name
        ");

        return array_map(fn ($row) => (array) $row, [...$models, ...$bodyTypes, ...$options]);
    }

    private function confirmConflicts(array $conflicts): bool
    {
        $this->command->warn(
            'Эти id уже используются объявлениями, а в дампе означают другое. '
            . 'После импорта объявления будут показывать значение из дампа:'
        );

        $this->command->table(
            ['Таблица', 'id', 'Объявлений', 'Сейчас', 'В дампе'],
            array_map(fn ($c) => [$c['what'], $c['id'], $c['refs'], $c['current'], $c['dump']], $conflicts)
        );

        return $this->command->confirm('Импортировать всё равно?', false);
    }

    private function upsertFromStaging(string $table): void
    {
        $columns = DB::getSchemaBuilder()->getColumnListing($table);

        $list    = implode(', ', array_map(fn ($c) => "`{$c}`", $columns));
        $updates = implode(', ', array_map(
            fn ($c) => "`{$c}` = VALUES(`{$c}`)",
            array_filter($columns, fn ($c) => $c !== 'id')
        ));

        $before = DB::table($table)->count();

        DB::statement("
            INSERT INTO {$table} ({$list})
            SELECT {$list} FROM tmp_{$table}
            ON DUPLICATE KEY UPDATE {$updates}
        ");

        $added = DB::table($table)->count() - $before;
        $this->command->info("  {$table}: добавлено {$added}, остальные обновлены по id");
    }
}
