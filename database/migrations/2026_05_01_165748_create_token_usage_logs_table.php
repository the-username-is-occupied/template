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
        Schema::create('token_usage_logs', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('user_space_id')->nullable()->constrained()->nullOnDelete();
            $blueprint->string('operation_type');
            $blueprint->string('model_name');
            $blueprint->unsignedInteger('prompt_tokens')->nullable();
            $blueprint->unsignedInteger('completion_tokens')->nullable();
            $blueprint->decimal('estimated_cost_usd', 10, 8)->nullable();
            $blueprint->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('token_usage_logs');
    }
};
