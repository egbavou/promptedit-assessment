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
        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignUuid('generation_id')->nullable()->constrained();
            $table->string('type');
            $table->bigInteger('amount_nanodollars');
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestampTz('created_at');
            $table->unique(['generation_id', 'type']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_amount_sign_check CHECK (
                (type IN ('purchase', 'hold_release') AND amount_nanodollars > 0)
                OR (type IN ('hold', 'charge') AND amount_nanodollars < 0)
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
