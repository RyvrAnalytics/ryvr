<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('timezone')->default('UTC');
            $table->char('currency', 3)->default('USD');
            $table->boolean('mcp_enabled')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->uuid('current_organization_id')->nullable()->after('email');
            $table->boolean('is_instance_admin')->default(false)->after('current_organization_id');
        });

        Schema::create('organization_user', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            // "all": the member's role applies to every project; "granted_only": only to granted projects.
            $table->string('project_access', 20)->default('all');
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('domain');
            // Extra hostnames that may send events (www., staging hosts, apps).
            $table->json('allowed_hostnames')->nullable();
            $table->string('timezone')->default('UTC');
            $table->string('environment', 20)->default('production');
            $table->string('ingest_key', 64)->unique();
            $table->json('settings')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'name']);
        });

        Schema::create('project_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('project_group_project', function (Blueprint $table) {
            $table->foreignUuid('project_group_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->primary(['project_group_id', 'project_id']);
        });

        Schema::create('project_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->timestamps();
            $table->index(['organization_id', 'user_id']);
        });

        Schema::create('exclusion_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            // Null project: the rule applies to every project in the organization.
            $table->foreignUuid('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('field', 20);
            $table->string('operator', 20);
            $table->string('value', 500);
            $table->string('note')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->index(['organization_id', 'project_id']);
        });

        Schema::create('goals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 20);
            $table->string('value', 500);
            $table->timestamps();
        });

        Schema::create('api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('type', 10);
            $table->string('token_hash', 64)->unique();
            $table->string('last_four', 4);
            $table->json('scopes');
            $table->json('project_ids')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 20);
            $table->string('project_access', 20)->default('all');
            $table->json('project_ids')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('daily_salts', function (Blueprint $table) {
            $table->date('day')->primary();
            $table->string('salt', 64);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('organization_id')->nullable()->index();
            $table->string('actor_type', 20);
            $table->string('actor_id')->nullable();
            $table->string('action', 80);
            $table->string('subject_type', 40)->nullable();
            $table->string('subject_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'daily_salts', 'invitations', 'api_keys', 'goals', 'exclusion_rules', 'project_grants',
            'project_group_project', 'project_groups', 'projects', 'organization_user'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['current_organization_id', 'is_instance_admin']));
        Schema::dropIfExists('organizations');
    }
};
