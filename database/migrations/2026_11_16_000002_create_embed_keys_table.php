<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('embed_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('public_id', 40)->unique();
            $table->string('name');
            // Encrypted with the app key (not hashed): RYVR must recompute HMACs with it.
            $table->text('secret');
            $table->string('last_four', 4);
            $table->string('alg', 10)->default('HS256');
            $table->json('allowed_origins');
            $table->json('project_ids')->nullable();
            $table->json('widgets');
            $table->unsignedInteger('max_ttl_seconds')->default(600);
            $table->unsignedInteger('max_period_days')->default(400);
            $table->unsignedInteger('rate_limit_per_minute')->default(600);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('embed_keys');
    }
};
