<?php

namespace App\Services\Quotes\Crud;

use App\Http\Requests\Quote\StoreTrainingQuoteRequest;
use App\Http\Requests\Quote\UpdateTrainingQuoteRequest;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class TrainingQuoteService
{
    use SharedQuoteCrudHelpers;

    public function __construct(private AuditLogService $auditLog) {}

    public function showTraining(Request $request, int $id): JsonResponse
    {
        $quote = DB::table('quotes_training')->where('id', $id)->first();

        if (!$quote) {
            return response()->json(['status' => 'error', 'message' => 'Quote not found.'], 404);
        }

        return response()->json(['status' => 'success', 'data' => $quote]);
    }

    public function storeTraining(StoreTrainingQuoteRequest $request): JsonResponse
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        $nameCode = trim((string) $request->session()->get('name_code', ''));

        if ($staffId <= 0 || $nameCode === '') {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $data = $request->validated();
        $clientSnapshot = $data['client_snapshot'];
        $picSnapshot = $data['pic_snapshot'];
        $table = 'quotes_training';
        $lockName = 'quotes_training_' . date('Y');
        $prefix = 'QTR' . date('y') . '-%';

        $quoteId = null;
        $refNo = null;

        DB::beginTransaction();
        try {
            $priceException = $this->approvedPriceException($request, 'training', 0);
            $trainingTotals = $this->trainingTotals($data, $priceException);

            $lockResult = DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);
            if (!$lockResult || !$lockResult->acquired) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Could not acquire lock. Please retry.'], 503);
            }

            $row = DB::selectOne(
                "SELECT MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(quote_ref_no, '-', -1), ?, 1) AS UNSIGNED)) AS max_run FROM {$table} WHERE quote_ref_no LIKE ?",
                [$nameCode, $prefix]
            );
            $next = (($row->max_run ?? 0) ?: 0) + 1;
            $refNo = 'QTR' . date('y') . '-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT) . $nameCode;

            $insert = [
                'client_id' => $data['client_id'],
                'service_group' => 'training',
                'quote_running_no' => $next,
                'client_name' => $clientSnapshot['company_name'] ?? null,
                'client_ssm' => $clientSnapshot['ssm_number'] ?? null,
                'client_address' => $clientSnapshot['address'] ?? null,
                'client_city' => $clientSnapshot['city'] ?? null,
                'client_state' => $clientSnapshot['state'] ?? null,
                'client_zip' => $clientSnapshot['zip'] ?? null,
                'pic_name' => $picSnapshot['full_name'] ?? null,
                'pic_email' => $picSnapshot['email'] ?? null,
                'pic_phone' => $picSnapshot['mobile_number'] ?? null,
                'pic_position' => $picSnapshot['position'] ?? null,
                'training_id' => $data['training_id'] ?? null,
                'training_title' => $data['training_title'],
                'training_type' => $data['training_type'],
                ...(Schema::hasColumn($table, 'training_rate_type') ? ['training_rate_type' => $data['training_rate_type'] ?? null] : []),
                'payment_method' => $data['payment_method'],
                'proposed_date' => $data['proposed_date'] ?? null,
                ...(Schema::hasColumn($table, 'proposed_end_date') ? ['proposed_end_date' => $data['proposed_end_date'] ?? null] : []),
                'to_be_confirmed' => isset($data['to_be_confirmed']) ? (int) $data['to_be_confirmed'] : 0,
                'venue' => $data['venue'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'target_groups' => $data['target_groups'] ?? null,
                'pax' => $data['pax'] ?? null,
                'session_count' => $data['session_count'] ?? null,
                'duration_per_session' => $this->nd($data['duration_per_session'] ?? null),
                'duration_unit' => $data['duration_unit'] ?? null,
                ...(Schema::hasColumn($table, 'pricing_basis') ? ['pricing_basis' => $data['pricing_basis'] ?? 'per_session'] : []),
                'unit_price' => $this->nd($data['unit_price'] ?? null),
                'travel_charge' => $this->nd($data['travel_charge'] ?? null),
                ...(Schema::hasColumn($table, 'travel_region') ? ['travel_region' => $data['travel_region'] ?? null] : []),
                ...(Schema::hasColumn($table, 'price_exception_request_id') ? ['price_exception_request_id' => $priceException?->id] : []),
                'meals_provided' => $this->normalizeMealsProvided($data['meals_provided'] ?? null),
                'meal_price' => $this->nd($data['meal_price'] ?? null),
                'discount_type' => $priceException ? 'Negotiated' : ($data['discount_type'] ?? null),
                'discount_value' => $priceException ? $trainingTotals['discount_amount'] : $this->nd($data['discount_value'] ?? null),
                'sst_rate' => $this->nd($data['sst_rate'] ?? null),
                'hrd_charge' => $this->nd($data['hrd_charge'] ?? null),
                'training_total' => $trainingTotals['training_total'],
                'meal_total' => $trainingTotals['meal_total'],
                'mobilization_cost' => $trainingTotals['mobilization_cost'],
                'discount_amount' => $trainingTotals['discount_amount'],
                'subtotal' => $trainingTotals['subtotal'],
                'sst_amount' => $trainingTotals['sst_amount'],
                'hrd_amount' => $trainingTotals['hrd_amount'],
                'grand_total' => $trainingTotals['grand_total'],
                'attach_proposal' => isset($data['attach_proposal']) ? (int) $data['attach_proposal'] : 0,
                'proposal_id' => $data['proposal_id'] ?? null,
                'status' => 'Open',
                'revision_no' => 0,
                'created_by_id' => $staffId,
                'created_by_name' => (string) $request->session()->get('full_name', ''),
                'created_by_code' => $nameCode,
                'quote_ref_no' => $refNo,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (Schema::hasColumn($table, 'proposal_language')) {
                $insert['proposal_language'] = $this->normalizeProposalLanguage($data['proposal_language'] ?? 'en');
            }

            $quoteId = DB::table($table)->insertGetId($insert);
            $this->markPriceExceptionUsed($priceException, $quoteId);

            DB::commit();
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->databaseErrorResponse($e, $this->trainingQuoteFailureContext($data));
        } finally {
            DB::select('DO RELEASE_LOCK(?)', [$lockName]);
        }

        $this->auditLog->log($request, "Created training quote {$refNo} (ID #{$quoteId})");

        return response()->json([
            'status' => 'success',
            'message' => 'Training quote created successfully.',
            'quote_id' => $quoteId,
            'quote_ref_no' => $refNo,
            'data' => [
                'quote_id' => $quoteId,
                'quote_ref_no' => $refNo,
            ],
        ]);
    }

    public function updateTraining(UpdateTrainingQuoteRequest $request, int $id): JsonResponse
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        $nameCode = trim((string) $request->session()->get('name_code', ''));

        if ($staffId <= 0 || $nameCode === '') {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $quote = DB::table('quotes_training')->where('id', $id)->first();
        if (!$quote) {
            return response()->json(['status' => 'error', 'message' => 'Quote not found.'], 404);
        }

        $data = $request->validated();
        $clientSnapshot = $data['client_snapshot'];
        $picSnapshot = $data['pic_snapshot'];
        $isRevision = $request->boolean('isRevision');
        $updates = [];

        try {
            DB::beginTransaction();
            $priceException = $this->approvedPriceException($request, 'training', $id);
            $trainingTotals = $this->trainingTotals($data, $priceException);

            $updates = [
                'client_id' => $data['client_id'],
                'client_name' => $clientSnapshot['company_name'] ?? null,
                'client_ssm' => $clientSnapshot['ssm_number'] ?? null,
                'client_address' => $clientSnapshot['address'] ?? null,
                'client_city' => $clientSnapshot['city'] ?? null,
                'client_state' => $clientSnapshot['state'] ?? null,
                'client_zip' => $clientSnapshot['zip'] ?? null,
                'pic_name' => $picSnapshot['full_name'] ?? null,
                'pic_email' => $picSnapshot['email'] ?? null,
                'pic_phone' => $picSnapshot['mobile_number'] ?? null,
                'pic_position' => $picSnapshot['position'] ?? null,
                'training_id' => $data['training_id'] ?? null,
                'training_title' => $data['training_title'],
                'training_type' => $data['training_type'],
                ...(Schema::hasColumn('quotes_training', 'training_rate_type') ? ['training_rate_type' => $data['training_rate_type'] ?? null] : []),
                'payment_method' => $data['payment_method'],
                'proposed_date' => $data['proposed_date'] ?? null,
                ...(Schema::hasColumn('quotes_training', 'proposed_end_date') ? ['proposed_end_date' => $data['proposed_end_date'] ?? null] : []),
                'to_be_confirmed' => isset($data['to_be_confirmed']) ? (int) $data['to_be_confirmed'] : 0,
                'venue' => $data['venue'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'target_groups' => $data['target_groups'] ?? null,
                'pax' => $data['pax'] ?? null,
                'session_count' => $data['session_count'] ?? null,
                'duration_per_session' => $this->nd($data['duration_per_session'] ?? null),
                'duration_unit' => $data['duration_unit'] ?? null,
                ...(Schema::hasColumn('quotes_training', 'pricing_basis') ? ['pricing_basis' => $data['pricing_basis'] ?? 'per_session'] : []),
                'unit_price' => $this->nd($data['unit_price'] ?? null),
                'travel_charge' => $this->nd($data['travel_charge'] ?? null),
                ...(Schema::hasColumn('quotes_training', 'travel_region') ? ['travel_region' => $data['travel_region'] ?? null] : []),
                ...(Schema::hasColumn('quotes_training', 'price_exception_request_id') ? ['price_exception_request_id' => $priceException?->id ?? $quote->price_exception_request_id ?? null] : []),
                'meals_provided' => $this->normalizeMealsProvided($data['meals_provided'] ?? null),
                'meal_price' => $this->nd($data['meal_price'] ?? null),
                'discount_type' => $priceException ? 'Negotiated' : ($data['discount_type'] ?? null),
                'discount_value' => $priceException ? $trainingTotals['discount_amount'] : $this->nd($data['discount_value'] ?? null),
                'sst_rate' => $this->nd($data['sst_rate'] ?? null),
                'hrd_charge' => $this->nd($data['hrd_charge'] ?? null),
                'training_total' => $trainingTotals['training_total'],
                'meal_total' => $trainingTotals['meal_total'],
                'mobilization_cost' => $trainingTotals['mobilization_cost'],
                'discount_amount' => $trainingTotals['discount_amount'],
                'subtotal' => $trainingTotals['subtotal'],
                'sst_amount' => $trainingTotals['sst_amount'],
                'hrd_amount' => $trainingTotals['hrd_amount'],
                'grand_total' => $trainingTotals['grand_total'],
                'attach_proposal' => isset($data['attach_proposal']) ? (int) $data['attach_proposal'] : 0,
                'proposal_id' => $data['proposal_id'] ?? null,
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('quotes_training', 'proposal_language')) {
                $updates['proposal_language'] = $this->normalizeProposalLanguage($data['proposal_language'] ?? ($quote->proposal_language ?? 'en'));
            }

            if ($isRevision) {
                $updates['revision_no'] = DB::table('quotes_training')->where('id', $id)->lockForUpdate()->value('revision_no') + 1;
            }

            if ($decisionResponse = $this->projectValueDecisionResponse($request, 'training', $id, $quote, (float) $updates['grand_total'])) {
                DB::rollBack();

                return $decisionResponse;
            }

            DB::table('quotes_training')->where('id', $id)->update($updates);
            $this->markPriceExceptionUsed($priceException, $id);

            DB::commit();
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->databaseErrorResponse($e, $this->trainingQuoteFailureContext($data, $id));
        }

        $this->auditLog->log($request, "Updated training quote ID #{$id} by {$nameCode}" . ($isRevision ? ' (revision)' : ''));

        return response()->json([
            'status' => 'success',
            'message' => 'Training quote updated successfully.',
            'data' => [
                'revision_no' => $updates['revision_no'] ?? $quote->revision_no,
            ],
        ]);
    }

    private function normalizeMealsProvided(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        $v = strtolower(trim((string) $value));
        return in_array($v, ['1', 'true', 'yes'], true) ? 'Yes' : 'No';
    }

    private function trainingTotals(array $data, ?object $priceException): array
    {
        $pricingBasis = (string) ($data['pricing_basis'] ?? 'per_session');
        $pax = (float) ($data['pax'] ?? 0);
        $sessionCount = (float) ($data['session_count'] ?? 0);
        $durationPerSession = (float) ($data['duration_per_session'] ?? 0);
        $unitPrice = (float) ($data['unit_price'] ?? 0);
        $trainingTotal = $pricingBasis === 'per_pax'
            ? $pax * $unitPrice
            : $sessionCount * $durationPerSession * $unitPrice;
        $mealTotal = $this->normalizeMealsProvided($data['meals_provided'] ?? null) === 'Yes'
            ? $pax * (float) ($data['meal_price'] ?? 0) * max(1, $durationPerSession) * max(1, $sessionCount)
            : 0.0;
        $mobilizationCost = (float) ($data['travel_charge'] ?? 0);
        $discountAmount = $priceException ? (float) $priceException->approved_discount_amount : (float) ($data['discount_amount'] ?? 0);
        $subtotal = max(0, $trainingTotal + $mealTotal + $mobilizationCost - $discountAmount);
        $sstAmount = round($subtotal * (float) ($data['sst_rate'] ?? 0) / 100, 2);
        $hrdAmount = round(max(0, $trainingTotal - $discountAmount) * (float) ($data['hrd_charge'] ?? 0) / 100, 2);

        return [
            'training_total' => round($trainingTotal, 2),
            'meal_total' => round($mealTotal, 2),
            'mobilization_cost' => round($mobilizationCost, 2),
            'discount_amount' => round($discountAmount, 2),
            'subtotal' => round($subtotal, 2),
            'sst_amount' => $sstAmount,
            'hrd_amount' => $hrdAmount,
            'grand_total' => round($subtotal + $sstAmount + $hrdAmount, 2),
        ];
    }

    private function databaseErrorResponse(\Throwable $e, array $context = []): JsonResponse
    {
        report($e);

        $errorInfo = $e instanceof QueryException ? ($e->errorInfo ?? []) : [];
        Log::error('Training quote database save failed.', [
            ...$context,
            'exception' => $e::class,
            'sql_state' => $errorInfo[0] ?? null,
            'driver_code' => $errorInfo[1] ?? null,
        ]);

        [$message, $status] = $this->friendlyDatabaseError($e);

        return response()->json(['status' => 'error', 'message' => $message], $status);
    }

    private function friendlyDatabaseError(\Throwable $e): array
    {
        if (!$e instanceof QueryException) {
            return ['Database error.', 500];
        }

        $message = strtolower($e->getMessage());

        if (str_contains($message, 'training_id') && str_contains($message, 'null')) {
            return ['Please select a valid training topic before saving.', 422];
        }

        if (str_contains($message, 'data too long')) {
            return ['Some quotation details are too long for storage. Please shorten the text and try again.', 422];
        }

        if (str_contains($message, 'incorrect string value')) {
            return ['Some quotation details contain unsupported symbols. Please remove unusual characters and try again.', 422];
        }

        if (str_contains($message, 'out of range')) {
            return ['One of the quotation amounts is too large. Please check the pricing values and try again.', 422];
        }

        if (str_contains($message, 'unknown column')) {
            return ['Quotation database schema is not up to date. Please run migrations.', 500];
        }

        return ['Database error.', 500];
    }

    private function trainingQuoteFailureContext(array $data, ?int $quoteId = null): array
    {
        return [
            'quote_id' => $quoteId,
            'training_id' => $data['training_id'] ?? null,
            'proposal_id' => $data['proposal_id'] ?? null,
            'training_title_length' => $this->stringLength($data['training_title'] ?? ''),
            'venue_length' => $this->stringLength($data['venue'] ?? ''),
            'payment_method_length' => $this->stringLength($data['payment_method'] ?? ''),
            'pricing_basis' => $data['pricing_basis'] ?? null,
            'duration_per_session' => $data['duration_per_session'] ?? null,
            'proposal_language' => $data['proposal_language'] ?? null,
        ];
    }

    private function stringLength(mixed $value): int
    {
        $text = (string) ($value ?? '');

        return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    }
}
