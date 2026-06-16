<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notebook_content_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('notebook_id')->constrained('notebooks')->cascadeOnDelete();
            $table->foreignUuid('content_source_id')->constrained('content_sources')->cascadeOnDelete();
            $table->timestampTz('added_at', 0)->useCurrent();

            $table->unique(['notebook_id', 'content_source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notebook_content_sources');
    }
};
