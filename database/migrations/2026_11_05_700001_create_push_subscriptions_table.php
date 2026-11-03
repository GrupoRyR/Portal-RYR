<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * «Avisos en el celular» (odd/tasks/avisos.md T1): the browsers' push
     * subscriptions, one per device and account. The endpoint is a long
     * URL, so it is unique through its SHA-256 (64 characters) to keep the
     * index short on MariaDB.
     */
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            // Explicit names: every identifier stays under MariaDB's 64 characters.
            $table->foreignId('user_id')
                ->constrained(indexName: 'push_subscriptions_user_id_foreign')
                ->cascadeOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64);
            // The browser's P-256 key (p256dh) and auth secret, base64url.
            $table->string('public_key', 255);
            $table->string('auth_token', 64);
            $table->string('content_encoding', 16)->default('aes128gcm');
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->unique('endpoint_hash', 'push_subscriptions_endpoint_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
