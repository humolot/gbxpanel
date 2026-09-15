<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // private registries used to pull and push images
        Schema::create('docker_registries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url');                  // docker.io, ghcr.io, registry.example.com:5000
            $table->string('username')->nullable();
            $table->text('password')->nullable();   // encrypted
            $table->string('namespace')->nullable();
            $table->string('remark')->nullable();
            $table->timestamps();
        });

        // compose templates offered when a project is created
        Schema::create('docker_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('remark')->nullable();
            $table->text('content');
            $table->text('env')->nullable();
            $table->timestamps();
        });

        // notes shown next to containers and compose projects
        Schema::create('docker_notes', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20); // container, project
            $table->string('ref');
            $table->string('note', 255);
            $table->timestamps();
            $table->unique(['type', 'ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('docker_notes');
        Schema::dropIfExists('docker_templates');
        Schema::dropIfExists('docker_registries');
    }
};
