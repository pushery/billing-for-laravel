<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which declaration an exported run is.
 *
 * The recapitulative statement is kept beside the one-stop-shop return, with the same bytes, fingerprint and
 * append-only rule, so the row has to say which of the two it is. Every row written before this is a
 * one-stop-shop run, because that was the only declaration exported, and the default says exactly that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_tax_return_exports', function (Blueprint $table): void {
            $table->string('return_type')->default('one_stop_shop')->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('billing_tax_return_exports', function (Blueprint $table): void {
            $table->dropColumn('return_type');
        });
    }
};
