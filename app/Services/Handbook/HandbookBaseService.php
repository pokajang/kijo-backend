<?php

namespace App\Services\Handbook;

use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

abstract class HandbookBaseService
{
    public function __construct(protected AuditLogService $auditLog) {}

    protected function currentVersion(): object
    {
        $versions = DB::table('hr_handbook_versions')
            ->where('is_current', 1)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get();

        if ($versions->count() > 1) {
            report(new \RuntimeException('Handbook current-version invariant violated: multiple current versions exist.'));
        }

        if ($versions->isNotEmpty()) {
            return $versions->first();
        }

        $contentPath = database_path('seeders/data/handbook_v2_2024_01_05.json');
        $content = is_file($contentPath) ? file_get_contents($contentPath) : null;
        $now = now();

        $payload = [
            'version_label' => 'V2 - 2024-01-05',
            'content_json' => is_string($content) && trim($content) !== ''
                ? $content
                : '{"title":"AMIOSH Employee Handbook","chapters":[]}',
            'change_summary' => 'Initial migrated handbook snapshot.',
            'published_by_staff_id' => null,
            'published_by_name_code' => 'SYSTEM',
            'published_at' => $now,
            'is_current' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($this->hasCurrentVersionGuard()) {
            $payload['current_version_guard'] = 1;
        }

        $versionId = DB::table('hr_handbook_versions')->insertGetId($payload);

        $this->insertChangeLog(null, $versionId, 'migrate', 'Initial handbook snapshot created.', $now);

        if (Schema::hasColumn('hr_handbook_sign', 'handbook_version_id')) {
            DB::table('hr_handbook_sign')
                ->whereNull('handbook_version_id')
                ->update(['handbook_version_id' => $versionId]);
        }

        return DB::table('hr_handbook_versions')->where('id', $versionId)->first();
    }

    protected function hasCurrentVersionGuard(): bool
    {
        return Schema::hasColumn('hr_handbook_versions', 'current_version_guard');
    }

    protected function currentVersionOffPayload($now): array
    {
        $payload = [
            'is_current' => false,
            'updated_at' => $now,
        ];

        if ($this->hasCurrentVersionGuard()) {
            $payload['current_version_guard'] = null;
        }

        return $payload;
    }

    protected function currentVersionOnPayload($now): array
    {
        $payload = [
            'is_current' => true,
            'updated_at' => $now,
        ];

        if ($this->hasCurrentVersionGuard()) {
            $payload['current_version_guard'] = 1;
        }

        return $payload;
    }

    protected function assertSingleCurrentVersion(): void
    {
        $count = DB::table('hr_handbook_versions')->where('is_current', 1)->count();

        if ((int) $count !== 1) {
            throw new \RuntimeException("Handbook current-version invariant violated: {$count} current versions exist.");
        }
    }

    protected function formatVersion(object $version): array
    {
        $content = json_decode((string) $version->content_json, true);
        if (! is_array($content)) {
            $content = ['title' => 'AMIOSH Employee Handbook', 'chapters' => []];
        }

        return [
            'id' => (int) $version->id,
            'version_label' => (string) $version->version_label,
            'content' => $content,
            'change_summary' => $version->change_summary,
            'published_by_staff_id' => $version->published_by_staff_id,
            'published_by_name_code' => $version->published_by_name_code,
            'published_at' => $version->published_at,
            'is_current' => (bool) $version->is_current,
            'content_sha256' => $version->content_sha256
                ?? hash('sha256', (string) $version->content_json),
            'acknowledgement_schema_version' => isset($version->acknowledgement_schema_version)
                ? (int) $version->acknowledgement_schema_version
                : (isset($content['acknowledgement']['schemaVersion'])
                    ? (int) $content['acknowledgement']['schemaVersion']
                    : null),
            'acknowledgement_sha256' => $version->acknowledgement_sha256
                ?? $this->acknowledgements()->hash($content['acknowledgement'] ?? null),
        ];
    }

    protected function activeDraft(int $baseVersionId, bool $lock = false): ?object
    {
        if (! Schema::hasTable('hr_handbook_drafts')) {
            return null;
        }

        $query = DB::table('hr_handbook_drafts')
            ->where('base_handbook_version_id', $baseVersionId)
            ->where('status', 'active')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    protected function formatDraft(?object $draft): ?array
    {
        if (! $draft || ! Schema::hasTable('hr_handbook_draft_changes')) {
            return null;
        }

        $content = json_decode((string) $draft->content_json, true);
        if (! is_array($content)) {
            $content = ['title' => 'AMIOSH Employee Handbook', 'chapters' => []];
        }

        $changes = DB::table('hr_handbook_draft_changes')
            ->where('handbook_draft_id', $draft->id)
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->values();

        return [
            'id' => (int) $draft->id,
            'base_handbook_version_id' => (int) $draft->base_handbook_version_id,
            'content' => $content,
            'changes_count' => $changes->count(),
            'changes' => $changes,
            'updated_by_staff_id' => $draft->updated_by_staff_id === null ? null : (int) $draft->updated_by_staff_id,
            'updated_by_name_code' => $draft->updated_by_name_code,
            'updated_at' => $draft->updated_at,
        ];
    }

    protected function currentSignature(Request $request, int $versionId): array
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return [
                'signed' => false,
                'signed_at' => null,
                'full_name' => null,
            ];
        }

        $signature = DB::table('hr_handbook_sign')
            ->where('staff_id', $staffId)
            ->where('handbook_version_id', $versionId)
            ->orderByDesc('signed_at')
            ->orderByDesc('id')
            ->first(['full_name', 'signed_at']);

        return [
            'signed' => $signature !== null,
            'signed_at' => $signature?->signed_at,
            'full_name' => $signature?->full_name,
        ];
    }

