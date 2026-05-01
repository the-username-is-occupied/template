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
        Schema::create('sources', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_space_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64)->unique();
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->string('path');
            $table->timestamps();

            $table->index('user_space_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
