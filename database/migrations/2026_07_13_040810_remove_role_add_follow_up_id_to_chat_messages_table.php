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
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('role');
            $table->uuid('follow_up_id')->nullable()->after('tech_account_id');
            $table->foreign('follow_up_id')->references('id')->on('chat_messages')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->enum('role', ['user', 'assistant'])->after('tech_account_id');
            $table->dropForeign(['follow_up_id']);
            $table->dropColumn('follow_up_id');
        });
    }
};
