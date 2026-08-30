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
        // Drop the index first if it exists
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['sqlite', 'pgsql', 'mysql'], true)) {
            DB::statement('DROP INDEX IF EXISTS original_items_unbundled_index');
        }

        // Drop the column if it exists
        if (Schema::hasColumn('original_items', 'md_bundle_id')) {
            Schema::table('original_items', function (Blueprint $table): void {
                $table->dropForeign(['md_bundle_id']);
                $table->dropColumn('md_bundle_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('original_items', function (Blueprint $table): void {
            $table->foreignUuid('md_bundle_id')->nullable()->after('metadata')->constrained('md_bundles')->nullOnDelete();
        });

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['sqlite', 'pgsql', 'mysql'], true)) {
            DB::statement('CREATE INDEX original_items_unbundled_index ON original_items(md_bundle_id) WHERE md_bundle_id IS NULL');
        }
    }
};
