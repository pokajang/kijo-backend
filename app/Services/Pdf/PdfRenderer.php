<?php

namespace App\Services\Pdf;

use App\Support\AppFilePaths;
use Dompdf\Dompdf;
use Dompdf\Options;

class PdfRenderer
{
    private static bool $dompdfAutoloaderRegistered = false;

    public function pdfView(string $baseView, mixed $language): string
    {
        $bmView = $baseView . '-bm';
        return $this->normalizeProposalLanguage($language) === 'ms-MY' && view()->exists($bmView)
            ? $bmView
            : $baseView;
    }

    public function companyLogoDataUri(): ?string
    {
        $logoPath = AppFilePaths::tcpdfTemplatePath('logo.png');
        if (!is_file($logoPath) || !is_readable($logoPath)) {
            return null;
        }
        $bytes = file_get_contents($logoPath);
        if ($bytes === false) {
            return null;
        }
        return 'data:image/png;base64,' . base64_encode($bytes);
    }

    public function renderPortraitWithFooter(string $html, mixed $generatedAt, string $generatorCode, string $generatorId): Dompdf
    {
        return $this->renderWithFooter($html, $generatedAt, $generatorCode, $generatorId, 'portrait');
    }

    public function renderWithFooter(string $html, mixed $generatedAt, string $generatorCode, string $generatorId, string $orientation = 'portrait'): Dompdf
    {
        $this->ensureDompdfAutoloaded();
        $dompdf = $this->makeDompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $metrics = $dompdf->getFontMetrics();
        $font = $metrics->getFont('Helvetica', 'italic') ?: $metrics->getFont('Times-Roman', 'italic');
        $fontSize = 8;
        $y = $canvas->get_height() - 28;
        $muted = [0.45, 0.45, 0.45];
        $canvas->page_text(20, $y, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, $fontSize, $muted);

        $stamp = 'Computer generated on: ' . $generatedAt->format('d M Y, h:i A')
            . ' by: ' . ($generatorCode !== '' ? $generatorCode : '-')
            . ' (' . $generatorId . ')';
        $stampWidth = $metrics->getTextWidth($stamp, $font, $fontSize);
        $canvas->page_text($canvas->get_width() - 20 - $stampWidth, $y, $stamp, $font, $fontSize, $muted);

        return $dompdf;
    }

    public function toRenderableRichText(string $value): string
    {
        $raw = trim($value);
        if ($raw === '') {
            return '';
        }

        $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/<\s*\/?\s*[a-z][^>]*>/i', $decoded)) {
            $allowed = '<p><br><br/><strong><b><em><i><u><ul><ol><li><div><span><h1><h2><h3><h4><h5><h6><table><thead><tbody><tr><th><td><a><sup><sub>';
            $clean = strip_tags($decoded, $allowed);
            return $clean !== '' ? $clean : nl2br(e($decoded));
        }

        return nl2br(e($decoded));
    }

    public function formatProposalDurationLabel(mixed $durationRaw): string
    {
        $duration = strtolower(trim((string) $durationRaw));
        return match ($duration) {
            '1hour' => '1 Hour',
            '2hour' => '2 Hours',
            '3hour' => '3 Hours',
            'halfday_am', 'halfday_pm' => 'Half Day (4 hours)',
            '1day' => '1 Full Day (8 hours)',
            '2day' => '2 Days (16 hours)',
            '3day' => '3 Days (24 hours)',
            default => $duration !== '' ? ucfirst($duration) : '',
        };
    }

    public function normalizeProposalLanguage(mixed $language): string
    {
        $value = strtolower(trim((string) $language));
        return match ($value) {
            'bm', 'ms', 'ms-my', 'ms_my', 'bahasa', 'bahasa melayu' => 'ms-MY',
            default => 'en',
        };
    }

    protected function makeDompdf(): Dompdf
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        return new Dompdf($options);
    }

    protected function ensureDompdfAutoloaded(): void
    {
        if (class_exists(Dompdf::class)) {
            return;
        }

        if (!self::$dompdfAutoloaderRegistered) {
            $prefixes = [
                'Dompdf\\' => base_path('vendor/dompdf/dompdf/src/'),
                'FontLib\\' => base_path('vendor/dompdf/php-font-lib/src/FontLib/'),
                'Svg\\' => base_path('vendor/dompdf/php-svg-lib/src/Svg/'),
                'Masterminds\\' => base_path('vendor/masterminds/html5/src/'),
                'Sabberworm\\CSS\\' => base_path('vendor/sabberworm/php-css-parser/src/'),
                'Barryvdh\\DomPDF\\' => base_path('vendor/barryvdh/laravel-dompdf/src/'),
            ];

            spl_autoload_register(static function (string $class) use ($prefixes): void {
                foreach ($prefixes as $prefix => $baseDir) {
                    if (!str_starts_with($class, $prefix)) {
                        continue;
                    }
                    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
                    $file = rtrim($baseDir, '/\\') . '/' . $relative . '.php';
                    if (is_file($file)) {
                        require_once $file;
                    }
                }
            });

            self::$dompdfAutoloaderRegistered = true;
        }

        $safeFiles = [
            base_path('vendor/thecodingmachine/safe/lib/special_cases.php'),
            base_path('vendor/thecodingmachine/safe/generated/classobj.php'),
            base_path('vendor/thecodingmachine/safe/generated/pcre.php'),
            base_path('vendor/thecodingmachine/safe/generated/iconv.php'),
        ];
        foreach ($safeFiles as $file) {
            if (is_file($file) && is_readable($file)) {
                require_once $file;
            }
        }

        $requiredFiles = [
            base_path('vendor/sabberworm/php-css-parser/src/Rule/Rule.php'),
            base_path('vendor/sabberworm/php-css-parser/src/RuleSet/RuleContainer.php'),
            base_path('vendor/dompdf/dompdf/lib/Cpdf.php'),
        ];
        foreach ($requiredFiles as $file) {
            if (is_file($file)) {
                require_once $file;
            }
        }

        if (!interface_exists('Safe\\Exceptions\\SafeExceptionInterface', false)) {
            $safeInterface = base_path('vendor/thecodingmachine/safe/lib/Exceptions/SafeExceptionInterface.php');
            if (is_file($safeInterface) && is_readable($safeInterface)) {
                require_once $safeInterface;
            } else {
                require_once app_path('Support/SafeExceptionInterfaceFallback.php');
            }
        }
    }
}
