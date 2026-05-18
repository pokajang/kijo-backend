<?php

namespace App\Services\QuoteRecords;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class QuoteRelatedDocsPayload
{
    public static function forQuote(int $quoteId, string $quoteType, string ...$projectTypePatterns): array
    {
        $projects = self::linkedProjects($quoteId, $quoteType, $projectTypePatterns);
        $projectIds = array_values(array_filter(array_map(
            static fn (array $project): int => (int) ($project['id'] ?? 0),
            $projects
        )));

        $deliveryOrders = [];
        $invoices = [];
        $jd14Forms = [];
        $vendorLoas = [];
        $vendorPayments = [];

        if ($projectIds !== []) {
            if (Schema::hasTable('do_details')) {
                $deliveryOrders = DB::table('do_details')
                    ->whereIn('project_id', $projectIds)
                    ->orderBy('id')
                    ->select(['id', 'project_id', 'do_number'])
                    ->get()
                    ->map(static fn ($row): array => (array) $row)
                    ->all();
            }

            if (Schema::hasTable('invoices')) {
                $invoices = DB::table('invoices')
                    ->whereIn('project_id', $projectIds)
                    ->orderBy('id')
                    ->select(['id', 'project_id', 'invoice_ref_no', 'receipt_no'])
                    ->get()
                    ->map(static fn ($row): array => (array) $row)
                    ->all();
            }

            if (Schema::hasTable('invoices_jd14form')) {
                $jd14Forms = DB::table('invoices_jd14form')
                    ->whereIn('project_id', $projectIds)
                    ->orderBy('id')
                    ->select(['id', 'project_id', 'approval_no'])
                    ->get()
                    ->map(static fn ($row): array => (array) $row)
                    ->all();
            }

            if (Schema::hasTable('project_vendors')) {
                $query = DB::table('project_vendors as pv')
                    ->whereIn('pv.project_id', $projectIds)
                    ->orderBy('pv.id')
                    ->select(['pv.id', 'pv.project_id', 'pv.vendor_id', 'pv.loa_ref_no']);

                if (Schema::hasTable('vendor_main_details')) {
                    $query
                        ->leftJoin('vendor_main_details as vmd', 'vmd.vendor_id', '=', 'pv.vendor_id')
                        ->addSelect('vmd.vendor_name');
                }

                $vendorLoas = $query
                    ->get()
                    ->map(static fn ($row): array => (array) $row)
                    ->all();
            }

            if (Schema::hasTable('vendor_payments')) {
                $paymentQuery = DB::table('vendor_payments as vp')
                    ->whereIn('vp.project_id', $projectIds)
                    ->whereNull('vp.deleted_at')
                    ->orderBy('vp.id')
                    ->select([
                        'vp.id',
                        'vp.project_id',
                        'vp.vendor_id',
                        'vp.payment_type',
                        'vp.payment_context',
                        'vp.amount',
                        'vp.status',
                    ]);

                if (Schema::hasTable('project_vendors')) {
                    $paymentQuery
                        ->leftJoin('project_vendors as pv', function ($join): void {
                            $join->on('pv.project_id', '=', 'vp.project_id')
                                ->on('pv.vendor_id', '=', 'vp.vendor_id');
                        })
                        ->addSelect('pv.id as vendor_loa_id', 'pv.loa_ref_no');
                }

                $vendorPayments = $paymentQuery
                    ->get()
                    ->map(static fn ($row): array => (array) $row)
                    ->all();
            }
        }

        $receipts = array_values(array_filter(
            $invoices,
            static fn (array $invoice): bool => ! empty($invoice['receipt_no'])
        ));

        return [
            'projects' => $projects,
            'delivery_orders' => $deliveryOrders,
            'invoices' => $invoices,
            'receipts' => $receipts,
            'jd14' => $jd14Forms,
            'vendor_loas' => $vendorLoas,
            'vendor_payments' => $vendorPayments,
        ];
    }

    private static function linkedProjects(int $quoteId, string $quoteType, array $projectTypePatterns): array
    {
        if (! Schema::hasTable('projects_main')) {
            return [];
        }

        return DB::table('projects_main')
            ->where('quote_id', $quoteId)
            ->where(function ($query) use ($quoteType, $projectTypePatterns): void {
                $query->where('quote_type', $quoteType)
                    ->orWhere(function ($inner) use ($projectTypePatterns): void {
                        $inner->where(function ($nullable): void {
                            $nullable->whereNull('quote_type')
                                ->orWhereRaw("TRIM(quote_type) = ''");
                        })->where(function ($projectType) use ($projectTypePatterns): void {
                            foreach ($projectTypePatterns as $pattern) {
                                $projectType->orWhereRaw('LOWER(project_type) LIKE ?', [$pattern]);
                            }
                        });
                    });
            })
            ->orderBy('id')
            ->select(['id', 'project_name', 'project_type'])
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }
}
