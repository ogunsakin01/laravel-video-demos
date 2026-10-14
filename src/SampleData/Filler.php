<?php

namespace Ogunsakin01\VideoDemo\SampleData;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Finds values for the required columns a record was given no value for,
 * from each column's name and type, so a row can be saved without a
 * factory: an email for "email", a hashed password, the first allowed
 * value of an enum, the id of an existing row for a foreign key.
 */
class Filler
{
    /**
     * The columns of each table read so far.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    protected array $columns = [];

    /**
     * Get values for the table's required columns missing from $given.
     *
     * $casts are the model's, when there is one: SQLite reports JSON columns
     * as text, and the casts say which hold JSON.
     *
     * @param  array<string, mixed>  $given
     * @param  array<string, string>  $casts
     * @return array<string, mixed>
     */
    public function missing(string $table, array $given, array $casts = []): array
    {
        $values = [];
        foreach ($this->columns($table) as $column) {
            $name = $column['name'];
            if ($column['nullable'] || $column['default'] !== null || $column['auto_increment'] || array_key_exists($name, $given)) {
                continue;
            }
            // An integer primary key fills itself in SQLite.
            if ($name === 'id' && str_contains(strtolower((string) $column['type_name']), 'int')) {
                continue;
            }

            $cast = strtolower((string) ($casts[$name] ?? ''));
            $values[$name] = preg_match('/array|json|collection|object/', $cast)
                ? '[]'
                : $this->guess($table, $name, strtolower((string) $column['type_name']));
        }

        return $values;
    }

    /**
     * Get the names of the table's columns.
     *
     * @return list<string>
     */
    public function names(string $table): array
    {
        return array_column($this->columns($table), 'name');
    }

    /**
     * Get the table's columns.
     *
     * @return list<array<string, mixed>>
     */
    public function columns(string $table): array
    {
        return $this->columns[$table] ??= Schema::getColumns($table);
    }

    /**
     * Guess a value for a column from its name and type.
     */
    protected function guess(string $table, string $name, string $type): mixed
    {
        if ($allowed = $this->allowedValues($table, $name)) {
            return $allowed[0];
        }

        $label = Str::headline(Str::singular($table));

        return match (true) {
            str_contains($name, 'email') => 'sample-'.Str::lower(Str::random(8)).'@example.com',
            str_contains($name, 'password') => Hash::make('password'),
            $name === 'uuid' || $name === 'ulid' || ($name === 'id' && ! str_contains($type, 'int')) => (string) Str::uuid(),
            str_ends_with($name, '_id') => $this->foreignId($table, $name),
            str_ends_with($name, '_at') || in_array($type, ['date', 'datetime', 'timestamp'], true) => now()->toDateTimeString(),
            $name === 'slug' => Str::slug($label).'-'.Str::lower(Str::random(6)),
            str_contains($type, 'json') => '{}',
            str_contains($type, 'bool') || $type === 'tinyint(1)' => false,
            (bool) preg_match('/int|decimal|numeric|float|double|real/', $type) => 0,
            in_array($name, ['name', 'title', 'label', 'subject'], true) => "Sample $label",
            default => 'Sample',
        };
    }

    /**
     * Get the id of a row the foreign key can point at, making a row in the
     * parent table when it has none.
     */
    protected function foreignId(string $table, string $column): mixed
    {
        foreach (Schema::getForeignKeys($table) as $key) {
            if ($key['columns'] === [$column]) {
                $parent = $key['foreign_table'];
                $parentKey = $key['foreign_columns'][0] ?? 'id';

                return DB::table($parent)->value($parentKey)
                    ?? DB::table($parent)->insertGetId($this->missing($parent, []));
            }
        }

        return 1;
    }

    /**
     * Get the values an enum column allows, from its CHECK constraint on SQLite.
     *
     * @return list<string>
     */
    protected function allowedValues(string $table, string $column): array
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return [];
        }

        $sql = (string) DB::table('sqlite_master')->where('type', 'table')->where('name', $table)->value('sql');
        if (! preg_match('/["`]?'.preg_quote($column, '/').'["`]?\s+in\s*\(([^)]*)\)/i', $sql, $match)) {
            return [];
        }

        preg_match_all("/'((?:[^']|'')*)'/", $match[1], $values);

        return array_map(fn ($v) => str_replace("''", "'", $v), $values[1]);
    }
}
