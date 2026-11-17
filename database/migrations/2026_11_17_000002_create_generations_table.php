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
        Schema::create('generations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained();
            $table->string('pixelforge_id')->nullable()->unique();
            $table->string('status')->index();
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->jsonb('reference_images')->default('[]');
            $table->bigInteger('estimated_min_nanodollars');
            $table->bigInteger('estimated_max_nanodollars');
            $table->jsonb('rates')->nullable();
            $table->jsonb('usage')->nullable();
            $table->bigInteger('provider_cost_nanodollars')->nullable();
            $table->bigInteger('price_nanodollars')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->text('image_url')->nullable();
            $table->timestampTz('image_url_expires_at')->nullable();
            $table->string('image_path')->nullable();
            $table->unsignedInteger('submit_attempts')->default(0);
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'created_at']);
        });

        DB::statement('ALTER TABLE generations ADD CONSTRAINT generations_submit_attempts_check CHECK (submit_attempts >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
