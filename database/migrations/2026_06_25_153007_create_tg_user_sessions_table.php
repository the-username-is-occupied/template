<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tg_user_sessions', function (Blueprint $table) {
            $table->bigInteger('tg_user_id')->unique()->primary();
            $table->uuid('active_base_id')->nullable();
            $table->timestamps();

            $table->foreign('active_base_id')
                ->references('id')
                ->on('notebooks')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tg_user_sessions');
    }
};
