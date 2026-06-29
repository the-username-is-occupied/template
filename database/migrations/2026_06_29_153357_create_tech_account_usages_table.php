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
        Schema::create('tech_account_usages', function (Blueprint $table) {
            $table->id();
            $table->uuid('tech_account_id');
            $table->date('date');
            $table->unsignedInteger('count')->default(0);
            $table->timestamps();

            $table->unique(['tech_account_id', 'date']);
            $table->foreign('tech_account_id')->references('id')->on('tech_accounts')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tech_account_usages');
    }
};
