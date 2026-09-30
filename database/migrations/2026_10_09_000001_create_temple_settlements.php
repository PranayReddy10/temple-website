<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paying temples what devotees paid for their sevas.
 *
 * Devotees pay the platform's gateway; the platform then settles with each
 * temple. A settlement gathers a temple's paid bookings up to a day, keeps
 * the platform fee, and is marked paid once the transfer has been made with
 * its bank reference (UTR). A booking belongs to at most one settlement, so
 * nothing is paid twice.
 *
 * Every step checks before it acts, so a deploy that failed half-way through
 * can simply run it again (MySQL does not roll back DDL).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('temple_payout_accounts')) {
            Schema::create('temple_payout_accounts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('temple_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('account_name', 120)->nullable();
                // Encrypted with the app key: text, not a sized string.
                $table->text('account_number')->nullable();
                $table->string('account_last4', 4)->nullable();
                $table->string('ifsc', 11)->nullable();
                $table->string('bank_name', 120)->nullable();
                $table->string('upi_id', 120)->nullable();
                // Null: the platform's default fee applies. Staff set it.
                $table->decimal('platform_fee_percent', 5, 2)->nullable();
                // Cleared whenever the temple changes the details, so money
                // never follows an unchecked change of account.
                $table->timestamp('verified_at')->nullable();
                $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('temple_settlements')) {
            Schema::create('temple_settlements', function (Blueprint $table): void {
                $table->id();
                $table->string('reference', 16)->unique();
                $table->foreignId('temple_id')->constrained()->cascadeOnDelete();
                // Seva days covered: from the earliest booking to this day.
                $table->date('period_from')->nullable();
                $table->date('period_to');
                $table->unsignedInteger('bookings_count')->default(0);
                $table->unsignedBigInteger('gross_paise')->default(0);
                $table->decimal('fee_percent', 5, 2)->default(0);
                $table->unsignedBigInteger('fee_paise')->default(0);
                $table->unsignedBigInteger('net_paise')->default(0);
                $table->string('currency', 3)->default('INR');

                // pending | paid | cancelled
                $table->string('status', 16)->default('pending');
                // Where the money went, as the details read when prepared.
                $table->text('payout_to')->nullable();
                // bank | upi | cheque | cash
                $table->string('method', 16)->nullable();
                $table->string('transaction_ref', 80)->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->string('note', 500)->nullable();
                $table->string('cancel_reason')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['temple_id', 'status']);
            });
        }

        if (! Schema::hasColumn('puja_bookings', 'settlement_id')) {
            Schema::table('puja_bookings', function (Blueprint $table): void {
                $table->foreignId('settlement_id')->nullable()->after('payment_id')->constrained('temple_settlements')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('puja_bookings', 'settlement_id')) {
            Schema::table('puja_bookings', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('settlement_id');
            });
        }

        Schema::dropIfExists('temple_settlements');
        Schema::dropIfExists('temple_payout_accounts');
    }
};
