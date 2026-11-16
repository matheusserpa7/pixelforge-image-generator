<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32);
            $table->text('prompt');
            $table->string('size', 32);
            $table->string('quality', 32);
            $table->json('reference_images');
            $table->string('provider_id')->nullable()->unique();
            $table->bigInteger('estimated_provider_cost_nanos');
            $table->bigInteger('maximum_provider_cost_nanos');
            $table->bigInteger('estimated_charge_nanos');
            $table->bigInteger('maximum_charge_nanos');
            $table->bigInteger('provider_cost_nanos')->nullable();
            $table->bigInteger('charge_nanos')->nullable();
            $table->json('rates')->nullable();
            $table->json('usage')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('provider_started_at')->nullable();
            $table->timestamp('provider_completed_at')->nullable();
            $table->unsignedTinyInteger('submit_attempts')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
