<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app notifications (the bell), browser push subscriptions, and per-user preferences.
 *
 * - notifications: Laravel's standard table plus organization_id. data holds {category, title, body, url, org_id}.
 * - push_subscriptions: one row per browser/device that allowed notifications (Web Push).
 *   endpoint_hash keeps endpoints unique without indexing a long URL.
 * - users.notification_prefs: {category: {push: bool}}; missing means on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            // The company the alert is about; hidden once the person leaves that company.
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('endpoint_hash', 64)->unique();
            $table->text('endpoint');
            $table->string('public_key', 255);
            $table->string('auth_token', 255);
            $table->string('content_encoding', 20)->default('aes128gcm');
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_prefs')->nullable();
        });

        // "Auction is live" is sent once, whichever path opened the auction (tick or first bid).
        Schema::table('auctions', function (Blueprint $table) {
            $table->timestamp('opened_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('auctions', fn (Blueprint $table) => $table->dropColumn('opened_notified_at'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('notification_prefs'));
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('notifications');
    }
};
