<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connections of the Claude connector (App\Models\McpConnection): one per
 * OAuth client and approving account, and the accounts it may use, each
 * confirmed with Microsoft when connecting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('oauth_client_id')->constrained('oauth_clients')->cascadeOnDelete();
            // The account that approved the connection: the one Passport's
            // tokens are issued to.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->index(['oauth_client_id', 'user_id', 'revoked_at']);
        });

        Schema::create('mcp_connection_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mcp_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('confirmed_at');

            $table->unique(['mcp_connection_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_connection_accounts');
        Schema::dropIfExists('mcp_connections');
    }
};
