<?php

namespace Tests\Support;

use Spatie\DbDumper\Databases\Sqlite;
use SQLite3;

/**
 * SQLite dumper written in PHP, for tests and machines without the
 * `sqlite3` command-line tool (spatie/db-dumper shells out to it).
 * Production backs up MySQL with `mysqldump`; this only lets a real
 * `backup:run` happen locally against an SQLite file.
 */
class PhpSqliteDumper extends Sqlite
{
    public function dumpToFile(string $dumpFile): void
    {
        $db = new SQLite3($this->dbName, SQLITE3_OPEN_READONLY);
        $lines = ['BEGIN TRANSACTION;'];

        $tables = $db->query("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");

        while ($table = $tables->fetchArray(SQLITE3_ASSOC)) {
            $lines[] = $table['sql'].';';
            $rows = $db->query('SELECT * FROM "'.str_replace('"', '""', $table['name']).'"');

            while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
                $values = array_map(fn (mixed $value) => match (true) {
                    $value === null => 'NULL',
                    is_int($value), is_float($value) => (string) $value,
                    default => "'".SQLite3::escapeString((string) $value)."'",
                }, $row);

                $lines[] = 'INSERT INTO "'.$table['name'].'" VALUES('.implode(',', $values).');';
            }
        }

        $db->close();
        $lines[] = 'COMMIT;';

        file_put_contents($dumpFile, implode("\n", $lines)."\n");
    }
}
