<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->unsignedSmallInteger('submit_attempts')->default(0)->change();
        });

        Schema::table('generations', function (Blueprint $table) {
            $table->boolean('submit_unconfirmed')->default(false)->after('submit_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn('submit_unconfirmed');
        });

        Schema::table('generations', function (Blueprint $table) {
            $table->unsignedTinyInteger('submit_attempts')->default(0)->change();
        });
    }
};
