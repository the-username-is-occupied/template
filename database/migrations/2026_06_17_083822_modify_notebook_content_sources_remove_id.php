<?php

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
        Schema::table('notebook_content_sources', function (Blueprint $table) {
            $table->dropPrimary(); // Remove the primary key on 'id'
            $table->dropColumn('id'); // Remove the 'id' column
            $table->primary(['notebook_id', 'content_source_id']); // Set composite primary key
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notebook_content_sources', function (Blueprint $table) {
            $table->dropPrimary(); // Remove composite primary key
            $table->uuid('id')->primary(); // Add back the 'id' column
            $table->unique(['notebook_id', 'content_source_id']); // Restore unique constraint
        });
    }
};
