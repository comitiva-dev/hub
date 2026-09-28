<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reported by the desktop that ran the turn, cost frozen there (ADR 0011).
        // No foreign keys to agents or conversations: usage outlives them.
        Schema::create('usage_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('agent_id', 26);
            $table->string('conversation_id', 26);
            $table->string('message_id', 26)->nullable();
            $table->string('provider', 32);
            $table->string('model', 200);
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('cache_read_tokens')->nullable();
            $table->unsignedBigInteger('cache_write_tokens')->nullable();
            $table->boolean('estimated')->default(false);
            $table->double('cost_usd')->nullable();
            $table->string('cost_source', 16)->nullable();
            $table->boolean('cost_estimated')->default(false);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestampTz('created_at', 3);
            $table->index(['workspace_id', 'created_at']);
            $table->index('conversation_id');
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 255);
            $table->string('media_type', 255);
            $table->unsignedInteger('size');
            $table->string('sha256', 64);
            $table->string('path');
            $table->timestampTz('created_at', 3);
            $table->index('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('usage_records');
    }
};
