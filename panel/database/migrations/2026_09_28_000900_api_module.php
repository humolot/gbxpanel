<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // API keys: only the hash of the token is stored, so a stolen database cannot call the API
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('prefix', 12)->unique();
            $table->string('token_hash', 64);
            $table->json('scopes');
            $table->text('allowed_ips')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->cascadeOnDelete();
            $table->unsignedInteger('rate_limit')->default(120);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->unsignedBigInteger('requests')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('api_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_key_id')->nullable()->constrained('api_keys')->nullOnDelete();
            $table->string('key_name', 60)->nullable();
            $table->string('method', 10);
            $table->string('path', 255);
            $table->unsignedSmallInteger('status');
            $table->string('ip', 45)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('message', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['created_at', 'id']);
        });

        // outgoing notifications: the panel calls these URLs when something happens
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('url', 1000);
            $table->string('secret', 64);
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_at')->nullable();
            $table->unsignedInteger('failures')->default(0);
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_id')->constrained('webhooks')->cascadeOnDelete();
            $table->string('event', 60);
            $table->json('payload');
            $table->string('status', 12)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('next_try_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_try_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhooks');
        Schema::dropIfExists('api_requests');
        Schema::dropIfExists('api_keys');
    }
};
