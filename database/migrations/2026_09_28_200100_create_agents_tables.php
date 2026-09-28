<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Shared agents: a provider and model instead of a connection (connections are local).
        Schema::create('agents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->jsonb('avatar');
            $table->string('provider', 32);
            $table->string('model', 200)->nullable();
            $table->text('role')->default('');
            $table->jsonb('params')->default('{}');
            $table->string('permission_policy', 16)->default('ask');
            $table->jsonb('tags')->default('[]');
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz(3);
            $table->index('workspace_id');
        });

        // Workspace tool servers are http only; secret header values never reach the hub.
        Schema::create('tool_servers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('transport', 8)->default('http');
            $table->text('url');
            $table->jsonb('headers')->default('{}');
            $table->boolean('enabled')->default(true);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz(3);
            $table->index('workspace_id');
        });
        DB::statement("ALTER TABLE tool_servers ADD CONSTRAINT tool_servers_http_only CHECK (transport = 'http')");

        Schema::create('agent_tool_servers', function (Blueprint $table) {
            $table->foreignUlid('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('tool_server_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->primary(['agent_id', 'tool_server_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tool_servers');
        Schema::dropIfExists('tool_servers');
        Schema::dropIfExists('agents');
    }
};
