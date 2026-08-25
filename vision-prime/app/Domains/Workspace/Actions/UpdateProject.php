<?php

declare(strict_types=1);

namespace App\Domains\Workspace\Actions;

use App\Domains\Audit\Actions\RecordAuditLog;
use App\Domains\Workspace\Models\Client;
use App\Domains\Workspace\Models\Project;

class UpdateProject
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function handle(Project $project, Client $client, string $name, ?string $objective): Project
    {
        $before = ['name' => $project->name, 'objective' => $project->objective, 'client_id' => $project->client_id];
        $project->update(['client_id' => $client->getKey(), 'name' => $name, 'objective' => $objective]);
        $this->audit->handle(action: 'project.updated', subject: $project, before: $before, after: ['name' => $project->name, 'objective' => $project->objective, 'client_id' => $project->client_id]);

        return $project->refresh();
    }
}
