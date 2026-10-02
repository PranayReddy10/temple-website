<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Who is collecting the money, proven.
 *
 * Before a temple can take money in the app (paid sevas, paid event tickets,
 * the online hundi) the person asking gives their Aadhaar, a proof that the
 * temple is theirs to represent, and a photo of themselves. Staff check it
 * with the bank details and approve; until then nobody can pay that temple.
 * The files live on the private disk and only staff can open them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_payout_accounts', function (Blueprint $table) {
            $table->string('kyc_name', 120)->nullable()->after('upi_id');
            $table->text('aadhaar_number')->nullable()->after('kyc_name');
            $table->string('aadhaar_last4', 4)->nullable()->after('aadhaar_number');
            $table->string('kyc_disk', 40)->nullable()->after('aadhaar_last4');
            $table->string('aadhaar_front_path')->nullable()->after('kyc_disk');
            $table->string('aadhaar_back_path')->nullable()->after('aadhaar_front_path');
            $table->string('temple_proof_path')->nullable()->after('aadhaar_back_path');
            $table->string('temple_proof_kind', 60)->nullable()->after('temple_proof_path');
            $table->string('person_photo_path')->nullable()->after('temple_proof_kind');
            $table->timestamp('kyc_submitted_at')->nullable()->after('person_photo_path');
            $table->text('rejection_reason')->nullable()->after('verified_by');
        });
    }

    public function down(): void
    {
        Schema::table('temple_payout_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'kyc_name', 'aadhaar_number', 'aadhaar_last4', 'kyc_disk', 'aadhaar_front_path', 'aadhaar_back_path',
                'temple_proof_path', 'temple_proof_kind', 'person_photo_path', 'kyc_submitted_at', 'rejection_reason',
            ]);
        });
    }
};
