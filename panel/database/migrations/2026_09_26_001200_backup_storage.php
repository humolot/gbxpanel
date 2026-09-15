<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // remote destinations for backups (object storage, cloud drives, FTP/SFTP/WebDAV) through rclone
        Schema::create('backup_storages', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30);
            $table->string('name', 60);
            $table->text('credentials')->nullable();
            $table->string('folder', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('bwlimit', 20)->nullable();
            $table->string('account', 190)->nullable();
            $table->unsignedBigInteger('used_bytes')->nullable();
            $table->unsignedBigInteger('total_bytes')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        // every upload to or download from a storage, with its state and log
        Schema::create('backup_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storage_id')->constrained('backup_storages')->cascadeOnDelete();
            $table->string('direction', 10)->default('upload');
            $table->string('category', 20);
            $table->string('label', 190)->nullable();
            $table->string('local_path', 1024);
            $table->string('remote_path', 1024);
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('parts')->default(0);
            $table->string('sha256', 64)->nullable();
            $table->string('status', 20)->default('queued');
            $table->boolean('delete_local')->default(false);
            $table->unsignedInteger('keep')->nullable();
            $table->json('after')->nullable();
            $table->foreignId('cron_job_id')->nullable()->constrained('cron_jobs')->nullOnDelete();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedInteger('pid')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('progress', 255)->nullable();
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_transfers');
        Schema::dropIfExists('backup_storages');
    }
};
