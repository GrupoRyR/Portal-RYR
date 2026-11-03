<?php

namespace Database\Factories;

use App\Models\Integration;
use App\Models\WorkItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkItem>
 */
class WorkItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'integration_id' => Integration::factory(),
            'external_id' => (string) Str::uuid(),
            'kind' => WorkItem::KIND_TASK,
            'title' => 'Revisar '.fake()->word(),
            'status' => WorkItem::STATUS_PENDING,
            'due_at' => null,
            'url' => 'https://tareas.segurex.com.co/tareas/'.fake()->numberBetween(1, 99999),
            'completable_from_portal' => true,
            'version' => 1,
            'assignee_tenant_id' => (string) Str::uuid(),
            'assignee_oid' => (string) Str::uuid(),
        ];
    }
}
