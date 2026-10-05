<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A register's verdict on a tax ID can come back after a different one, and then it is an answer of its own.
 *
 * The unique index held one row per tax ID and verdict, so a number the register confirmed again after rejecting
 * it could not be kept: the earlier `verified` row already existed, and the rejection stayed the latest deciding
 * answer. `follows` names the row of the same tax ID that an answer changed, `0` for the first one, and the index
 * moves onto it. The rows kept before are one per tax ID and verdict, so they stay distinct with `0`.
 *
 * Rolling back restores the index on the verdict alone, and fails once a verdict has come back, because that index
 * cannot hold both answers and they are kept as evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_tax_id_verifications', function (Blueprint $table): void {
            $table->unsignedBigInteger('follows')->default(0);
        });

        Schema::table('billing_tax_id_verifications', function (Blueprint $table): void {
            $table->dropUnique('billing_tax_id_verifications_answer_unique');
            $table->unique(['provider', 'tax_id_reference', 'status', 'follows'], 'billing_tax_id_verifications_answer_unique');
        });
    }

    public function down(): void
    {
        Schema::table('billing_tax_id_verifications', function (Blueprint $table): void {
            $table->dropUnique('billing_tax_id_verifications_answer_unique');
            $table->unique(['provider', 'tax_id_reference', 'status'], 'billing_tax_id_verifications_answer_unique');
        });

        Schema::table('billing_tax_id_verifications', function (Blueprint $table): void {
            $table->dropColumn('follows');
        });
    }
};
