<?php

use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // A reusable code the owner can copy and send. Nullable first so
            // existing rows can be backfilled with distinct values.
            $table->string('invite_code', 12)->nullable()->unique()->after('slug');
        });

        Tenant::withoutEvents(function () {
            Tenant::whereNull('invite_code')->each(
                fn (Tenant $tenant) => $tenant->forceFill(['invite_code' => Tenant::generateInviteCode()])->save()
            );
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['invite_code']);
            $table->dropColumn('invite_code');
        });
    }
};
