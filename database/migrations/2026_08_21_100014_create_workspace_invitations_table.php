<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();

            // Who it is for. An invitation always names someone: the shareable
            // path is the workspace's own code, not an invitation.
            $table->string('email');
            $table->string('role')->default('member'); // member | admin

            $table->string('status')->default('pending'); // pending | accepted | declined | revoked
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The notification screen reads by email; the admin list by tenant.
            $table->index(['email', 'status']);
            $table->index(['tenant_id', 'status']);
            // No unique key here: someone may be invited, decline, and be
            // invited again, which would collide on any (tenant, email, status)
            // pair. "One pending invitation at a time" is enforced in code,
            // where re-inviting refreshes the existing row.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_invitations');
    }
};
