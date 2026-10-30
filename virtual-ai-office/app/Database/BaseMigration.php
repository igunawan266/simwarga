<?php

namespace App\Database;

use CodeIgniter\Database\Migration;

/**
 * Shared column helpers so every migration declares ids, timestamps and
 * JSON columns the same way on MySQL/MariaDB (production) and SQLite (tests).
 */
abstract class BaseMigration extends Migration
{
    protected function id(): array
    {
        return ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true];
    }

    protected function fk(bool $nullable = false): array
    {
        return ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => $nullable];
    }

    protected function str(int $length = 191, bool $nullable = false, ?string $default = null): array
    {
        $col = ['type' => 'VARCHAR', 'constraint' => $length, 'null' => $nullable];
        if ($default !== null) {
            $col['default'] = $default;
        }

        return $col;
    }

    protected function text(bool $nullable = true): array
    {
        return ['type' => 'TEXT', 'null' => $nullable];
    }

    protected function json(): array
    {
        return ['type' => $this->db->DBDriver === 'MySQLi' ? 'JSON' : 'TEXT', 'null' => true];
    }

    protected function int(int $default = 0, bool $nullable = false): array
    {
        return ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => $nullable, 'default' => $default];
    }

    /** Monetary amounts are stored in whole Rupiah (Tripay works in integer IDR). */
    protected function money(): array
    {
        return ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'default' => 0];
    }

    protected function bool(int $default = 0): array
    {
        return ['type' => 'TINYINT', 'constraint' => 1, 'default' => $default];
    }

    protected function datetime(bool $nullable = true): array
    {
        return ['type' => 'DATETIME', 'null' => $nullable];
    }

    protected function timestamps(bool $softDeletes = false): array
    {
        $cols = [
            'created_at' => $this->datetime(),
            'updated_at' => $this->datetime(),
        ];
        if ($softDeletes) {
            $cols['deleted_at'] = $this->datetime();
        }

        return $cols;
    }

    protected function tableAttributes(): array
    {
        return $this->db->DBDriver === 'MySQLi'
            ? ['ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4', 'COLLATE' => 'utf8mb4_unicode_ci']
            : [];
    }
}
