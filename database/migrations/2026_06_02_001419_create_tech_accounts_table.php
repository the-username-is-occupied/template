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
        Schema::create('tech_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('pool_type')->index();
            $table->string('status')->index();
            $table->string('cookie_path')->nullable();
            $table->unsignedInteger('notebooks_count')->default(0);
            $table->unsignedInteger('chats_today')->default(0);
            $table->timestamp('chats_reset_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'chats_today']);
            $table->index(['status', 'notebooks_count']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tech_accounts');
    }
};
