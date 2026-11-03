<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The portal's copy of the tasks and approvals another app publishes
     * (App\Models\WorkItem, docs/contrato-tareas-aprobaciones.md §3). Only
     * the fields of the contract are stored. A deleted item keeps only its
     * version for 30 days (`deleted_at`), so the content columns are
     * nullable.
     *
     * `external_id` is case-sensitive (§3.1): binary on MySQL, whose default
     * collation would make `TASK-1` and `task-1` the same item. SQLite
     * already compares text in binary.
     */
    public function up(): void
    {
        Schema::create('work_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $externalId = $table->string('external_id', 190);

            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $externalId->collation('utf8mb4_bin');
            }

            $table->string('kind', 16)->nullable();
            $table->string('title', 140)->nullable();
            $table->string('status', 16)->nullable();
            $table->timestamp('due_at')->nullable();
            $table->string('url', 2048)->nullable();
            $table->boolean('completable_from_portal')->default(false);
            $table->unsignedBigInteger('version');
            $table->string('assignee_tenant_id', 36)->nullable();
            $table->string('assignee_oid', 36)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['integration_id', 'external_id']);
            $table->index(['assignee_tenant_id', 'assignee_oid', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_items');
    }
};
