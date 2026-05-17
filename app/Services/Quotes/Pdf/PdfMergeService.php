<?php

namespace App\Services\Quotes\Pdf;

use App\Support\AppFilePaths;

class PdfMergeService
{
    public function mergeSequence(array $sources): ?string
    {
        if (empty($sources)) {
            return null;
        }

        $legacyTcpdfPath = AppFilePaths::tcpdfPath();
        if (!is_file($legacyTcpdfPath)) {
            return null;
        }
        require_once $legacyTcpdfPath;

        $fpdiAutoloadPath = base_path('vendor/setasign/fpdi/src/autoload.php');
        if (is_file($fpdiAutoloadPath)) {
            require_once $fpdiAutoloadPath;
        }

        if (!class_exists(\setasign\Fpdi\Tcpdf\Fpdi::class)) {
            return null;
        }

        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $tempFiles = [];
        $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
        $pdf->SetCreator('');
        $pdf->SetAuthor('');
        $pdf->SetTitle('');
        $pdf->SetSubject('');
        $pdf->SetKeywords('');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetMargins(0, 0, 0, true);

        try {
            foreach ($sources as $source) {
                $sourcePath = null;
                if (is_string($source) && is_file($source)) {
                    $sourcePath = $source;
                } elseif (is_string($source) && str_starts_with($source, '%PDF')) {
                    $tmpFile = tempnam($tempDir, 'merge_pdf_');
                    if ($tmpFile === false) {
                        continue;
                    }
                    $tmpPdf = $tmpFile . '.pdf';
                    if (@file_put_contents($tmpPdf, $source) === false) {
                        @unlink($tmpFile);
                        continue;
                    }
                    @unlink($tmpFile);
                    $tempFiles[] = $tmpPdf;
                    $sourcePath = $tmpPdf;
                }

                if ($sourcePath === null || !is_file($sourcePath)) {
                    continue;
                }

                try {
                    $pageCount = $pdf->setSourceFile($sourcePath);
                } catch (\Throwable) {
                    continue;
                }

                for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                    $templateId = $pdf->importPage($pageNo);
                    $size = $pdf->getTemplateSize($templateId);
                    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $pdf->useTemplate($templateId);
                }
            }

            if ($pdf->getNumPages() <= 0) {
                return null;
            }

            return $pdf->Output('', 'S');
        } catch (\Throwable) {
            return null;
        } finally {
            foreach ($tempFiles as $tempFile) {
                @unlink($tempFile);
            }
        }
    }
}
