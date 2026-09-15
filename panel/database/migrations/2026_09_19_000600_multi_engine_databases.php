<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // remote database servers (MySQL, PostgreSQL, MongoDB, SQL Server, Redis, Qdrant)
        Schema::create('db_servers', function (Blueprint $table) {
            $table->id();
            $table->string('engine', 20);
            $table->string('name');
            $table->string('host');
            $table->unsignedInteger('port');
            $table->string('username')->nullable();
            $table->text('password')->nullable(); // encrypted
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->index('engine');
        });

        Schema::table('databases', function (Blueprint $table) {
            $table->dropUnique('databases_name_unique');
        });

        Schema::table('databases', function (Blueprint $table) {
            $table->string('engine', 20)->default('mysql')->after('id');
            $table->foreignId('server_id')->nullable()->after('engine')->constrained('db_servers')->cascadeOnDelete();
            $table->index(['engine', 'name']);
        });

        // databases deleted from the panel are dumped first and can be restored for 7 days
        Schema::create('database_recycle', function (Blueprint $table) {
            $table->id();
            $table->string('engine', 20);
            $table->string('name');
            $table->string('username')->nullable();
            $table->text('password')->nullable(); // encrypted
            $table->string('host')->nullable();
            $table->string('charset')->nullable();
            $table->string('file');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_recycle');
        Schema::table('databases', function (Blueprint $table) {
            $table->dropIndex(['engine', 'name']);
            $table->dropConstrainedForeignId('server_id');
            $table->dropColumn('engine');
        });
        Schema::table('databases', function (Blueprint $table) {
            $table->unique('name');
        });
        Schema::dropIfExists('db_servers');
    }
};
