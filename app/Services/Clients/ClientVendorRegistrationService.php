<?php

namespace App\Services\Clients;

use App\Support\AppFilePaths;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ClientVendorRegistrationService extends ClientBaseService
{
    public const EXPIRING_SOON_DAYS = 60;

    public function index(Request $request): JsonResponse
    {
        try {
            $rawRows = $this->baseRowsQuery()
                ->orderBy('r.valid_until')
                ->orderBy('cc.company_name')
                ->limit(2000)
                ->get();
            $recipientMap = $this->recipientsForRegistrations(
                $rawRows->pluck('id')->map(fn ($id) => (int) $id)->all()
            );
            $rows = $rawRows
                ->map(fn ($row) => $this->formatRow($row, $recipientMap))
                ->all();

            return $this->success(['rows' => $rows]);
        } catch (\Throwable $e) {
            report($e);
            return $this->error('Server error', 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $row = $this->baseRowsQuery()->where('r.id', $id)->first();
            if (!$row) {
                return $this->error('Vendor registration not found.', 404);
            }

            return $this->success($this->formatRow($row, null, true));
        } catch (\Throwable $e) {
            report($e);
            return $this->error('Server error', 500);
        }
    }

    public function attentionCount(): JsonResponse
    {
        try {
            if (!Schema::hasTable('client_vendor_registrations')) {
                return $this->success([
                    'expired_count' => 0,
                    'expiring_soon_count' => 0,
                    'count' => 0,
                ]);
            }

            $today = Carbon::today();
            $soonCutoff = $today->copy()->addDays(self::EXPIRING_SOON_DAYS);
            $baseQuery = DB::table('client_vendor_registrations')->whereNull('deleted_at');

            $expiredCount = (clone $baseQuery)
                ->whereDate('valid_until', '<', $today->toDateString())
                ->count();

            $expiringSoonCount = (clone $baseQuery)
                ->whereDate('valid_until', '>=', $today->toDateString())
                ->whereDate('valid_until', '<=', $soonCutoff->toDateString())
                ->count();

            return $this->success([
                'expired_count' => (int) $expiredCount,
                'expiring_soon_count' => (int) $expiringSoonCount,
                'count' => (int) $expiredCount,
            ]);
        } catch (\Throwable $e) {
            report($e);
            return $this->error('Server error', 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        return $this->save($request);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return $this->save($request, $id);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $existing = DB::table('client_vendor_registrations')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->first();

            if (!$existing) {
                return $this->error('Vendor registration not found.', 404);
            }

            DB::transaction(function () use ($id, $existing): void {
                DB::table('client_vendor_registration_recipients')->where('registration_id', $id)->delete();
                DB::table('client_vendor_registrations')->where('id', $id)->update([
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);

                if (!empty($existing->certificate_path)) {
                    AppFilePaths::deleteStoredPath((string) $existing->certificate_path);
                }
            });

            $this->auditLog->log($request, "Deleted client vendor registration ID {$id}");

            return $this->success(['id' => $id]);
        } catch (\Throwable $e) {
            report($e);
            return $this->error('Server error', 500);
        }
    }

    public function certificate(int $id)
    {
        $record = DB::table('client_vendor_registrations')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        if (!$record || empty($record->certificate_path)) {
            abort(404);
        }

        return AppFilePaths::storedPathResponse(
            (string) $record->certificate_path,
            (string) ($record->certificate_original_name ?: basename((string) $record->certificate_path))
        );
    }

    private function save(Request $request, ?int $id = null): JsonResponse
    {
        $storedCertificate = null;

        try {
            $data = $this->validatedPayload($request);
            $existing = $id !== null
                ? DB::table('client_vendor_registrations')->where('id', $id)->whereNull('deleted_at')->first()
                : DB::table('client_vendor_registrations')->where('client_id', $data['client_id'])->whereNull('deleted_at')->first();

            if ($id !== null && !$existing) {
                return $this->error('Vendor registration not found.', 404);
            }

            if ($id !== null) {
                $duplicate = DB::table('client_vendor_registrations')
                    ->where('client_id', $data['client_id'])
                    ->whereNull('deleted_at')
                    ->where('id', '<>', $id)
                    ->exists();
                if ($duplicate) {
                    return $this->error('This client already has an active vendor registration.', 422);
                }
            }

            $certificate = $this->storeCertificate($request);
            $storedCertificate = $certificate['path'] ?? null;
            $now = now();
            $staffId = (int) $request->session()->get('staff_id', 0) ?: null;

            $registrationId = DB::transaction(function () use ($data, $existing, $certificate, $now, $staffId): int {
                $payload = [
                    'client_id' => $data['client_id'],
                    'valid_from' => $data['valid_from'],
                    'valid_until' => $data['valid_until'],
                    'portal_url' => $data['portal_url'],
                    'portal_username' => $data['portal_username'],
                    'portal_password' => $this->encryptPortalPassword($data['portal_password']),
                    'remarks' => $data['remarks'],
                    'updated_by' => $staffId,
                    'updated_at' => $now,
                ];

                if (!empty($certificate['path'])) {
                    $payload['certificate_path'] = $certificate['path'];
                    $payload['certificate_original_name'] = $certificate['original_name'];
                    $payload['certificate_mime_type'] = $certificate['mime_type'];
                    $payload['certificate_size'] = $certificate['size'];
                }

                if ($existing) {
                    DB::table('client_vendor_registrations')->where('id', $existing->id)->update($payload);
                    $registrationId = (int) $existing->id;
                } else {
                    $registrationId = (int) DB::table('client_vendor_registrations')->insertGetId([
                        ...$payload,
                        'created_by' => $staffId,
                        'created_at' => $now,
                    ]);
                }

                DB::table('client_vendor_registration_recipients')
                    ->where('registration_id', $registrationId)
                    ->delete();

                $recipientRows = array_map(
                    fn (int $staffId): array => [
                        'registration_id' => $registrationId,
                        'staff_id' => $staffId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    $data['recipient_staff_ids']
                );
                DB::table('client_vendor_registration_recipients')->insert($recipientRows);

                return $registrationId;
            });

            if (!empty($certificate['path']) && $existing && !empty($existing->certificate_path)) {
                AppFilePaths::deleteStoredPath((string) $existing->certificate_path);
            }

            $this->auditLog->log($request, ($existing ? 'Updated' : 'Created') . " client vendor registration ID {$registrationId}");

            return $this->success($this->findFormattedRow($registrationId, true), null, $existing ? 200 : 201);
        } catch (ValidationException $e) {
            if ($storedCertificate) {
                AppFilePaths::deleteStoredPath($storedCertificate);
            }

            return response()->json([
                'status' => 'error',
                'message' => $e->validator->errors()->first() ?: 'Invalid vendor registration.',
                'errors' => $e->validator->errors(),
            ], 422);
        } catch (\Throwable $e) {
            if ($storedCertificate) {
                AppFilePaths::deleteStoredPath($storedCertificate);
            }

            report($e);
            return $this->error('Server error', 500);
        }
    }

    private function validatedPayload(Request $request): array
    {
        $request->merge([
            'recipient_staff_ids' => $this->recipientIdsFromRequest($request),
        ]);

        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_until' => ['required', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'recipient_staff_ids' => ['required', 'array', 'min:1'],
            'recipient_staff_ids.*' => ['required', 'integer'],
            'portal_url' => ['nullable', 'url', 'max:2048'],
            'portal_username' => ['nullable', 'string', 'max:255'],
            'portal_password' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'certificate' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ]);

        if (!$this->clientExists((int) $data['client_id'])) {
            throw ValidationException::withMessages(['client_id' => 'Selected client was not found.']);
        }

        $validRecipients = $this->validRecipientStaffIds((array) $data['recipient_staff_ids']);
        if (count($validRecipients) !== count(array_unique(array_map('intval', $data['recipient_staff_ids'])))) {
            throw ValidationException::withMessages(['recipient_staff_ids' => 'Select active staff with valid email recipients only.']);
        }

        return [
            'client_id' => (int) $data['client_id'],
            'valid_from' => $data['valid_from'],
            'valid_until' => $data['valid_until'],
            'recipient_staff_ids' => $validRecipients,
            'portal_url' => trim((string) ($data['portal_url'] ?? '')) ?: null,
            'portal_username' => trim((string) ($data['portal_username'] ?? '')) ?: null,
            'portal_password' => trim((string) ($data['portal_password'] ?? '')) ?: null,
            'remarks' => trim((string) ($data['remarks'] ?? '')) ?: null,
        ];
    }

    private function encryptPortalPassword(?string $password): ?string
    {
        $password = trim((string) $password);
        return $password !== '' ? Crypt::encryptString($password) : null;
    }

    private function decryptPortalPassword(?string $encryptedPassword): string
    {
        $encryptedPassword = trim((string) $encryptedPassword);
        if ($encryptedPassword === '') {
            return '';
        }

        try {
            return Crypt::decryptString($encryptedPassword);
        } catch (\Throwable) {
            // Backward compatibility for records created before encryption was introduced.
            return $encryptedPassword;
        }
    }

    private function recipientIdsFromRequest(Request $request): array
    {
        $raw = $request->input('recipient_staff_ids', []);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : explode(',', $raw);
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, (array) $raw),
            static fn (int $id): bool => $id > 0
        )));
    }

    private function clientExists(int $clientId): bool
    {
        return Schema::hasTable('client_company')
            && DB::table('client_company')
                ->where('company_id', $clientId)
                ->when(Schema::hasColumn('client_company', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
                ->exists();
    }

    private function validRecipientStaffIds(array $ids): array
    {
        if (!Schema::hasTable('staff_general')) {
            return [];
        }

        $query = DB::table('staff_general')
            ->whereIn('staff_id', array_values(array_unique(array_map('intval', $ids))))
            ->whereNotNull('email')
            ->where('email', '<>', '');

        if (Schema::hasColumn('staff_general', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        if (Schema::hasColumn('staff_general', 'status')) {
            $query->whereRaw("LOWER(TRIM(COALESCE(status, 'active'))) = 'active'");
        }

        return $query->get(['staff_id', 'email'])
            ->filter(fn ($row) => filter_var((string) $row->email, FILTER_VALIDATE_EMAIL))
            ->pluck('staff_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function storeCertificate(Request $request): array
    {
        if (!$request->hasFile('certificate')) {
            return ['path' => null, 'original_name' => null, 'mime_type' => null, 'size' => null];
        }

        $file = $request->file('certificate');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $path = AppFilePaths::storeFileAs(
            'client-vendor-registrations',
            $file,
            Str::uuid()->toString() . '.' . $extension
        );

        return [
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ];
    }

    private function baseRowsQuery()
    {
        return DB::table('client_vendor_registrations as r')
            ->leftJoin('client_company as cc', 'cc.company_id', '=', 'r.client_id')
            ->leftJoin('staff_general as created_by', 'created_by.staff_id', '=', 'r.created_by')
            ->leftJoin('staff_general as updated_by', 'updated_by.staff_id', '=', 'r.updated_by')
            ->whereNull('r.deleted_at')
            ->select([
                'r.id',
                'r.client_id',
                'r.valid_from',
                'r.valid_until',
                'r.certificate_path',
                'r.certificate_original_name',
                'r.certificate_mime_type',
                'r.certificate_size',
                'r.portal_url',
                'r.portal_username',
                'r.portal_password',
                'r.remarks',
                'r.created_by',
                'r.updated_by',
                'r.created_at',
                'r.updated_at',
                'cc.company_name',
                'cc.client_status',
                'created_by.full_name as created_by_name',
                'updated_by.full_name as updated_by_name',
            ]);
    }

    private function findFormattedRow(int $id, bool $includeSensitive = false): ?array
    {
        $row = $this->baseRowsQuery()->where('r.id', $id)->first();
        return $row ? $this->formatRow($row, null, $includeSensitive) : null;
    }

    private function formatRow(object $row, ?array $recipientMap = null, bool $includeSensitive = false): array
    {
        $id = (int) $row->id;
        $recipients = ($recipientMap ?? $this->recipientsForRegistrations([$id]))[$id] ?? [];
        $validUntil = substr((string) $row->valid_until, 0, 10);
        $daysLeft = $this->daysLeft($validUntil);
        $status = $this->statusFor($validUntil, !empty($row->certificate_path));

        return [
            'id' => $id,
            'client_id' => (int) $row->client_id,
            'client_name' => (string) ($row->company_name ?? "Client #{$row->client_id}"),
            'client_status' => $row->client_status,
            'valid_from' => substr((string) $row->valid_from, 0, 10),
            'valid_until' => $validUntil,
            'days_left' => $daysLeft,
            'status' => $status,
            'certificate_url' => !empty($row->certificate_path) ? url("client-vendor-registrations/{$id}/certificate") : '',
            'certificate_original_name' => (string) ($row->certificate_original_name ?? ''),
            'certificate_mime_type' => (string) ($row->certificate_mime_type ?? ''),
            'certificate_size' => $row->certificate_size !== null ? (int) $row->certificate_size : null,
            'has_certificate' => !empty($row->certificate_path),
            'portal_url' => (string) ($row->portal_url ?? ''),
            'portal_username' => (string) ($row->portal_username ?? ''),
            'remarks' => (string) ($row->remarks ?? ''),
            'recipients' => $recipients,
            'recipient_staff_ids' => array_map(fn (array $recipient): int => (int) $recipient['staff_id'], $recipients),
            'created_by' => $row->created_by ? (int) $row->created_by : null,
            'created_by_name' => (string) ($row->created_by_name ?? ''),
            'updated_by' => $row->updated_by ? (int) $row->updated_by : null,
            'updated_by_name' => (string) ($row->updated_by_name ?? ''),
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ] + ($includeSensitive ? [
            'portal_password' => $this->decryptPortalPassword($row->portal_password ?? null),
        ] : []);
    }

    public function formatRowsForReminder(array $registrationIds): array
    {
        if (!$registrationIds) {
            return [];
        }

        $rows = $this->baseRowsQuery()
            ->whereIn('r.id', $registrationIds)
            ->get();
        $recipientMap = $this->recipientsForRegistrations(
            $rows->pluck('id')->map(fn ($id) => (int) $id)->all()
        );

        return $rows
            ->map(fn ($row) => $this->formatRow($row, $recipientMap))
            ->all();
    }

    public function recipientsForRegistrations(array $registrationIds): array
    {
        if (!$registrationIds) {
            return [];
        }

        $rows = DB::table('client_vendor_registration_recipients as rr')
            ->join('staff_general as sg', 'sg.staff_id', '=', 'rr.staff_id')
            ->whereIn('rr.registration_id', $registrationIds)
            ->select([
                'rr.registration_id',
                'sg.staff_id',
                'sg.full_name',
                'sg.name_code',
                'sg.email',
            ])
            ->orderBy('sg.full_name')
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row->registration_id][] = [
                'staff_id' => (int) $row->staff_id,
                'full_name' => (string) ($row->full_name ?? ''),
                'name_code' => (string) ($row->name_code ?? ''),
                'email' => (string) ($row->email ?? ''),
            ];
        }

        return $grouped;
    }

    public static function statusForDate(?string $validUntil, bool $hasCertificate = true): string
    {
        $validUntil = trim((string) $validUntil);
        if ($validUntil === '') {
            return 'unknown';
        }

        $daysLeft = Carbon::today()->diffInDays(Carbon::parse($validUntil)->startOfDay(), false);
        if ($daysLeft < 0) {
            return 'expired';
        }

        if ($daysLeft <= self::EXPIRING_SOON_DAYS) {
            return 'expiring_soon';
        }

        if (!$hasCertificate) {
            return 'missing_certificate';
        }

        return 'active';
    }

    private function statusFor(string $validUntil, bool $hasCertificate): string
    {
        return self::statusForDate($validUntil, $hasCertificate);
    }

    private function daysLeft(string $validUntil): ?int
    {
        if ($validUntil === '') {
            return null;
        }

        return (int) Carbon::today()->diffInDays(Carbon::parse($validUntil)->startOfDay(), false);
    }
}
