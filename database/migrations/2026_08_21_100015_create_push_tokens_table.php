<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // An Expo push token: one per install, reused across launches.
            $table->string('token')->unique();
            $table->string('platform')->nullable();    // ios | android | web
            $table->string('device_name')->nullable(); // "Rady's iPad", for the settings list
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_active_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            // Digest preferences live with the account so every device agrees.
            $table->boolean('digest_enabled')->default(true)->after('timezone');
            $table->unsignedTinyInteger('digest_morning_hour')->default(8)->after('digest_enabled');
            $table->unsignedTinyInteger('digest_evening_hour')->default(19)->after('digest_morning_hour');
        });

        // One delivery per user, per kind, per day — the sender runs hourly and
        // must never send the same digest twice.
        Schema::create('digest_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // morning | evening
            $table->date('sent_for');
            $table->unsignedSmallInteger('devices')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'kind', 'sent_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digest_deliveries');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['digest_enabled', 'digest_morning_hour', 'digest_evening_hour']);
        });

        Schema::dropIfExists('push_tokens');
    }
};
