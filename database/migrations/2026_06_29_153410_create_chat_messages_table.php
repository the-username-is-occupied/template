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
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('chat_id');
            $table->uuid('tech_account_id')->nullable();
            $table->enum('role', ['user', 'assistant']);
            $table->text('content')->nullable();
            $table->json('result')->nullable();
            $table->boolean('is_success')->nullable();
            $table->timestamps();

            $table->foreign('chat_id')->references('id')->on('chats')->cascadeOnDelete();
            $table->foreign('tech_account_id')->references('id')->on('tech_accounts')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
