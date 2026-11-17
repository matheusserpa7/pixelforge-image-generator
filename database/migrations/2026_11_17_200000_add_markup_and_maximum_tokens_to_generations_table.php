<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->string('markup_percent', 32)->nullable()->after('maximum_charge_nanos');
            $table->json('maximum_tokens')->nullable()->after('markup_percent');
        });

        $this->backfill();

        Schema::table('generations', function (Blueprint $table) {
            $table->string('markup_percent', 32)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn(['markup_percent', 'maximum_tokens']);
        });
    }

    /**
     * Charged generations keep the markup their ledger row recorded. Every other row takes the
     * configured markup at migration time.
     */
    public function backfill(): void
    {
        DB::table('generations')
            ->join('ledger_entries', 'ledger_entries.generation_id', '=', 'generations.id')
            ->update(['generations.markup_percent' => DB::raw('ledger_entries.markup_percent')]);

        DB::table('generations')
            ->whereNotExists(function (Builder $query): void {
                $query->select(DB::raw(1))
                    ->from('ledger_entries')
                    ->whereColumn('ledger_entries.generation_id', 'generations.id');
            })
            ->update(['markup_percent' => config()->string('billing.markup_percent')]);
    }
};
