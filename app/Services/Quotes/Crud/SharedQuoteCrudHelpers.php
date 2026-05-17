<?php

namespace App\Services\Quotes\Crud;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait SharedQuoteCrudHelpers
{
    protected function normalizeProposalLanguage(mixed $language): string
    {
        $value = strtolower(trim((string) $language));
        return match ($value) {
            'bm', 'ms', 'ms-my', 'ms_my', 'bahasa', 'bahasa melayu' => 'ms-MY',
            default => 'en',
        };
    }


    protected function nd(mixed $v): ?float
    {
        return ($v !== null && $v !== '') ? (float) $v : null;
    }

    protected function approvedPriceException(Request $request, string $service, int $quoteId): ?object
    {
        $exceptionId = (int) $request->input('price_exception_request_id', 0);
        if ($exceptionId <= 0) {
            return null;
        }
        if (!in_array($service, ['training', 'manpower'], true)) {
            abort(response()->json(['status' => 'error', 'message' => 'Negotiation can only be applied to locked-rate Training and Manpower quotes.'], 409));
        }

        $row = DB::table('quote_price_exception_requests')
            ->where('id', $exceptionId)
            ->lockForUpdate()
            ->first();

        if (!$row) {
            abort(response()->json(['status' => 'error', 'message' => 'Approved negotiation not found.'], 404));
        }
        if ($row->status !== 'approved') {
            abort(response()->json(['status' => 'error', 'message' => 'Negotiation is not approved.'], 409));
        }
        if ((string) $row->service_group !== $service) {
            abort(response()->json(['status' => 'error', 'message' => 'Negotiation service mismatch.'], 409));
        }
        if ((int) ($row->quote_id ?? 0) > 0 && (int) $row->quote_id !== $quoteId) {
            abort(response()->json(['status' => 'error', 'message' => 'Negotiation quote mismatch.'], 409));
        }
        if ((string) ($row->request_type ?? '') !== 'quote' || (int) ($row->quote_id ?? 0) <= 0) {
            abort(response()->json(['status' => 'error', 'message' => 'Pre-quote negotiations cannot be applied. Save the quote first, then request negotiation from quote records.'], 409));
        }
        if (!$this->sessionCanUsePriceException($request, $row)) {
            abort(response()->json(['status' => 'error', 'message' => 'Only the requester can apply this negotiation.'], 403));
        }
        if (!empty($row->used_at)) {
            abort(response()->json(['status' => 'error', 'message' => 'Negotiation has already been applied.'], 409));
        }
        if ($quoteId > 0) {
            if (!$request->boolean('isRevision')) {
                abort(response()->json(['status' => 'error', 'message' => 'Approved negotiations must be applied through a quote revision.'], 409));
            }
            $this->assertPriceExceptionQuoteCanBeApplied($request, $service, $quoteId);
        }

        return $row;
    }

    protected function markPriceExceptionUsed(?object $exception, int $quoteId): void
    {
        if (!$exception) {
            return;
        }

        $affected = DB::table('quote_price_exception_requests')
            ->where('id', $exception->id)
            ->where('status', 'approved')
            ->whereNull('used_at')
            ->update([
                'status' => 'used',
                'quote_id' => (int) ($exception->quote_id ?: $quoteId),
                'used_revision_quote_id' => $quoteId,
                'used_at' => now(),
                'updated_at' => now(),
            ]);

        if ($affected < 1) {
            abort(response()->json(['status' => 'error', 'message' => 'Negotiation could not be marked as applied.'], 409));
        }
    }

    protected function sessionCanUsePriceException(Request $request, object $exception): bool
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        return $staffId > 0 && $staffId === (int) ($exception->requested_by_id ?? 0);
    }

    protected function assertPriceExceptionQuoteCanBeApplied(Request $request, string $service, int $quoteId): void
    {
        $table = match ($service) {
            'training' => 'quotes_training',
            'manpower' => 'quotes_manpower',
            default => null,
        };

        if (!$table || !Schema::hasTable($table)) {
            abort(response()->json(['status' => 'error', 'message' => 'Quotation service cannot apply negotiation.'], 409));
        }

        $quote = DB::table($table)->where('id', $quoteId)->lockForUpdate()->first();
        if (!$quote) {
            abort(response()->json(['status' => 'error', 'message' => 'Quotation not found.'], 404));
        }

        $status = strtolower(trim((string) ($quote->status ?? '')));
        if (!in_array($status, ['open', 'pending'], true)) {
            abort(response()->json(['status' => 'error', 'message' => 'Negotiation can only be applied to Open or Pending quotes.'], 409));
        }

        $staffId = (int) $request->session()->get('staff_id', 0);
        $nameCode = strtolower(trim((string) $request->session()->get('name_code', '')));
        $creatorId = (int) ($quote->created_by_id ?? 0);
        $creatorCode = strtolower(trim((string) ($quote->created_by_code ?? '')));
        $ownsQuote = ($staffId > 0 && $creatorId > 0 && $staffId === $creatorId)
            || ($nameCode !== '' && $creatorCode !== '' && $nameCode === $creatorCode);

        if (!$ownsQuote) {
            abort(response()->json(['status' => 'error', 'message' => 'Only the quote owner can apply an approved negotiation.'], 403));
        }
    }
}
