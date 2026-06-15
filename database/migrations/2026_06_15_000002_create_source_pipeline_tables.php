<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->text('url')->nullable();
            $table->text('file_ref')->nullable();
            $table->text('title')->nullable();
            $table->boolean('auto_update')->default(false);
            $table->string('extraction_status')->default('pending');
            $table->text('nlm_temp_source_id')->nullable();
            $table->foreignUuid('parent_source_id')->nullable()->constrained('content_sources')->nullOnDelete();
            $table->string('discovery_method')->default('manual');
            $table->string('review_status')->default('approved');
            $table->string('last_fetched_id')->nullable();
            $table->json('metadata')->nullable();
            $table->text('error_message')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamps();
        });

        Schema::create('source_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('knowledge_base_id')->constrained('notebooks')->cascadeOnDelete();
            $table->foreignUuid('content_source_id')->nullable()->constrained('content_sources')->nullOnDelete();
            $table->string('type')->nullable();
            $table->text('raw_input');
            $table->json('channel_meta')->nullable();
            $table->json('scrape_config')->nullable();
            $table->boolean('auto_update')->default(false);
            $table->string('status')->default('fetching_meta');
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['content_source_id']);
        });

        Schema::create('original_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('content_source_id')->constrained('content_sources')->cascadeOnDelete();
            $table->text('title')->nullable();
            $table->text('full_text')->nullable();
            $table->text('source_url')->nullable();
            $table->foreignUuid('parent_item_id')->nullable()->constrained('original_items')->nullOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->unsignedInteger('word_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['content_source_id', 'published_at']);
        });

        Schema::create('md_bundles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('notebook_id')->constrained('notebooks')->cascadeOnDelete();
            $table->string('type');
            $table->text('file_path')->nullable();
            $table->unsignedInteger('word_count')->default(0);
            $table->string('status')->default('pending');
            $table->text('nlm_source_id')->nullable();
            $table->boolean('is_consolidating')->default(false);
            $table->string('error_code')->nullable();
            $table->timestamps();

            $table->index(['notebook_id', 'status']);
            $table->index(['nlm_source_id']);
        });

        Schema::create('bundle_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('bundle_id')->constrained('md_bundles')->cascadeOnDelete();
            $table->foreignUuid('original_item_id')->constrained('original_items')->cascadeOnDelete();
            $table->unsignedInteger('position');
        });

        $this->createPartialIndexes();
    }

    public function down(): void
    {
        Schema::dropIfExists('bundle_items');
        Schema::dropIfExists('md_bundles');
        Schema::dropIfExists('original_items');
        Schema::dropIfExists('source_drafts');
        Schema::dropIfExists('content_sources');
    }

    private function createPartialIndexes(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite' || $driver === 'pgsql' || $driver === 'mysql') {
            DB::statement('CREATE INDEX content_sources_user_auto_update_true_index ON content_sources(user_id, auto_update) WHERE auto_update = true');
            DB::statement('CREATE INDEX content_sources_parent_source_id_not_null_index ON content_sources(parent_source_id) WHERE parent_source_id IS NOT NULL');
            DB::statement("CREATE INDEX content_sources_pending_review_index ON content_sources(review_status) WHERE review_status = 'pending_review'");
        }
    }
};
