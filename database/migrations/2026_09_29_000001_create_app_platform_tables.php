<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Social sign-in, devices and notifications, and subscriptions.
 *
 * Every step checks before it acts, so a deploy that failed half-way through
 * can simply run it again (MySQL does not roll back DDL).
 */
return new class extends Migration
{
    public function up(): void
    {
        // The subject ("sub") of a verified Google or Apple token. Stable per
        // account for that provider, unlike an email, which can change or be
        // hidden behind Apple's relay.
        Schema::table('devotees', function (Blueprint $table): void {
            if (! Schema::hasColumn('devotees', 'google_id')) {
                $table->string('google_id', 191)->nullable()->unique()->after('passport_code');
            }
            if (! Schema::hasColumn('devotees', 'apple_id')) {
                $table->string('apple_id', 191)->nullable()->unique()->after('google_id');
            }
        });

        // One row per install that can receive a push. A guest's device has
        // no devotee; signing in claims it.
        if (! Schema::hasTable('devotee_devices')) {
            Schema::create('devotee_devices', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('devotee_id')->nullable()->constrained()->nullOnDelete();
                $table->string('token', 512);
                $table->string('token_hash', 64)->unique();
                $table->string('platform', 16);
                $table->string('app_version', 32)->nullable();
                $table->string('locale', 10)->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();
                $table->index(['devotee_id', 'platform']);
            });
        }

        if (! Schema::hasTable('app_notifications')) {
            Schema::create('app_notifications', function (Blueprint $table): void {
                $table->id();
                $table->string('title', 120);
                $table->text('body');
                $table->string('image_url')->nullable();

                // What a tap opens: nothing, a temple, a weekday page, a screen, or a link.
                $table->string('link_type', 16)->default('none');
                $table->string('link_value')->nullable();

                // Who receives it: everyone, one platform, followers of a
                // temple, a home state, or one devotee.
                $table->string('audience', 16)->default('all');
                $table->unsignedBigInteger('audience_id')->nullable();
                $table->string('platform', 16)->nullable();

                // draft | scheduled | sent | failed
                $table->string('status', 16)->default('draft');
                $table->timestamp('scheduled_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->unsignedInteger('push_count')->default(0);
                $table->text('last_error')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['status', 'sent_at']);
                $table->index(['status', 'scheduled_at']);
            });
        }

        if (! Schema::hasTable('app_notification_reads')) {
            Schema::create('app_notification_reads', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('app_notification_id')->constrained()->cascadeOnDelete();
                $table->foreignId('devotee_id')->constrained()->cascadeOnDelete();
                $table->timestamp('read_at');
                $table->unique(['app_notification_id', 'devotee_id']);
            });
        }

        if (! Schema::hasTable('subscription_plans')) {
            Schema::create('subscription_plans', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 40)->unique();
                $table->string('name', 80);
                $table->text('description')->nullable();
                // Paise, never a float: ₹99 is 9900.
                $table->unsignedInteger('price_paise');
                $table->string('currency', 3)->default('INR');
                $table->unsignedSmallInteger('duration_days');
                $table->json('benefits')->nullable();
                $table->string('badge', 40)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('devotee_id')->constrained()->cascadeOnDelete();
                $table->foreignId('subscription_plan_id')->nullable()->constrained()->nullOnDelete();
                $table->string('purpose', 24)->default('subscription');
                $table->string('gateway', 16);
                $table->unsignedInteger('amount_paise');
                $table->string('currency', 3)->default('INR');
                // created | pending | paid | failed | refunded
                $table->string('status', 16)->default('created');
                $table->string('gateway_order_id')->nullable();
                $table->string('gateway_payment_id')->nullable();
                $table->string('failure_reason')->nullable();
                $table->json('meta')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();

                $table->index(['gateway', 'gateway_order_id']);
                $table->index(['devotee_id', 'status']);
            });
        }

        if (! Schema::hasTable('devotee_subscriptions')) {
            Schema::create('devotee_subscriptions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('devotee_id')->constrained()->cascadeOnDelete();
                $table->foreignId('subscription_plan_id')->constrained()->restrictOnDelete();
                // Unique: one payment can never switch on two subscriptions,
                // however many times its webhook is delivered.
                $table->foreignId('payment_id')->nullable()->unique()->constrained()->nullOnDelete();
                $table->timestamp('starts_at');
                $table->timestamp('ends_at');
                $table->timestamp('cancelled_at')->nullable();
                $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('note')->nullable();
                $table->timestamps();

                $table->index(['devotee_id', 'ends_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('devotee_subscriptions');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('subscription_plans');
        Schema::dropIfExists('app_notification_reads');
        Schema::dropIfExists('app_notifications');
        Schema::dropIfExists('devotee_devices');

        Schema::table('devotees', function (Blueprint $table): void {
            $table->dropUnique(['google_id']);
            $table->dropUnique(['apple_id']);
            $table->dropColumn(['google_id', 'apple_id']);
        });
    }
};
