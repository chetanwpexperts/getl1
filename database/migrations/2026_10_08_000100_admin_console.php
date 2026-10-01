<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Two-step login for GetL1 staff. Secret and recovery codes are stored encrypted.
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('is_platform_admin');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });

        // Early-access and demo requests from the public website.
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('company', 160);
            $table->string('email', 190);
            $table->string('phone', 20)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('interest', 20)->default('buyer'); // buyer | supplier
            $table->string('monthly_spend', 30)->nullable();
            $table->text('message')->nullable();
            $table->string('source', 60)->nullable();          // utm_source or referrer host
            $table->string('status', 20)->default('new');      // new | contacted | converted | closed
            $table->text('notes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
