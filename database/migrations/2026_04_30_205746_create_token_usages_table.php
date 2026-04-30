<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_usages', function (Blueprint $table) {
            $table->id();
            $table->string('userspace');
            $table->enum('operation', ['ingest', 'query', 'lint']);
            $table->string('provider');
            $table->string('model');
            $table->integer('input_tokens');
            $table->integer('output_tokens');
            $table->decimal('input_cost', 10, 6);
            $table->decimal('output_cost', 10, 6);
            $table->decimal('total_cost', 10, 6);
            $table->string('source_file')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_usages');
    }
};
