<?php

declare(strict_types=1);

use App\Support\Billing\OpeningBalances;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 32);
            $table->bigInteger('amount_nanos');
            $table->ulid('idempotency_key')->nullable()->unique();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });

        OpeningBalances::backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_purchases');
    }
};
