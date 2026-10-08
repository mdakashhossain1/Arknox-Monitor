<?php

namespace Modules\ArknoxMonitor\App\Support;

use Illuminate\Support\Facades\DB;

class Upsert
{
    /**
     * Inserts a counter row, or adds to / maxes against the existing row when the unique key already exists.
     *
     * @param  array<string, mixed>  $values  every column of the new row
     * @param  list<string>  $keys  columns forming the unique key
     * @param  list<string>  $add  columns incremented by the inserted value
     * @param  list<string>  $max  columns kept at the larger of old and inserted value
     */
    public static function counters(string $table, array $values, array $keys, array $add, array $max = []): void
    {
        $driver = DB::connection()->getDriverName();
        $mysql = in_array($driver, ['mysql', 'mariadb'], true);
        $incoming = fn (string $column) => $mysql ? "VALUES({$column})" : "EXCLUDED.{$column}";
        $largest = $driver === 'sqlite' ? 'MAX' : 'GREATEST';

        $updates = [];
        foreach ($add as $column) {
            $updates[] = "{$column} = {$table}.{$column} + {$incoming($column)}";
        }
        foreach ($max as $column) {
            $updates[] = "{$column} = {$largest}({$table}.{$column}, {$incoming($column)})";
        }
        $updates[] = "updated_at = {$incoming('updated_at')}";

        $columns = array_keys($values);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $conflict = $mysql ? 'ON DUPLICATE KEY UPDATE' : 'ON CONFLICT ('.implode(', ', $keys).') DO UPDATE SET';

        DB::statement(
            "INSERT INTO {$table} (".implode(', ', $columns).") VALUES ({$placeholders}) {$conflict} ".implode(', ', $updates),
            array_values($values)
        );
    }
}
