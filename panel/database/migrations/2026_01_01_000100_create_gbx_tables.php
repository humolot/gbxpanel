<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->text('aliases')->nullable();
            $table->string('root_path');
            $table->string('php_version')->nullable(); // null = static site
            $table->string('status')->default('active'); // active | stopped
            $table->boolean('ssl_enabled')->default(false);
            $table->string('ssl_provider')->nullable(); // letsencrypt | custom
            $table->timestamp('ssl_expires_at')->nullable();
            $table->boolean('force_https')->default(false);
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('databases', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('username');
            $table->text('password')->nullable(); // encrypted
            $table->string('host')->default('localhost');
            $table->string('charset')->default('utf8mb4');
            $table->foreignId('website_id')->nullable()->constrained()->nullOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('ftp_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->text('password')->nullable(); // encrypted
            $table->string('path');
            $table->boolean('is_active')->default(true);
            $table->foreignId('website_id')->nullable()->constrained()->nullOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('cron_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('schedule'); // cron expression
            $table->text('command');
            $table->string('run_as')->default('root');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('type')->default('shell');
            $table->string('title');
            $table->longText('script');
            $table->string('status')->default('queued'); // queued | running | success | failed
            $table->integer('exit_code')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 40)->index();
            $table->string('action');
            $table->text('details')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('metrics', function (Blueprint $table) {
            $table->id();
            $table->float('cpu')->default(0);
            $table->float('memory')->default(0);
            $table->float('load1')->default(0);
            $table->float('disk')->default(0);
            $table->unsignedBigInteger('net_rx')->default(0); // bytes/s
            $table->unsignedBigInteger('net_tx')->default(0);
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20); // user | assistant
            $table->longText('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['ai_messages', 'ai_conversations', 'metrics', 'activity_logs', 'tasks', 'cron_jobs', 'ftp_accounts', 'databases', 'websites', 'settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
