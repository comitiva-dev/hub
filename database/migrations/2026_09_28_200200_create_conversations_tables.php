<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('agent_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200)->nullable();
            // placeholder (first line of the first message) | auto (generated) | user (renamed)
            $table->string('title_source', 16)->nullable();
            $table->string('status', 24)->default('idle');
            $table->boolean('archived')->default(false);
            $table->timestampTz('last_activity_at', 3);
            // Event revision: +1 with every message event (ADR 0008).
            $table->unsignedBigInteger('rev')->default(0);
            $table->jsonb('pending_approval')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz(3);
            $table->index(['workspace_id', 'archived', 'last_activity_at']);
            $table->index('agent_id');
        });
        DB::statement("ALTER TABLE conversations ADD CONSTRAINT conversations_status_check CHECK (status IN ('idle', 'running', 'awaiting-approval', 'error'))");

        Schema::create('messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('conversation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('seq');
            $table->string('role', 16);
            $table->jsonb('content')->default('[]');
            $table->string('status', 16);
            $table->jsonb('error')->nullable();
            $table->foreignUlid('author_id')->nullable()->constrained('users')->nullOnDelete();
            // Text blocks and attachment names, set once the message stops streaming.
            $table->text('search_text')->nullable();
            $table->timestampsTz(3);
            $table->unique(['conversation_id', 'seq']);
        });
        DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_status_check CHECK (status IN ('streaming', 'complete', 'cancelled', 'error'))");

        // A turn a desktop is running. One active run per conversation: the lock
        // every desktop takes before it runs anything (ADR 0017).
        Schema::create('runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('reply_message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('token_id')->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('last_batch')->default(0);
            $table->timestampTz('lease_expires_at', 3);
            $table->timestampTz('started_at', 3);
            $table->timestampTz('finished_at', 3)->nullable();
        });
        DB::statement("CREATE UNIQUE INDEX runs_one_active_per_conversation ON runs (conversation_id) WHERE status = 'active'");
        DB::statement("CREATE INDEX runs_active_lease ON runs (lease_expires_at) WHERE status = 'active'");

        Schema::create('conversation_reads', function (Blueprint $table) {
            $table->foreignUlid('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('last_read_seq')->default(0);
            $table->primary(['conversation_id', 'user_id']);
        });

        // History of decisions. Allow-always stays on each desktop: it is about that machine.
        Schema::create('tool_approvals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tool_use_id');
            $table->string('tool_server_id');
            $table->string('tool_name');
            $table->jsonb('input');
            $table->string('decision', 16);
            $table->foreignUlid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at', 3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_approvals');
        Schema::dropIfExists('conversation_reads');
        Schema::dropIfExists('runs');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
