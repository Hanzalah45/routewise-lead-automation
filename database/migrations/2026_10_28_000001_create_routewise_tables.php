<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('agent')->after('password');
        });

        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('secret');                       // encrypted at rest
            $table->boolean('active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('owners', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('regions')->nullable();        // comma separated ISO country codes; empty = anywhere
            $table->string('segments')->nullable();       // comma separated: enterprise, smb
            $table->unsignedSmallInteger('capacity_per_day')->default(20);
            $table->boolean('active')->default(true);
            $table->string('crm_owner_id')->nullable();
            $table->timestamps();
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('lead_source_id')->constrained('lead_sources');
            $table->string('external_id')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('company')->nullable();
            $table->string('job_title')->nullable();
            $table->string('country', 2)->nullable();
            $table->text('message')->nullable();
            $table->boolean('consent')->nullable();
            $table->json('raw_payload')->nullable();
            $table->string('email_normalized')->nullable()->index();
            $table->string('phone_e164', 20)->nullable()->index();
            $table->string('company_domain')->nullable();
            $table->boolean('is_free_email')->default(false);
            $table->smallInteger('score')->default(0);
            $table->json('score_breakdown')->nullable();
            $table->string('tier', 10)->default('cold')->index();
            $table->string('status', 20)->default('received')->index();
            $table->foreignId('owner_id')->nullable()->constrained('owners')->nullOnDelete();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->string('crm_contact_id')->nullable();
            $table->string('crm_deal_id')->nullable();
            $table->unsignedSmallInteger('sync_attempts')->default(0);
            $table->timestamp('next_retry_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestamp('routed_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();

            $table->unique(['lead_source_id', 'external_id']);
        });

        Schema::create('lead_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('message');
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_source_id')->nullable()->constrained('lead_sources')->nullOnDelete();
            $table->string('source_slug', 80);
            $table->string('ip', 45)->nullable();
            $table->string('outcome', 30)->index();       // accepted, duplicate_delivery, invalid_signature, expired, validation_failed, unknown_source, inactive_source, too_large
            $table->unsignedSmallInteger('http_status');
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payload_sha256', 64)->nullable();
            $table->unsignedInteger('payload_bytes')->default(0);
            $table->string('error')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('crm_sync_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->string('outcome', 20);                // success, retry, permanent_failure
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('error')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('scoring_rules', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('description');
            $table->smallInteger('points');
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('channel', 20);                // slack, email
            $table->string('recipient');
            $table->string('subject');
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
        });

        // Bundled Mock CRM (stand-in for HubSpot / Pipedrive / Salesforce)
        Schema::create('mock_crm_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique();
            $table->string('email')->unique();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('company')->nullable();
            $table->string('job_title')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('owner_id')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });

        Schema::create('mock_crm_deals', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique();
            $table->string('contact_external_id');
            $table->string('name');
            $table->string('stage')->default('new');
            $table->unsignedInteger('amount')->nullable();
            $table->string('owner_id')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamps();
        });

        Schema::create('mock_crm_requests', function (Blueprint $table) {
            $table->id();
            $table->string('method', 8);
            $table->string('path');
            $table->unsignedSmallInteger('status');
            $table->string('idempotency_key')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        foreach (['mock_crm_requests', 'mock_crm_deals', 'mock_crm_contacts', 'settings', 'outbox_messages', 'scoring_rules', 'crm_sync_attempts', 'webhook_deliveries', 'lead_events', 'leads', 'owners', 'lead_sources'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('role'));
    }
};
