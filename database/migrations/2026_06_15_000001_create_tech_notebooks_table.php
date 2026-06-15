<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tech_notebooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('account_id');
            $table->string('notebook_id');
            $table->string('type'); // source_extractor, summary_aggregator, global_search
            $table->integer('sources_count')->default(0);
            $table->integer('max_sources')->default(20);
            $table->string('status')->default('idle'); // idle, busy, full, degraded
            $table->timestamp('locked_at')->nullable();
            $table->string('locked_by')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('tech_accounts')->onDelete('cascade');
            $table->index(['type', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tech_notebooks');
    }
};