    protected function canManage(Request $request): bool
    {
        $roles = (array) $request->session()->get('roles', []);

        return collect($roles)->contains(fn ($role) => in_array(
            strtolower(trim((string) $role)),
            ['system admin', 'hr', 'manager'],
            true,
        ));
    }

    protected function canViewEvidence(Request $request): bool
    {
        $roles = (array) $request->session()->get('roles', []);

        return collect($roles)->contains(fn ($role) => in_array(
            strtolower(trim((string) $role)),
            ['system admin', 'hr'],
            true,
        ));
    }

    protected function acknowledgements(): HandbookAcknowledgementService
    {
        return app(HandbookAcknowledgementService::class);
    }

    protected function nextVersionLabel(): string
    {
        $max = 0;
        foreach (DB::table('hr_handbook_versions')->pluck('version_label') as $label) {
            if (preg_match('/^V(\d+)/i', (string) $label, $matches)) {
                $max = max($max, (int) $matches[1]);
            }
        }

        return 'V'.($max + 1).' - '.now()->toDateString();
    }

    protected function contentValidator(Request $request): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($request->all(), [
            'content' => ['required', 'array'],
            'content.title' => ['required', 'string', 'max:255'],
            'content.chapters' => ['required', 'array', 'min:1'],
            'content.chapters.*.id' => ['required', 'string', 'max:80'],
            'content.chapters.*.title' => ['required', 'string', 'max:255'],
            'content.chapters.*.bodyHtml' => ['required', 'string'],
            'content.acknowledgement' => ['sometimes', 'array'],
            'content.acknowledgement.schemaVersion' => ['required_with:content.acknowledgement', 'integer'],
            'content.acknowledgement.profileFields' => ['required_with:content.acknowledgement', 'array'],
            'content.acknowledgement.declarations' => ['required_with:content.acknowledgement', 'array'],
            'content.acknowledgement.declarations.*.id' => ['required', 'string', 'max:80'],
            'content.acknowledgement.declarations.*.title' => ['required', 'string', 'max:255'],
            'content.acknowledgement.declarations.*.body' => ['required', 'string'],
            'content.acknowledgement.declarations.*.required' => ['required', 'boolean'],
            'content.acknowledgement.declarations.*.order' => ['required', 'integer', 'min:1'],
            'change_summary' => ['required', 'string', 'max:2000'],
            'section_id' => ['nullable', 'string', 'max:80'],
            'section_title' => ['nullable', 'string', 'max:255'],
        ]);
    }

    protected function sanitizeContent(array $content): array
    {
        return [
            'title' => mb_substr(trim((string) ($content['title'] ?? 'AMIOSH Employee Handbook')), 0, 255),
            'chapters' => collect($content['chapters'] ?? [])
                ->map(fn ($chapter) => [
                    'id' => mb_substr(trim((string) ($chapter['id'] ?? '')), 0, 80),
                    'title' => mb_substr(trim((string) ($chapter['title'] ?? '')), 0, 255),
                    'bodyHtml' => $this->sanitizeHtml((string) ($chapter['bodyHtml'] ?? '')),
                ])
                ->filter(fn ($chapter) => $chapter['id'] !== '' && $chapter['title'] !== '' && $chapter['bodyHtml'] !== '')
                ->values()
                ->all(),
            'acknowledgement' => $this->acknowledgements()->sanitize(
                $content['acknowledgement'] ?? null,
                true,
            ),
        ];
    }

    protected function prepareContentForPublication(array $content, string $versionLabel): array
    {
        $content = $this->sanitizeContent($content);
        $content['acknowledgement'] = $this->acknowledgements()->materialize(
            $this->acknowledgements()->defaultDefinition(),
            $versionLabel,
        );

        return $content;
    }

    protected function versionIntegrityPayload(array $content, string $encoded): array
    {
        return [
            'content_sha256' => hash('sha256', $encoded),
            'acknowledgement_schema_version' => (int) (
                $content['acknowledgement']['schemaVersion'] ?? 0
            ) ?: null,
            'acknowledgement_sha256' => $this->acknowledgements()->hash(
                $content['acknowledgement'] ?? null,
            ),
        ];
    }

    protected function sanitizeHtml(string $html): string
    {
        $allowedTags = '<p><br><strong><b><em><i><u><ul><ol><li><table><thead><tbody><tr><th><td><div><span><small><h5><h6>';
        $clean = preg_replace('/<(script|style|iframe|object|embed)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $clean = strip_tags($clean, $allowedTags);
        $clean = $this->sanitizeHtmlAttributes($clean);

        return trim($clean);
    }

    protected function sanitizeHtmlAttributes(string $html): string
    {
        $allowedAttributes = ['class', 'rowspan', 'colspan', 'type'];
        $preservedClassNames = ['table-responsive', 'table', 'table-bordered', 'table-sm'];

        return preg_replace_callback('/<([a-z0-9]+)([^>]*)>/i', function (array $matches) use ($allowedAttributes, $preservedClassNames) {
            $tag = strtolower($matches[1]);
            $attributes = $matches[2] ?? '';
            $safeAttributes = [];

            preg_match_all(
                '/\s+([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/',
                $attributes,
                $attributeMatches,
                PREG_SET_ORDER,
            );

            foreach ($attributeMatches as $attribute) {
                $name = strtolower((string) $attribute[1]);
                $value = '';
                foreach ([3, 4, 5] as $valueIndex) {
                    if (isset($attribute[$valueIndex]) && $attribute[$valueIndex] !== '') {
                        $value = $attribute[$valueIndex];
                        break;
                    }
                }

                if (! in_array($name, $allowedAttributes, true)) {
                    continue;
                }

                if (preg_match('/^\s*javascript:/i', (string) $value)) {
                    continue;
                }

                if ($name === 'class') {
                    $classNames = collect(preg_split('/\s+/', (string) $value) ?: [])
                        ->filter(fn ($className) => in_array($className, $preservedClassNames, true))
                        ->values()
                        ->all();

                    if ($classNames === []) {
                        continue;
                    }

                    $value = implode(' ', $classNames);
                }

                $safeAttributes[] = sprintf(
                    '%s="%s"',
                    $name,
                    htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                );
            }

            return '<'.$tag.($safeAttributes ? ' '.implode(' ', $safeAttributes) : '').'>';
        }, $html) ?? $html;
    }

    protected function insertChangeLog(
        ?Request $request,
        int $versionId,
        string $action,
        string $summary,
        $changedAt,
        ?string $sectionId = null,
        ?string $sectionTitle = null,
    ): void {
        DB::table('hr_handbook_change_logs')->insert([
            'handbook_version_id' => $versionId,
            'action' => $action,
            'section_id' => $sectionId ? mb_substr($sectionId, 0, 80) : null,
            'section_title' => $sectionTitle ? mb_substr($sectionTitle, 0, 255) : null,
            'summary' => $summary,
            'changed_by_staff_id' => $request ? ((int) $request->session()->get('staff_id', 0) ?: null) : null,
            'changed_by_name_code' => $request ? mb_substr((string) $request->session()->get('name_code', ''), 0, 50) : 'SYSTEM',
            'changed_at' => $changedAt,
            'ip_address' => $request ? mb_substr((string) $request->ip(), 0, 45) : null,
            'user_agent' => $request ? $request->userAgent() : null,
            'created_at' => $changedAt,
            'updated_at' => $changedAt,
        ]);
    }
}
