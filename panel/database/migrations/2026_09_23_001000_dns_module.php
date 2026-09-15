<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // DNS hosting accounts (Cloudflare, Namecheap, ...) used through their APIs
        Schema::create('dns_providers', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30);
            $table->string('alias')->nullable();
            $table->text('credentials');                 // encrypted JSON
            $table->boolean('is_active')->default(true);
            $table->boolean('rate_limit')->default(false); // space requests to respect API limits
            $table->string('account')->nullable();        // account name returned by the API check
            $table->timestamp('checked_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
        });

        // zones found in the accounts; records are always read live from the provider
        Schema::create('dns_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('dns_providers')->cascadeOnDelete();
            $table->string('name');
            $table->string('external_id')->nullable();
            $table->boolean('manageable')->default(true);
            $table->string('note')->nullable();
            $table->unsignedInteger('records_count')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['provider_id', 'name']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_zones');
        Schema::dropIfExists('dns_providers');
    }
};
