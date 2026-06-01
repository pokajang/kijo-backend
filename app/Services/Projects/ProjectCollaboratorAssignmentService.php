<?php

namespace App\Services\Projects;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectCollaboratorAssignmentService
{
    private const ALLOWED_ROLES = ['Leader', 'Assistant', 'Collaborator'];

    public function assignInitialCollaborators(int $projectId, Request $request): void
    {
        if ($projectId <= 0) {
            throw new \InvalidArgumentException('Project is missing.');
        }

        $collaborators = $this->validatedCollaborators($request->input('project_collaborators', []));
        if (empty($collaborators)) {
            return;
        }

        $updatedBy = (int) $request->session()->get('staff_id', 0) ?: null;
        $now = now();

        foreach ($collaborators as $collaborator) {
            DB::table('project_collaborators')->insert([
                'project_id' => $projectId,
                'staff_id' => $collaborator['staff_id'],
                'project_role' => $collaborator['project_role'],
                'role_description' => $collaborator['role_description'],
            ]);

            $nameCode = DB::table('staff_general')
                ->where('staff_id', $collaborator['staff_id'])
                ->value('name_code') ?: "STAFF#{$collaborator['staff_id']}";

            DB::table('project_progress')->insert([
                'project_id' => $projectId,
                'progress_date' => $now->format('Y-m-d'),
                'progress_text' => "Staff {$nameCode} assigned as {$collaborator['project_role']}.",
                'updated_by' => $updatedBy,
                'updated_on' => $now,
            ]);
        }
    }

    /**
     * @return array<int, array{staff_id:int, project_role:string, role_description:?string}>
     */
    public function validatedCollaborators(mixed $collaborators): array
    {
        if ($collaborators === null || $collaborators === []) {
            return [];
        }

        if (! is_array($collaborators)) {
            throw new \InvalidArgumentException('Project collaborators must be an array.');
        }

        $deduped = [];
        $rawLeaderCount = 0;
        foreach ($collaborators as $collaborator) {
            if (! is_array($collaborator)) {
                throw new \InvalidArgumentException('Each project collaborator must be an object.');
            }

            $staffId = (int) ($collaborator['staff_id'] ?? 0);
            $role = (string) ($collaborator['project_role'] ?? '');
            if ($staffId <= 0) {
                throw new \InvalidArgumentException('Each project collaborator must have a valid staff ID.');
            }
            if (! in_array($role, self::ALLOWED_ROLES, true)) {
                throw new \InvalidArgumentException('Project role must be Leader, Assistant, or Collaborator.');
            }
            if ($role === 'Leader') {
                $rawLeaderCount++;
            }

            if (array_key_exists($staffId, $deduped)) {
                continue;
            }

            $description = $collaborator['role_description'] ?? null;
            $description = $description === null ? null : trim((string) $description);

            $deduped[$staffId] = [
                'staff_id' => $staffId,
                'project_role' => $role,
                'role_description' => $description !== '' ? $description : null,
            ];
        }

        $dedupedLeaderCount = 0;
        foreach ($deduped as $collaborator) {
            if ($collaborator['project_role'] === 'Leader') {
                $dedupedLeaderCount++;
            }
        }

        if ($rawLeaderCount > 1) {
            throw new \InvalidArgumentException('Only one project Leader can be assigned.');
        }

        if (! empty($deduped) && $dedupedLeaderCount !== 1) {
            throw new \InvalidArgumentException('Exactly one project Leader must be assigned.');
        }

        $staffIds = array_keys($deduped);
        if (! empty($staffIds)) {
            $activeStaffIds = DB::table('staff_general')
                ->whereIn('staff_id', $staffIds)
                ->whereNull('deleted_at')
                ->where('status', '!=', 'Inactive')
                ->pluck('staff_id')
                ->map(static fn ($staffId): int => (int) $staffId)
                ->all();

            $missingStaffIds = array_values(array_diff($staffIds, $activeStaffIds));
            if (! empty($missingStaffIds)) {
                throw new \InvalidArgumentException(
                    'Project collaborators must be active staff members. Invalid staff ID(s): '
                    .implode(', ', $missingStaffIds).'.'
                );
            }
        }

        return array_values($deduped);
    }
}
