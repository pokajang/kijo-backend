<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeliveryOrder\StoreDeliveryOrderRequest;
use App\Http\Requests\DeliveryOrder\UpdateDeliveryOrderRequest;
use App\Services\AuditLogService;
use App\Support\AppFilePaths;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeliveryOrderController extends Controller
{
    private static bool $dompdfAutoloaderRegistered = false;

    public function __construct(private AuditLogService $auditLog) {}

    public function index(Request $request)
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
        $year = (int) $request->query('year', 0);

        $query = DB::table('do_details')->orderBy('created_at', 'desc');
        if ($year >= 2000 && $year <= 2100) {
            $query->whereYear('project_award_date', $year);
        }

        $paginator = $query->paginate($perPage);
        $orders    = $paginator->items();

        if (!empty($orders)) {
            $doIds        = array_column($orders, 'id');
            $placeholders = implode(',', array_fill(0, count($doIds), '?'));
            $items        = DB::select(
                "SELECT do_id, id, item_name, description, quantity, unit
                 FROM do_breakdown WHERE do_id IN ({$placeholders}) ORDER BY id ASC",
                $doIds
            );

            $itemsByDo = [];
            foreach ($items as $item) {
                $itemsByDo[$item->do_id][] = $item;
            }
            foreach ($orders as &$order) {
                $order->items = $itemsByDo[$order->id] ?? [];
            }
            unset($order);
        }

        return response()->json([
            'status'     => 'success',
            'orders'     => $orders,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreDeliveryOrderRequest $request)
    {
        $data        = $request->validated();
        $details     = $data['details'];
        $breakdown   = $data['breakdown'];
        $forceCreate = (bool) $request->input('forceCreate', false);

        $staffId  = $request->session()->get('staff_id');
        $nameCode = $request->session()->get('name_code', 'XXX');

        if (!$forceCreate) {
            $existing = DB::table('do_details')
                ->where('project_name', $details['project_name'])
                ->where('project_code', $details['project_code'])
                ->where('project_award_date', $details['project_award_date'])
                ->value('do_number');

            if ($existing) {
                return response()->json([
                    'status'             => 'exists',
                    'message'            => 'A Delivery Order already exists for this project.',
                    'existing_do_number' => $existing,
                ]);
            }
        }

        DB::beginTransaction();
        try {
            $doNumber = $this->nextDoNumber($nameCode);
            $documentLanguage = $this->documentLanguageForProject($details['project_id'] ?? null);

            $insert = [
                'do_number'               => $doNumber,
                'client_name'             => $details['client_name'],
                'client_address'          => $details['client_address'],
                'client_contact_name'     => $details['client_contact_name'],
                'client_contact_position' => $details['client_contact_position'],
                'client_contact_email'    => $details['client_contact_email'],
                'client_contact_phone'    => $details['client_contact_phone'],
                'company_contact_name'    => $details['company_contact_name'],
                'company_contact_email'   => $details['company_contact_email'] ?? null,
                'company_contact_phone'   => $details['company_contact_phone'] ?? null,
                'project_id'              => $details['project_id'] ?? null,
                'project_name'            => $details['project_name'],
                'project_code'            => $details['project_code'],
                'project_award_date'      => $details['project_award_date'],
                'project_type'            => $details['project_type'] ?? null,
                'project_description'     => $details['project_description'] ?? null,
                'project_service_period'  => $details['project_service_period'] ?? null,
                'created_by'              => $staffId,
            ];
            if (Schema::hasColumn('do_details', 'document_language')) {
                $insert['document_language'] = $documentLanguage;
            }

            $doId = DB::table('do_details')->insertGetId($insert);

            DB::table('do_breakdown')->insert(array_map(fn ($item) => [
                'do_id'       => $doId,
                'item_name'   => $item['item_name'],
                'description' => $item['description'],
                'quantity'    => $item['quantity'],
                'unit'        => $item['unit'] ?? 'pcs',
            ], $breakdown));

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Database error.'], 500);
        }

        $this->auditLog->log($request, "Created delivery order {$doNumber}");
        return response()->json([
            'status'    => 'success',
            'message'   => 'Delivery Order created.',
            'do_number' => $doNumber,
            'do_id'     => $doId,
        ]);
    }

    public function update(UpdateDeliveryOrderRequest $request, int $id)
    {
        $data      = $request->validated();
        $details   = $data['details'];
        $breakdown = $data['breakdown'] ?? [];
        $staffId   = $request->session()->get('staff_id');

        DB::beginTransaction();
        try {
            $order = DB::table('do_details')->where('id', $id)->lockForUpdate()->first();
            if (!$order) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Delivery Order not found.'], 404);
            }
            if ((int) $order->created_by !== (int) $staffId) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'You are not allowed to edit this Delivery Order.'], 403);
            }

            $updates = [
                'client_name'             => $details['client_name'],
                'client_address'          => $details['client_address'],
                'client_contact_name'     => $details['client_contact_name'],
                'client_contact_position' => $details['client_contact_position'],
                'client_contact_email'    => $details['client_contact_email'],
                'client_contact_phone'    => $details['client_contact_phone'],
                'company_contact_name'    => $details['company_contact_name'],
                'company_contact_email'   => $details['company_contact_email'] ?? null,
                'company_contact_phone'   => $details['company_contact_phone'] ?? null,
                'project_id'              => $details['project_id'] ?? null,
                'project_name'            => $details['project_name'],
                'project_code'            => $details['project_code'],
                'project_award_date'      => $details['project_award_date'],
                'project_type'            => $details['project_type'] ?? null,
                'project_description'     => $details['project_description'] ?? null,
                'project_service_period'  => $details['project_service_period'] ?? null,
                'updated_at'              => now(),
            ];
            if (Schema::hasColumn('do_details', 'document_language')) {
                $updates['document_language'] = $order->document_language ?? $this->documentLanguageForProject($details['project_id'] ?? null);
            }

            DB::table('do_details')->where('id', $id)->update($updates);

            if (!empty($breakdown)) {
                DB::table('do_breakdown')->where('do_id', $id)->delete();
                DB::table('do_breakdown')->insert(array_map(fn ($item) => [
                    'do_id'       => $id,
                    'item_name'   => $item['item_name'],
                    'description' => $item['description'],
                    'quantity'    => $item['quantity'],
                    'unit'        => $item['unit'] ?? 'pcs',
                ], $breakdown));
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Database error.'], 500);
        }

        $this->auditLog->log($request, "Updated delivery order #{$id}");
        return response()->json(['status' => 'success', 'message' => 'Delivery Order updated.']);
    }

    public function destroy(Request $request, int $id)
    {
        $staffId = $request->session()->get('staff_id');

        DB::beginTransaction();
        try {
            $order = DB::table('do_details')->where('id', $id)->lockForUpdate()->first();
            if (!$order) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Delivery Order not found.'], 404);
            }
            if ((int) $order->created_by !== (int) $staffId) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'You are not allowed to delete this Delivery Order.'], 403);
            }

            DB::table('do_breakdown')->where('do_id', $id)->delete();
            DB::table('do_details')->where('id', $id)->delete();
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Database error.'], 500);
        }

        $this->auditLog->log($request, "Deleted delivery order #{$id}");
        return response()->json(['status' => 'success', 'message' => 'Delivery Order deleted.']);
    }

    public function pdf(Request $request, int $id)
    {
        $order = DB::table('do_details')->where('id', $id)->first();
        if (!$order) {
            return response()->json(['status' => 'error', 'message' => 'Delivery Order not found.'], 404);
        }

        $items = DB::table('do_breakdown')
            ->select(['item_name', 'description', 'quantity', 'unit'])
            ->where('do_id', $id)
            ->orderBy('id')
            ->get();

        $this->ensureDompdfAutoloaded();

        $createdAt = $order->created_at ?? now()->toDateTimeString();
        $generatedAt = now();
        $generatorId = (string) ($request->session()->get('staff_id', 'Unknown'));
        $generatorCode = (string) ($request->session()->get('name_code', ''));

        $logoDataUri = $this->companyLogoDataUri();

        $html = view($this->pdfView('pdf.delivery-order', $order->document_language ?? 'en'), [
            'order' => $order,
            'items' => $items,
            'documentType' => 'DELIVERY ORDER',
            'createdDate' => date('d M Y', strtotime((string) $createdAt)),
            'generatedDate' => $generatedAt->format('d M Y, h:i A'),
            'generatedByCode' => $generatorCode,
            'generatedById' => $generatorId,
            'logoDataUri' => $logoDataUri,
        ])->render();

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $metrics = $dompdf->getFontMetrics();
        $font = $metrics->getFont('Helvetica', 'italic');
        if (!$font) {
            $font = $metrics->getFont('Times-Roman', 'italic');
        }
        $fontSize = 8;
        // Align footer vertical inset with ~10mm page margin.
        $y = $canvas->get_height() - 28;
        $muted = [0.45, 0.45, 0.45];

        $canvas->page_text(20, $y, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, $fontSize, $muted);

        $stamp = 'Computer generated on: ' . $generatedAt->format('d M Y, h:i A')
            . ' by: ' . ($generatorCode !== '' ? $generatorCode : '-')
            . ' (' . $generatorId . ')';
        $stampWidth = $metrics->getTextWidth($stamp, $font, $fontSize);
        $canvas->page_text($canvas->get_width() - 20 - $stampWidth, $y, $stamp, $font, $fontSize, $muted);

        $safeDoNumber = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($order->do_number ?? "do-{$id}"));
        return response($dompdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"delivery-order-{$safeDoNumber}.pdf\"",
        ]);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    private function nextDoNumber(string $nameCode): string
    {
        $yearTwo = date('y');
        $prefix  = "DO{$yearTwo}-%";

        // Locks the relevant rows within the current transaction, so numbering stays consistent.
        $result = DB::selectOne(
            "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(do_number, '-', -1), ?, 1) AS UNSIGNED)), 0) AS max_run
             FROM do_details
             WHERE do_number LIKE ?
             FOR UPDATE",
            [$nameCode, $prefix]
        );

        $next   = ((int) ($result->max_run ?? 0)) + 1;
        return "DO{$yearTwo}-" . str_pad((string) $next, 3, '0', STR_PAD_LEFT) . $nameCode;
    }

    private function documentLanguageForProject(mixed $projectId): string
    {
        $id = (int) $projectId;
        if ($id <= 0) {
            return 'en';
        }

        if (!Schema::hasColumn('projects_main', 'proposal_language')) {
            return 'en';
        }

        $language = DB::table('projects_main')->where('id', $id)->value('proposal_language');
        return $this->normalizeDocumentLanguage($language);
    }

    private function normalizeDocumentLanguage(mixed $language): string
    {
        $value = strtolower(trim((string) $language));
        return match ($value) {
            'bm', 'ms', 'ms-my', 'ms_my', 'bahasa', 'bahasa melayu' => 'ms-MY',
            default => 'en',
        };
    }

    private function pdfView(string $baseView, mixed $language): string
    {
        $bmView = $baseView . '-bm';
        return $this->normalizeDocumentLanguage($language) === 'ms-MY' && view()->exists($bmView)
            ? $bmView
            : $baseView;
    }

    private function companyLogoDataUri(): ?string
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

    private function ensureDompdfAutoloaded(): void
    {
        if (class_exists(Dompdf::class)) {
            return;
        }

        if (!self::$dompdfAutoloaderRegistered) {
            $prefixes = [
                'Dompdf\\'         => base_path('vendor/dompdf/dompdf/src/'),
                'FontLib\\'        => base_path('vendor/dompdf/php-font-lib/src/FontLib/'),
                'Svg\\'            => base_path('vendor/dompdf/php-svg-lib/src/Svg/'),
                'Masterminds\\'    => base_path('vendor/masterminds/html5/src/'),
                'Sabberworm\\CSS\\' => base_path('vendor/sabberworm/php-css-parser/src/'),
                'Barryvdh\\DomPDF\\' => base_path('vendor/barryvdh/laravel-dompdf/src/'),
            ];

            spl_autoload_register(static function (string $class) use ($prefixes): void {
                foreach ($prefixes as $prefix => $baseDir) {
                    if (!str_starts_with($class, $prefix)) {
                        continue;
                    }

                    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
                    $file     = rtrim($baseDir, '/\\') . '/' . $relative . '.php';
                    if (is_file($file)) {
                        require_once $file;
                    }
                }
            });

            self::$dompdfAutoloaderRegistered = true;
        }

        $safeRequiredFiles = [
            base_path('vendor/thecodingmachine/safe/lib/special_cases.php'),
            base_path('vendor/thecodingmachine/safe/generated/classobj.php'),
            base_path('vendor/thecodingmachine/safe/generated/pcre.php'),
            base_path('vendor/thecodingmachine/safe/generated/iconv.php'),
        ];
        foreach ($safeRequiredFiles as $file) {
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
