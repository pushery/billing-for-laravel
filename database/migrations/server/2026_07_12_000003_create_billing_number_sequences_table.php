<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unique, monotonic number sources (per scope, e.g. per series and year) for legally-immutable
 * document numbers. Each scope holds the next value; it is handed out under a row lock so two
 * concurrent callers never receive the same number and the counter only advances. The guarantee is
 * uniqueness, not gap-freedom — a caller's surrounding transaction may roll back after a number was
 * drawn, and that gap is harmless; a duplicate is what cannot happen. Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_number_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('scope')->unique();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_number_sequences');
    }
};
