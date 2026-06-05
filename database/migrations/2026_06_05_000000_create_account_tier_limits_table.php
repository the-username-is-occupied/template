<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_tier_limits', function (Blueprint $table): void {
            $table->string('tier')->primary();
            $table->unsignedInteger('notebooks_limit');
            $table->unsignedInteger('sources_per_notebook');
            $table->unsignedInteger('chats_per_day');
            $table->unsignedInteger('audio_per_day');
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_tier_limits');
    }
};
