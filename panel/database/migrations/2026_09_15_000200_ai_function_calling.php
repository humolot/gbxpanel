<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->string('model')->nullable()->after('title');
        });

        Schema::table('ai_messages', function (Blueprint $table) {
            $table->longText('content')->nullable()->change();
            $table->json('tool_calls')->nullable()->after('content');
            $table->string('tool_call_id')->nullable()->after('tool_calls');
            $table->string('tool_name')->nullable()->after('tool_call_id');
            $table->json('images')->nullable()->after('tool_name');
            $table->json('meta')->nullable()->after('images');
        });

        Schema::create('ai_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_message_id')->constrained()->cascadeOnDelete();
            $table->string('tool_call_id');
            $table->string('tool');
            $table->json('arguments')->nullable();
            $table->string('status')->default('pending'); // pending | approved | rejected | done | failed
            $table->longText('result')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_actions');
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dropColumn(['tool_calls', 'tool_call_id', 'tool_name', 'images', 'meta']);
        });
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropColumn('model');
        });
    }
};
