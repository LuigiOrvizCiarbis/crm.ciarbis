<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
            $table->index(['user_id', 'removed_at']);
        });

        // Preserve every existing relationship before the application starts
        // reading memberships. `users.tenant_id` remains as a rollout fallback.
        DB::table('users')->orderBy('id')->each(function (object $user): void {
            if ($user->tenant_id === null) {
                return;
            }

            DB::table('tenant_memberships')->insert([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'branch_id' => $user->branch_id,
                'joined_at' => $user->created_at ?? now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_memberships');
    }
};
