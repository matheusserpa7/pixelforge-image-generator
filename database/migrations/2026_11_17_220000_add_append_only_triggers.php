<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = ['ledger_entries', 'credit_purchases'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $name => $event) {
                DB::unprepared(
                    "CREATE TRIGGER {$table}_no_{$name} BEFORE {$event} ON {$table} FOR EACH ROW "
                    ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} is append-only'"
                );
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            foreach (['update', 'delete'] as $name) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_{$name}");
            }
        }
    }
};
