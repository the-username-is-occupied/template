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
        Schema::table('content_sources', function (Blueprint $table): void {
            $table->foreignUuid('parent_item_id')->nullable()->after('parent_source_id')->constrained('original_items')->nullOnDelete();
        });

        Schema::table('original_items', function (Blueprint $table): void {
            $table->foreignUuid('md_bundle_id')->nullable()->after('metadata')->constrained('md_bundles')->nullOnDelete();
        });

        $this->createUnbundledIndex();
    }

    public function down(): void
    {
        Schema::table('original_items', function (Blueprint $table): void {
            $table->dropForeign(['md_bundle_id']);
            $table->dropColumn('md_bundle_id');
        });

        Schema::table('content_sources', function (Blueprint $table): void {
            $table->dropForeign(['parent_item_id']);
            $table->dropColumn('parent_item_id');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite' || $driver === 'pgsql' || $driver === 'mysql') {
            DB::statement('DROP INDEX IF EXISTS original_items_unbundled_index');
        }
    }

    private function createUnbundledIndex(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite' || $driver === 'pgsql' || $driver === 'mysql') {
            DB::statement('CREATE INDEX original_items_unbundled_index ON original_items(md_bundle_id) WHERE md_bundle_id IS NULL');
        }
    }
};
