<?php

namespace App\Services\Handbook;

use App\Services\AuditLogService;
use App\Services\Signatures\StaffSignatureService;
use App\Support\AppFilePaths;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class HandbookSignatureService extends HandbookBaseService
{
    public const EVIDENCE_SCHEMA_VERSION = 3;

    public function __construct(
        AuditLogService $auditLog,
        private StaffSignatureService $staffSignatures,
        private HandbookSignatureEvidenceService $evidence,
    ) {
        parent::__construct($auditLog);
    }

    public function acknowledgementStatus(Request $request)
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['success' => false, 'message' => 'Not authenticated.'], 401);
        }

        $version = DB::table('hr_handbook_versions')
            ->where('is_current', 1)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first(['id', 'version_label']);
        if (! $version) {
            $version = $this->currentVersion();
        }

        $signature = DB::table('hr_handbook_sign')
            ->where('staff_id', $staffId)
            ->where('handbook_version_id', $version->id)
            ->orderByDesc('signed_at')
            ->orderByDesc('id')
            ->first(['id', 'signed_at', 'evidence_schema_version']);

        return response()->json([
            'success' => true,
            'data' => [
                'version_id' => (int) $version->id,
                'version_label' => (string) $version->version_label,
                'acknowledged' => $signature !== null,
                'signature_id' => $signature?->id,
                'evidence_schema_version' => $signature?->evidence_schema_version,
                'signed_at' => $this->dateTime($signature?->signed_at),
            ],
        ]);
    }

    public function signingContext(Request $request, object $version): array
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        $nameCode = (string) $request->session()->get('name_code', '');
        $content = json_decode((string) $version->content_json, true);
        $definition = is_array($content) ? ($content['acknowledgement'] ?? null) : null;
        $schemaVersion = (int) ($definition['schemaVersion'] ?? 0);

        if ($staffId <= 0) {
            return [
                'available' => false,
                'reason' => 'Not authenticated.',
                'profile' => null,
                'personal_signature' => null,
            ];
        }

        if ($schemaVersion < HandbookAcknowledgementService::SCHEMA_VERSION) {
            return [
                'available' => false,
                'reason' => 'This handbook version does not contain the current acknowledgement evidence module. HR must publish an updated version before staff can sign.',
                'profile' => null,
                'personal_signature' => null,
            ];
        }

        try {
            $definition = $this->acknowledgements()->sanitize($definition);
            $acknowledgementHash = $this->acknowledgements()->hash($definition);
            $requiredDeclarations = $this->acknowledgements()->requiredDeclarations($definition);
        } catch (\Throwable $exception) {
            report($exception);

            return [
                'available' => false,
                'reason' => 'The current handbook acknowledgement definition is invalid. Contact HR.',
                'profile' => null,
                'personal_signature' => null,
            ];
        }

        if (! $this->versionIntegrityMatches($version, $acknowledgementHash)) {
            return [
                'available' => false,
                'reason' => 'The current handbook failed its integrity check. Contact HR before signing.',
                'profile' => null,
                'personal_signature' => null,
            ];
        }

        $profile = $this->staffProfile($staffId);
        $signature = $this->staffSignatures->current($staffId, $nameCode);
        $missing = $profile ? $this->missingProfileFields($profile) : ['profile'];

        return [
            'available' => $profile !== null && $missing === [],
            'reason' => $profile === null
                ? 'Staff profile not found.'
                : ($missing === []
                    ? null
                    : 'Complete the required staff profile fields before signing.'),
            'profile' => $profile ? [
                'full_name' => $profile['full_name'],
                'employee_code' => $profile['employee_code'],
                'designation' => $profile['designation'],
                'department' => $profile['department'],
                'identity_number' => $profile['identity_number'],
                'identity_number_masked' => $this->maskIdentity($profile['identity_number']),
                'missing_fields' => $missing,
            ] : null,
            'personal_signature' => $signature ? [
                'available' => true,
                'url' => $signature['url'],
                'sha256' => $signature['sha256'],
                'updated_at' => $signature['updated_at'],
            ] : [
                'available' => false,
                'url' => null,
                'sha256' => null,
                'updated_at' => null,
            ],
            'required_declaration_ids' => collect(
                $requiredDeclarations,
            )->pluck('id')->values()->all(),
            'acknowledgement_sha256' => $acknowledgementHash,
        ];
    }

    public function sign(Request $request)
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['success' => false, 'message' => 'Not authenticated.'], 401);
        }

        $current = DB::table('hr_handbook_versions')
            ->where('is_current', 1)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();
        if (! $current) {
            $current = $this->currentVersion();
        }

        $submittedVersionId = $request->input('handbook_version_id');
        if ($submittedVersionId !== null
            && is_numeric($submittedVersionId)
            && (int) $submittedVersionId !== (int) $current->id) {
            return response()->json([
                'success' => false,
                'message' => 'The handbook version changed before signing. Reload and review the current version.',
            ], 409);
        }

        $content = json_decode((string) $current->content_json, true);
        $schemaVersion = (int) ($content['acknowledgement']['schemaVersion'] ?? 0);

        return $schemaVersion >= HandbookAcknowledgementService::SCHEMA_VERSION
            ? $this->signEvidenceVersion($request, $current, $content)
            : $this->signLegacyVersion($request);
    }

    private function signEvidenceVersion(Request $request, object $current, array $content)
    {
        $validator = Validator::make($request->all(), [
            'submission_uuid' => ['required', 'uuid'],
            'handbook_version_id' => ['required', 'integer', 'min:1'],
            'typed_legal_name' => ['required', 'string', 'max:255'],
            'accepted_declaration_ids' => ['required', 'array', 'size:4'],
            'accepted_declaration_ids.*' => ['required', 'string', 'max:80', 'distinct'],
            'acknowledgement_sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
            'personal_signature_sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Complete every acknowledgement requirement before signing.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $submissionUuid = strtolower((string) $data['submission_uuid']);
        $existingSubmission = DB::table('hr_handbook_sign')
            ->where('submission_uuid', $submissionUuid)
            ->first([
                'id',
                'staff_id',
                'handbook_version_id',
                'full_name',
                'signed_at',
                'evidence_schema_version',
                'acknowledgement_sha256',
                'signature_sha256',
            ]);
        if ($existingSubmission) {
            if ($this->idempotentSubmissionMatches($existingSubmission, $data, $request)) {
                return $this->signedResponse($existingSubmission, true);
            }

            return response()->json([
                'success' => false,
                'message' => 'This submission identifier was already used for different acknowledgement data. Reload and try again.',
            ], 409);
        }

        if ((int) $data['handbook_version_id'] !== (int) $current->id) {
            return response()->json([
                'success' => false,
                'message' => 'The handbook version changed before signing. Reload and review the current version.',
            ], 409);
        }

        try {
            $definition = $this->acknowledgements()->sanitize($content['acknowledgement'] ?? null);
            $acknowledgementHash = $this->acknowledgements()->hash($definition);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'The current handbook acknowledgement definition is invalid. Contact HR.',
            ], 409);
        }

        if (! $this->versionIntegrityMatches($current, $acknowledgementHash)) {
            return response()->json([
                'success' => false,
                'message' => 'The current handbook failed its integrity check. Reload and contact HR before signing.',
            ], 409);
        }

        if (! hash_equals($acknowledgementHash, strtolower((string) $data['acknowledgement_sha256']))) {
            return response()->json([
                'success' => false,
                'message' => 'The acknowledgement wording changed before signing. Reload and review it again.',
            ], 409);
        }

        $requiredDeclarations = $this->acknowledgements()->requiredDeclarations($definition);
        $requiredIds = collect($requiredDeclarations)->pluck('id')->sort()->values()->all();
        $acceptedIds = collect($data['accepted_declaration_ids'])
            ->map(fn ($id) => trim((string) $id))
            ->sort()
            ->values()
            ->all();
        if ($acceptedIds !== $requiredIds) {
            return response()->json([
                'success' => false,
                'message' => 'Every required declaration must be accepted.',
            ], 422);
        }

        $staffId = (int) $request->session()->get('staff_id');
        $nameCode = (string) $request->session()->get('name_code', '');
        $profile = $this->staffProfile($staffId);
        $missing = $profile ? $this->missingProfileFields($profile) : ['profile'];
        if (! $profile || $missing !== []) {
            return response()->json([
                'success' => false,
                'message' => 'Complete the required staff profile fields before signing.',
                'missing_fields' => $missing,
            ], 422);
        }

        $typedName = trim((string) $data['typed_legal_name']);
        if ($this->normalizeName($typedName) !== $this->normalizeName($profile['full_name'])) {
            return response()->json([
                'success' => false,
                'message' => 'Typed legal name must match the name in your staff profile.',
            ], 422);
        }

        $personalSignature = $this->staffSignatures->current($staffId, $nameCode);
        if (! $personalSignature) {
            return response()->json([
                'success' => false,
                'message' => 'Upload a personal signature before signing the handbook.',
            ], 422);
        }
        if (! hash_equals(
            strtolower((string) $data['personal_signature_sha256']),
            (string) $personalSignature['sha256'],
        )) {
            return response()->json([
                'success' => false,
                'message' => 'Your personal signature changed before submission. Review it again.',
            ], 409);
        }

        $snapshot = null;
        try {
            $snapshot = $this->staffSignatures->snapshot($personalSignature, $submissionUuid);
            $signedAt = now();
            $contentSha256 = hash('sha256', (string) $current->content_json);
            $ipAddress = mb_substr((string) $request->ip(), 0, 45);
            $userAgent = mb_substr((string) $request->userAgent(), 0, 2000);
            $payload = $this->evidencePayload(
                self::EVIDENCE_SCHEMA_VERSION,
                $requiredDeclarations,
                $acknowledgementHash,
                $contentSha256,
                (int) $current->id,
                (string) $current->version_label,
                $profile,
                $staffId,
                $submissionUuid,
                'personal_signature_snapshot',
                $snapshot['sha256'],
                $signedAt->toIso8601String(),
                $typedName,
                $ipAddress,
                $userAgent,
            );
            $seal = $this->evidence->seal($payload);

            $signatureId = DB::transaction(function () use (
                $current,
                $staffId,
                $typedName,
                $profile,
                $submissionUuid,
                $snapshot,
                $contentSha256,
                $acknowledgementHash,
                $seal,
                $signedAt,
                $requiredDeclarations,
                $ipAddress,
                $userAgent,
            ): int {
                $lockedVersion = DB::table('hr_handbook_versions')
                    ->where('is_current', 1)
                    ->lockForUpdate()
                    ->first(['id']);
                if (! $lockedVersion || (int) $lockedVersion->id !== (int) $current->id) {
                    throw new StaleHandbookException;
                }

                $duplicate = DB::table('hr_handbook_sign')
                    ->where('staff_id', $staffId)
                    ->where('handbook_version_id', $current->id)
                    ->first(['id']);
                if ($duplicate) {
                    throw new DuplicateHandbookSignatureException((int) $duplicate->id);
                }

                $row = [
                    'handbook_version_id' => (int) $current->id,
                    'staff_id' => $staffId,
                    'full_name' => $typedName,
                    'ic_number' => '',
                    'signed_at' => $signedAt,
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent,
                    'submission_uuid' => $submissionUuid,
                    'evidence_schema_version' => self::EVIDENCE_SCHEMA_VERSION,
                    'employee_code_snapshot' => $profile['employee_code'],
                    'designation_snapshot' => $profile['designation'],
                    'department_snapshot' => $profile['department'],
                    'identity_number_encrypted' => Crypt::encryptString($profile['identity_number']),
                    'signature_method' => 'personal_signature_snapshot',
                    'signature_snapshot_path' => $snapshot['path'],
                    'signature_sha256' => $snapshot['sha256'],
                    'handbook_content_sha256' => $contentSha256,
                    'acknowledgement_sha256' => $acknowledgementHash,
                    'evidence_payload_json' => $seal['json'],
                    'signed_payload_sha256' => $seal['sha256'],
                    'evidence_hmac' => $seal['hmac'],
                    'evidence_key_id' => $seal['key_id'],
                ];

                $signatureId = DB::table('hr_handbook_sign')->insertGetId($row);
                foreach ($requiredDeclarations as $declaration) {
                    DB::table('hr_handbook_sign_declarations')->insert([
                        'handbook_sign_id' => $signatureId,
                        'declaration_id' => $declaration['id'],
                        'declaration_title_snapshot' => $declaration['title'],
                        'declaration_text_snapshot' => $declaration['body'],
                        'sort_order' => $declaration['order'],
                        'accepted_at' => $signedAt,
                        'created_at' => $signedAt,
                        'updated_at' => $signedAt,
                    ]);
                }

                return $signatureId;
            });
        } catch (StaleHandbookException) {
            if ($snapshot) {
                AppFilePaths::deleteStoredPath($snapshot['path']);
            }

            return response()->json([
                'success' => false,
                'message' => 'The handbook version changed before signing. Reload and review the current version.',
            ], 409);
        } catch (DuplicateHandbookSignatureException $exception) {
            if ($snapshot) {
                AppFilePaths::deleteStoredPath($snapshot['path']);
            }

            $existing = DB::table('hr_handbook_sign')->where('id', $exception->signatureId)->first();

            return $this->signedResponse($existing, true);
        } catch (\Throwable $exception) {
            if ($snapshot) {
                AppFilePaths::deleteStoredPath($snapshot['path']);
            }
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'The electronic signature could not be recorded safely.',
            ], 500);
        }

        $this->auditLog->log(
            $request,
            "Electronically signed employee handbook version #{$current->id} (record {$submissionUuid})",
        );

        return response()->json([
            'success' => true,
            'message' => 'Handbook acknowledgement recorded successfully.',
            'data' => [
                'id' => $signatureId,
                'submission_uuid' => $submissionUuid,
                'handbook_version_id' => (int) $current->id,
                'evidence_schema_version' => self::EVIDENCE_SCHEMA_VERSION,
                'declarations_accepted' => count($requiredDeclarations),
                'signed_at' => $signedAt->toIso8601String(),
            ],
        ]);
    }

    private function signLegacyVersion(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'full_name' => ['required', 'string', 'max:255'],
            'ic_number' => ['required', 'string', 'max:50'],
            'handbook_version_id' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'full_name and ic_number are required.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $staffId = (int) $request->session()->get('staff_id');
        $submittedVersionId = isset($data['handbook_version_id'])
            ? (int) $data['handbook_version_id']
            : null;
        $alreadySigned = false;
        $versionId = null;
        $staleVersion = false;

        DB::transaction(function () use ($request, $staffId, $submittedVersionId, $data, &$alreadySigned, &$versionId, &$staleVersion) {
            $version = DB::table('hr_handbook_versions')
                ->where('is_current', 1)
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            if (! $version) {
                $version = $this->currentVersion();
                DB::table('hr_handbook_versions')->where('id', $version->id)->lockForUpdate()->first();
            }

            $versionId = (int) $version->id;
            if ($submittedVersionId !== null && $submittedVersionId !== $versionId) {
                $staleVersion = true;

                return;
            }

            $alreadySigned = DB::table('hr_handbook_sign')
                ->where('staff_id', $staffId)
                ->where('handbook_version_id', $versionId)
                ->exists();
            if ($alreadySigned) {
                return;
            }

            DB::table('hr_handbook_sign')->insert([
                'handbook_version_id' => $versionId,
                'staff_id' => $staffId,
                'full_name' => trim((string) $data['full_name']),
                'ic_number' => trim((string) $data['ic_number']),
                'signed_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        });

        if ($staleVersion) {
            return response()->json([
                'success' => false,
                'message' => 'The handbook version changed before signing. Reload the handbook and sign the current version.',
            ], 409);
        }
        if ($alreadySigned) {
            return response()->json([
                'success' => false,
                'message' => 'You have already signed the current handbook version.',
            ]);
        }

        $this->auditLog->log($request, "Signed legacy employee handbook version #{$versionId} (staff #{$staffId})");

        return response()->json([
            'success' => true,
            'message' => 'Handbook signed successfully.',
            'data' => [
                'handbook_version_id' => $versionId,
                'signed_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function signatures(Request $request)
    {
        if (! $this->canManage($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: insufficient role to view handbook signatures.',
            ], 403);
        }

        $includeRestrictedAudit = $this->canViewEvidence($request);
        $versionId = (int) $request->query('version_id', 0);
        $query = DB::table('hr_handbook_sign as s')
            ->leftJoin('hr_handbook_versions as v', 'v.id', '=', 's.handbook_version_id')
            ->select([
                's.id',
                's.handbook_version_id',
                'v.version_label',
                's.staff_id',
                's.full_name',
                's.signed_at',
                's.ip_address',
                's.user_agent',
                's.submission_uuid',
                's.evidence_schema_version',
                's.employee_code_snapshot',
                's.signature_method',
            ]);
        if ($versionId > 0) {
            $query->where('s.handbook_version_id', $versionId);
        }

        $records = $query->orderByDesc('s.signed_at')->get();
        $declarations = Schema::hasTable('hr_handbook_sign_declarations')
            ? DB::table('hr_handbook_sign_declarations')
                ->whereIn('handbook_sign_id', $records->pluck('id'))
                ->orderBy('sort_order')
                ->get()
                ->groupBy('handbook_sign_id')
            : collect();

        return response()->json([
            'success' => true,
            'data' => $records->map(function (object $row) use ($declarations, $includeRestrictedAudit): array {
                $accepted = collect($declarations->get($row->id, []));
                $declarationMap = $accepted->mapWithKeys(
                    fn (object $declaration) => [$declaration->declaration_id => true],
                )->all();
                $isEvidence = (int) ($row->evidence_schema_version ?? 0)
                    >= HandbookAcknowledgementService::SCHEMA_VERSION;

                return [
                    'id' => (int) $row->id,
                    'handbook_version_id' => (int) $row->handbook_version_id,
                    'version_label' => $row->version_label,
                    'staff_id' => (int) $row->staff_id,
                    'full_name' => $row->full_name,
                    'employee_code' => $row->employee_code_snapshot,
                    'signed_at' => $this->dateTime($row->signed_at),
                    'ip_address' => $includeRestrictedAudit ? $row->ip_address : null,
                    'user_agent' => $includeRestrictedAudit ? $row->user_agent : null,
                    'submission_uuid' => $includeRestrictedAudit ? $row->submission_uuid : null,
                    'evidence_schema_version' => $row->evidence_schema_version,
                    'evidence_status' => $isEvidence ? 'complete' : 'legacy',
                    'declarations_accepted' => $accepted->count(),
                    'declarations_required' => $isEvidence
                        ? count(HandbookAcknowledgementService::REQUIRED_DECLARATION_IDS)
                        : null,
                    'declarations' => $declarationMap,
                    'signature_status' => $isEvidence ? 'electronically_signed' : 'legacy',
                    'signature_method' => $row->signature_method,
                ];
            })->values(),
        ]);
    }

    public function signatureEvidence(Request $request, int $id)
    {
        if (! $this->canViewEvidence($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: HR or System Admin access is required to view acknowledgement evidence.',
            ], 403);
        }

        $record = DB::table('hr_handbook_sign as s')
            ->leftJoin('hr_handbook_versions as v', 'v.id', '=', 's.handbook_version_id')
            ->where('s.id', $id)
            ->select([
                's.*',
                'v.version_label',
                'v.content_json as version_content_json',
                'v.content_sha256 as version_content_sha256',
                'v.acknowledgement_sha256 as version_acknowledgement_sha256',
            ])
            ->first();
        if (! $record) {
            return response()->json([
                'success' => false,
                'message' => 'Acknowledgement record not found.',
            ], 404);
        }

        $declarations = Schema::hasTable('hr_handbook_sign_declarations')
            ? DB::table('hr_handbook_sign_declarations')
                ->where('handbook_sign_id', $id)
                ->orderBy('sort_order')
                ->get()
                ->map(fn (object $row) => [
                    'id' => $row->declaration_id,
                    'title' => $row->declaration_title_snapshot,
                    'body' => $row->declaration_text_snapshot,
                    'accepted' => true,
                    'accepted_at' => $this->dateTime($row->accepted_at),
                ])->values()
            : collect();

        $isEvidence = (int) ($record->evidence_schema_version ?? 0)
            >= HandbookAcknowledgementService::SCHEMA_VERSION;

        $identity = null;
        $identityDecrypts = true;
        if ($record->identity_number_encrypted) {
            try {
                $identity = Crypt::decryptString($record->identity_number_encrypted);
            } catch (\Throwable) {
                $identityDecrypts = false;
            }
        }

        $signedAt = $this->dateTime($record->signed_at);
        $payloadDeclarations = $declarations->map(fn (array $declaration) => [
            'id' => $declaration['id'],
            'title' => $declaration['title'],
            'body' => $declaration['body'],
            'accepted' => true,
        ])->values()->all();
        $profileSnapshot = [
            'department' => $record->department_snapshot,
            'designation' => $record->designation_snapshot,
            'employee_code' => $record->employee_code_snapshot,
            'full_name' => $record->full_name,
            'identity_number' => $identity,
        ];
        $expectedPayload = $this->evidencePayload(
            (int) $record->evidence_schema_version,
            $payloadDeclarations,
            (string) $record->acknowledgement_sha256,
            (string) $record->handbook_content_sha256,
            (int) $record->handbook_version_id,
            (string) $record->version_label,
            $profileSnapshot,
            (int) $record->staff_id,
            (string) $record->submission_uuid,
            (string) $record->signature_method,
            (string) $record->signature_sha256,
            (string) $signedAt,
            (string) $record->full_name,
            (string) $record->ip_address,
            (string) $record->user_agent,
        );

        $sealVerified = false;
        try {
            $sealVerified = $isEvidence
                && $record->evidence_payload_json
                && $record->signed_payload_sha256
                && $record->evidence_hmac
                && $this->evidence->verify(
                    $record->evidence_payload_json,
                    $record->signed_payload_sha256,
                    $record->evidence_hmac,
                    $record->evidence_key_id,
                );
        } catch (\Throwable $exception) {
            report($exception);
        }

        $payloadMatchesRecord = $isEvidence
            && is_string($record->evidence_payload_json)
            && hash_equals(
                $record->evidence_payload_json,
                $this->acknowledgements()->canonicalJson($expectedPayload),
            );
        $signatureFileVerified = $isEvidence
            && $this->staffSignatures->verifySnapshot(
                $record->signature_snapshot_path,
                $record->signature_sha256,
            );
        $expectedDeclarationIds = collect(HandbookAcknowledgementService::REQUIRED_DECLARATION_IDS)
            ->sort()
            ->values()
            ->all();
        $actualDeclarationIds = $declarations->pluck('id')->sort()->values()->all();
        $declarationsVerified = $actualDeclarationIds === $expectedDeclarationIds
            && $declarations->every(
                fn (array $declaration): bool => $declaration['accepted_at'] === $signedAt,
            );
        $versionVerified = $this->storedVersionIntegrityMatches($record);
        $integrityVerified = $isEvidence
            && $identityDecrypts
            && $sealVerified
            && $payloadMatchesRecord
            && $signatureFileVerified
            && $declarationsVerified
            && $versionVerified;

        $this->auditLog->log($request, "Viewed handbook acknowledgement evidence record #{$id}");

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (int) $record->id,
                'submission_uuid' => $record->submission_uuid,
                'evidence_schema_version' => $record->evidence_schema_version,
                'evidence_status' => $isEvidence ? 'complete' : 'legacy',
                'integrity_verified' => $integrityVerified,
                'integrity_scope' => (int) $record->evidence_schema_version >= self::EVIDENCE_SCHEMA_VERSION
                    ? 'full_evidence'
                    : ($isEvidence ? 'core_evidence' : 'not_applicable'),
                'integrity_checks' => $isEvidence ? [
                    'sealed_payload' => $sealVerified,
                    'record_matches_payload' => $payloadMatchesRecord && $identityDecrypts,
                    'handbook_version' => $versionVerified,
                    'declarations' => $declarationsVerified,
                    'signature_file' => $signatureFileVerified,
                ] : null,
                'version' => [
                    'id' => (int) $record->handbook_version_id,
                    'label' => $record->version_label,
                    'content_sha256' => $record->handbook_content_sha256,
                    'acknowledgement_sha256' => $record->acknowledgement_sha256,
                ],
                'profile' => [
                    'staff_id' => (int) $record->staff_id,
                    'full_name' => $record->full_name,
                    'employee_code' => $record->employee_code_snapshot,
                    'designation' => $record->designation_snapshot,
                    'department' => $record->department_snapshot,
                    'identity_number_masked' => $this->maskIdentity($identity),
                ],
                'declarations' => $declarations,
                'signature' => [
                    'status' => $isEvidence ? 'electronically_signed' : 'legacy',
                    'method' => $record->signature_method,
                    'preview_url' => $record->signature_snapshot_path
                        ? route('handbook.signatures.signature', ['id' => $id])
                        : null,
                    'sha256' => $record->signature_sha256,
                    'typed_legal_name' => $record->full_name,
                    'signed_at' => $signedAt,
                ],
                'audit' => [
                    'ip_address' => $record->ip_address,
                    'user_agent' => $record->user_agent,
                    'signed_payload_sha256' => $record->signed_payload_sha256,
                    'evidence_key_id' => $record->evidence_key_id,
                ],
            ],
        ]);
    }

    public function signatureEvidenceImage(Request $request, int $id)
    {
        if (! $this->canViewEvidence($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: HR or System Admin access is required to view acknowledgement evidence.',
            ], 403);
        }

        $record = DB::table('hr_handbook_sign')
            ->where('id', $id)
            ->first(['signature_snapshot_path']);
        if (! $record?->signature_snapshot_path
            || ! AppFilePaths::storedPathExists($record->signature_snapshot_path)) {
            return response()->json([
                'success' => false,
                'message' => 'Preserved signature image not found.',
            ], 404);
        }

        $extension = strtolower((string) pathinfo(
            $record->signature_snapshot_path,
            PATHINFO_EXTENSION,
        ));

        return AppFilePaths::storedPathResponse(
            $record->signature_snapshot_path,
            "handbook-signature-{$id}.".($extension === 'png' ? 'png' : 'jpg'),
        );
    }

    private function evidencePayload(
        int $evidenceSchemaVersion,
        array $declarations,
        string $acknowledgementSha256,
        string $contentSha256,
        int $versionId,
        string $versionLabel,
        array $profile,
        int $staffId,
        string $submissionUuid,
        string $signatureMethod,
        string $signatureSha256,
        string $signedAt,
        string $typedName,
        string $ipAddress,
        string $userAgent,
    ): array {
        $payload = [
            'acknowledgement_sha256' => $acknowledgementSha256,
            'declarations' => collect($declarations)->map(fn (array $declaration) => [
                'id' => $declaration['id'],
                'title' => $declaration['title'],
                'body' => $declaration['body'],
                'accepted' => true,
            ])->values()->all(),
            'handbook_content_sha256' => $contentSha256,
            'handbook_version_id' => $versionId,
            'handbook_version_label' => $versionLabel,
            'profile' => [
                'department' => $profile['department'],
                'designation' => $profile['designation'],
                'employee_code' => $profile['employee_code'],
                'full_name' => $profile['full_name'],
                'identity_number_masked' => $this->maskIdentity($profile['identity_number']),
                'staff_id' => $staffId,
            ],
            'record_uuid' => $submissionUuid,
            'signature_method' => $signatureMethod,
            'signature_sha256' => $signatureSha256,
            'signed_at' => $signedAt,
            'typed_legal_name' => $typedName,
        ];

        if ($evidenceSchemaVersion >= self::EVIDENCE_SCHEMA_VERSION) {
            $payload['evidence_schema_version'] = $evidenceSchemaVersion;
            $payload['audit'] = [
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ];
        }

        return $payload;
    }

    private function idempotentSubmissionMatches(
        object $existing,
        array $submitted,
        Request $request,
    ): bool {
        if ((int) $existing->staff_id !== (int) $request->session()->get('staff_id')
            || (int) $existing->handbook_version_id !== (int) $submitted['handbook_version_id']
            || $this->normalizeName((string) $existing->full_name)
                !== $this->normalizeName((string) $submitted['typed_legal_name'])
            || ! hash_equals(
                strtolower((string) $existing->acknowledgement_sha256),
                strtolower((string) $submitted['acknowledgement_sha256']),
            )
            || ! hash_equals(
                strtolower((string) $existing->signature_sha256),
                strtolower((string) $submitted['personal_signature_sha256']),
            )) {
            return false;
        }

        $storedDeclarationIds = Schema::hasTable('hr_handbook_sign_declarations')
            ? DB::table('hr_handbook_sign_declarations')
                ->where('handbook_sign_id', $existing->id)
                ->pluck('declaration_id')
                ->map(fn ($id) => (string) $id)
                ->sort()
                ->values()
                ->all()
            : [];
        $submittedDeclarationIds = collect($submitted['accepted_declaration_ids'])
            ->map(fn ($id) => trim((string) $id))
            ->sort()
            ->values()
            ->all();

        return $storedDeclarationIds === $submittedDeclarationIds;
    }

    private function versionIntegrityMatches(object $version, ?string $acknowledgementSha256): bool
    {
        $storedContentSha256 = strtolower(trim((string) ($version->content_sha256 ?? '')));
        $storedAcknowledgementSha256 = strtolower(trim((string) (
            $version->acknowledgement_sha256 ?? ''
        )));

        return strlen($storedContentSha256) === 64
            && strlen($storedAcknowledgementSha256) === 64
            && is_string($acknowledgementSha256)
            && hash_equals(
                $storedContentSha256,
                hash('sha256', (string) $version->content_json),
            )
            && hash_equals($storedAcknowledgementSha256, strtolower($acknowledgementSha256));
    }

    private function storedVersionIntegrityMatches(object $record): bool
    {
        if (! is_string($record->version_content_json)
            || ! is_string($record->handbook_content_sha256)
            || ! hash_equals(
                strtolower($record->handbook_content_sha256),
                hash('sha256', $record->version_content_json),
            )) {
            return false;
        }

        if ($record->version_content_sha256
            && ! hash_equals(
                strtolower((string) $record->version_content_sha256),
                strtolower($record->handbook_content_sha256),
            )) {
            return false;
        }

        try {
            $content = json_decode($record->version_content_json, true, 512, JSON_THROW_ON_ERROR);
            $acknowledgementSha256 = $this->acknowledgements()->hash(
                $content['acknowledgement'] ?? null,
            );
        } catch (\Throwable) {
            return false;
        }

        return is_string($acknowledgementSha256)
            && hash_equals(
                strtolower((string) $record->acknowledgement_sha256),
                strtolower($acknowledgementSha256),
            )
            && (! $record->version_acknowledgement_sha256
                || hash_equals(
                    strtolower((string) $record->version_acknowledgement_sha256),
                    strtolower($acknowledgementSha256),
                ));
    }

    private function signedResponse(object $record, bool $idempotent)
    {
        return response()->json([
            'success' => true,
            'message' => 'Handbook acknowledgement was already recorded.',
            'data' => [
                'id' => (int) $record->id,
                'handbook_version_id' => (int) $record->handbook_version_id,
                'signed_at' => $this->dateTime($record->signed_at),
                'idempotent' => $idempotent,
            ],
        ]);
    }

    private function staffProfile(int $staffId): ?array
    {
        if (! Schema::hasTable('staff_general')) {
            return null;
        }

        $general = DB::table('staff_general')
            ->where('staff_id', $staffId)
            ->when(
                Schema::hasColumn('staff_general', 'deleted_at'),
                fn ($query) => $query->whereNull('deleted_at'),
            )
            ->first();
        if (! $general) {
            return null;
        }

        $profile = Schema::hasTable('staff_profile')
            ? DB::table('staff_profile')
                ->where('staff_id', $staffId)
                ->when(
                    Schema::hasColumn('staff_profile', 'deleted_at'),
                    fn ($query) => $query->whereNull('deleted_at'),
                )
                ->first()
            : null;

        return [
            'full_name' => trim((string) ($general->full_name ?? '')),
            'employee_code' => trim((string) ($general->name_code ?? $staffId)),
            'designation' => trim((string) (
                $general->position
                ?? $general->crm_position
                ?? ''
            )),
            'department' => trim((string) ($general->department ?? '')),
            'identity_number' => trim((string) ($profile?->nric ?? '')),
        ];
    }

    private function missingProfileFields(array $profile): array
    {
        return collect([
            'full_name',
            'employee_code',
            'designation',
            'department',
            'identity_number',
        ])->filter(fn (string $field): bool => trim((string) ($profile[$field] ?? '')) === '')
            ->values()
            ->all();
    }

    private function normalizeName(string $name): string
    {
        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);

        return mb_strtolower($name);
    }

    private function maskIdentity(?string $identity): ?string
    {
        $identity = trim((string) $identity);
        if ($identity === '') {
            return null;
        }

        $visible = mb_substr($identity, -4);

        return str_repeat('•', max(4, mb_strlen($identity) - 4)).$visible;
    }

    private function dateTime(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return (string) $value;
        }
    }
}

class StaleHandbookException extends \RuntimeException {}

class DuplicateHandbookSignatureException extends \RuntimeException
{
    public function __construct(public readonly int $signatureId)
    {
        parent::__construct('Handbook version already signed.');
    }
}
