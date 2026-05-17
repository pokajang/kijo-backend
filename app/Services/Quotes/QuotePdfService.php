<?php

namespace App\Services\Quotes;

use App\Services\QuoteRecords\EquipmentQuoteRecordPdfService;
use App\Services\QuoteRecords\ManpowerQuoteRecordPdfService;
use App\Services\Quotes\Pdf\IhQuotePdfService;
use App\Services\Quotes\Pdf\SpecialQuotePdfService;
use App\Services\Quotes\Pdf\TrainingQuotePdfService;
use Illuminate\Http\Request;

class QuotePdfService
{
    public function generateLegacyPdf(Request $request, string $service)
    {
        $service = $this->normalizeServiceKey($service);
        $quoteId = (int) $request->query('quote_id', 0);
        if ($quoteId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'quote_id is required'], 422);
        }

        if ($service === 'training') {
            return app(TrainingQuotePdfService::class)->generate($request, $quoteId);
        }
        if ($service === 'ih') {
            return app(IhQuotePdfService::class)->generate($request, $quoteId);
        }
        if ($service === 'special') {
            return app(SpecialQuotePdfService::class)->generate($request, $quoteId);
        }

        if ($service === 'manpower') {
            return app(ManpowerQuoteRecordPdfService::class)->pdfManpower($request);
        }
        if ($service === 'equipment') {
            return app(EquipmentQuoteRecordPdfService::class)->pdfEquipment($request);
        }

        return response()->json(['status' => 'error', 'message' => 'Unsupported service type.'], 404);
    }

    private function normalizeServiceKey(string $service): string
    {
        $service = strtolower(trim($service));
        return match ($service) {
            'equipment-tab', 'equipment supply', 'equipment' => 'equipment',
            'manpower-tab', 'manpower supply', 'manpower' => 'manpower',
            'ih-tab', 'industrial hygiene', 'ih' => 'ih',
            'special-tab', 'special service', 'special' => 'special',
            'training-tab', 'training' => 'training',
            default => $service,
        };
    }
}
