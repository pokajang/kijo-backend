<?php

use App\Http\Controllers\Api\AdminAssistantGovernanceController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminMailDiagnosticsController;
use App\Http\Controllers\Api\AdminMonthlyDashboardReportTestController;
use App\Http\Controllers\Api\AdminTaskClassificationExampleController;
use App\Http\Controllers\Api\AppNotificationController;
use App\Http\Controllers\Api\AppraisalController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\DebtorController;
use App\Http\Controllers\Api\DeliveryOrderController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\GoogleController;
use App\Http\Controllers\Api\HandbookController;
use App\Http\Controllers\Api\HrMiscController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\KnowledgeAssistantController;
use App\Http\Controllers\Api\KnowledgeController;
use App\Http\Controllers\Api\KpiController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\LegalComplianceAssessmentController;
use App\Http\Controllers\Api\LegalComplianceTemplateController;
use App\Http\Controllers\Api\MeetingController;
use App\Http\Controllers\Api\PrivateFileController;
use App\Http\Controllers\Api\ProcedureController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProposalTemplateController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\QuotePriceExceptionController;
use App\Http\Controllers\Api\QuoteRecordController;
use App\Http\Controllers\Api\QuoteRecordEmailController;
use App\Http\Controllers\Api\QuoteRecordTrainingSpecialController;
use App\Http\Controllers\Api\SalaryController;
use App\Http\Controllers\Api\SalesInquiryController;
use App\Http\Controllers\Api\SignatureController;
use App\Http\Controllers\Api\SportEventController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\StaffPreferenceController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\ToolRequestController;
use App\Http\Controllers\Api\VendorController;
use App\Http\Controllers\Api\VendorLoaController;
use App\Http\Controllers\Api\WhatsNewController;
use App\Http\Controllers\Api\WorkflowController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['status' => 'ok', 'app' => config('app.name')]));
Route::get('stats/workload/share/{token}', [StatsController::class, 'workloadShare'])
    ->where('token', '[A-Za-z0-9_-]{32,128}');
Route::get('stats/monthly-dashboard-report/public/{token}', [StatsController::class, 'publicMonthlyDashboardReport'])
    ->where('token', '[A-Za-z0-9_-]{32,128}');

// Auth
Route::post('auth/login', [AuthController::class, 'login']);
Route::post('auth/logout', [AuthController::class, 'logout'])->middleware('auth.session');
Route::get('auth/session', [AuthController::class, 'session'])->middleware('auth.session');
Route::post('auth/password', [AuthController::class, 'updatePassword'])->middleware('auth.session');
Route::post('auth/password/forgot', [AuthController::class, 'requestPasswordReset'])->middleware('throttle:5,1');
Route::post('auth/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');

// Protected routes
Route::middleware('auth.session')->group(function () {
    Route::get('files/private/{token}', [PrivateFileController::class, 'show'])
        ->where('token', '[A-Za-z0-9_-]+');

    Route::get('notifications/summary', [AppNotificationController::class, 'summary']);
    Route::get('notifications/list', [AppNotificationController::class, 'list']);
    Route::post('notifications/consume-entity', [AppNotificationController::class, 'consumeEntity']);
    Route::post('notifications/consume-route-group', [AppNotificationController::class, 'consumeRouteGroup']);

    Route::get('workflows/setup-status', [WorkflowController::class, 'setupStatus']);
    Route::get('workflows/templates', [WorkflowController::class, 'templates']);
    Route::get('workflows/templates/{key}', [WorkflowController::class, 'template']);
    Route::put('workflows/templates/{key}', [WorkflowController::class, 'updateTemplate'])->middleware('role:Manager,System Admin');
    Route::get('workflows/inbox', [WorkflowController::class, 'inbox']);
    Route::post('workflows/instances/{id}/actions', [WorkflowController::class, 'action'])->whereNumber('id');

    // What's New notices
    Route::get('whats-new/latest', [WhatsNewController::class, 'latestUnread']);
    Route::post('whats-new/read-all', [WhatsNewController::class, 'markAllRead']);
    Route::post('whats-new/{id}/read', [WhatsNewController::class, 'markRead'])->whereNumber('id');
    Route::get('whats-new', [WhatsNewController::class, 'index']);
    Route::post('whats-new', [WhatsNewController::class, 'store']);
    Route::get('whats-new/{id}', [WhatsNewController::class, 'show'])->whereNumber('id');
    Route::post('whats-new/{id}', [WhatsNewController::class, 'update'])->whereNumber('id');
    Route::put('whats-new/{id}', [WhatsNewController::class, 'update'])->whereNumber('id');
    Route::delete('whats-new/{id}', [WhatsNewController::class, 'destroy'])->whereNumber('id');
    Route::post('whats-new/{id}/publish', [WhatsNewController::class, 'publish'])->whereNumber('id');
    Route::post('whats-new/{id}/unpublish', [WhatsNewController::class, 'unpublish'])->whereNumber('id');

    // Knowledge Hub
    Route::get('knowledge/assistant/thread', [KnowledgeAssistantController::class, 'thread']);
    Route::post('knowledge/assistant/thread', [KnowledgeAssistantController::class, 'createThread']);
    Route::post('knowledge/assistant', [KnowledgeAssistantController::class, 'ask']);
    Route::post('knowledge/assistant/messages/{messageId}/feedback', [KnowledgeAssistantController::class, 'feedback'])->whereNumber('messageId');
    Route::delete('knowledge/assistant/thread', [KnowledgeAssistantController::class, 'clearThread']);
    Route::delete('knowledge/assistant/thread/{threadId}', [KnowledgeAssistantController::class, 'clearThread'])->whereNumber('threadId');
    Route::get('knowledge/articles', [KnowledgeController::class, 'index']);
    Route::get('knowledge/articles/my', [KnowledgeController::class, 'mine']);
    Route::post('knowledge/articles', [KnowledgeController::class, 'store']);
    Route::get('knowledge/articles/{slug}', [KnowledgeController::class, 'show']);
    Route::post('knowledge/articles/{id}', [KnowledgeController::class, 'update'])->whereNumber('id');
    Route::post('knowledge/articles/{id}/publish', [KnowledgeController::class, 'publish'])->whereNumber('id');
    Route::post('knowledge/articles/{id}/unpublish', [KnowledgeController::class, 'unpublish'])->whereNumber('id');
    Route::delete('knowledge/articles/{id}', [KnowledgeController::class, 'destroy'])->whereNumber('id');

    Route::get('admin/assistant/overview', [AdminAssistantGovernanceController::class, 'overview']);
    Route::get('admin/assistant/feedback', [AdminAssistantGovernanceController::class, 'feedback']);
    Route::get('admin/assistant/cache', [AdminAssistantGovernanceController::class, 'cache']);
    Route::get('admin/assistant/provider-memory', [AdminAssistantGovernanceController::class, 'providerMemory']);
    Route::get('admin/assistant/source-gaps', [AdminAssistantGovernanceController::class, 'sourceGaps']);
    Route::get('admin/assistant/analytics/overview', [AdminAssistantGovernanceController::class, 'analyticsOverview']);
    Route::get('admin/assistant/analytics/providers', [AdminAssistantGovernanceController::class, 'analyticsProviders']);
    Route::get('admin/assistant/analytics/trends', [AdminAssistantGovernanceController::class, 'analyticsTrends']);
    Route::get('admin/assistant/analytics/source-gaps', [AdminAssistantGovernanceController::class, 'analyticsSourceGaps']);
    Route::get('admin/assistant/analytics/export', [AdminAssistantGovernanceController::class, 'analyticsExport']);
    Route::post('admin/assistant/source-gaps/{id}/status', [AdminAssistantGovernanceController::class, 'updateSourceGapStatus'])->whereNumber('id');
    Route::post('admin/assistant/source-gaps/{id}/promote-provider-backlog', [AdminAssistantGovernanceController::class, 'promoteSourceGapProviderBacklog'])->whereNumber('id');
    Route::post('admin/assistant/source-gaps/{id}/create-knowledge-draft', [AdminAssistantGovernanceController::class, 'createSourceGapKnowledgeDraft'])->whereNumber('id');
    Route::post('admin/assistant/source-gaps/{id}/ignore', [AdminAssistantGovernanceController::class, 'ignoreSourceGap'])->whereNumber('id');
    Route::post('admin/assistant/source-gaps/{id}/resolve', [AdminAssistantGovernanceController::class, 'resolveSourceGap'])->whereNumber('id');
    Route::post('admin/assistant/blocked-signatures/{signature}/unblock', [AdminAssistantGovernanceController::class, 'unblockSignature'])->where('signature', '[A-Za-z0-9_-]+');

    // Batch 1 — Feedback
    Route::get('feedback', [FeedbackController::class, 'index']);
    Route::get('feedback/metrics/monthly', [FeedbackController::class, 'monthlyMetrics']);
    Route::post('feedback', [FeedbackController::class, 'store']);
    Route::put('feedback/{id}', [FeedbackController::class, 'update']);
    Route::delete('feedback/{id}', [FeedbackController::class, 'destroy']);

    // Batch 1 — Tool Requests
    Route::get('tool-requests', [ToolRequestController::class, 'index']);
    Route::post('tool-requests', [ToolRequestController::class, 'store']);
    Route::put('tool-requests/{id}/achievement', [ToolRequestController::class, 'updateAchievement']);

    // Internal tools - Legal Compliance Assessment
    Route::get('legal-compliance-templates/default', [LegalComplianceTemplateController::class, 'default']);
    Route::get('legal-compliance-templates', [LegalComplianceTemplateController::class, 'index']);
    Route::get('legal-compliance-templates/{id}', [LegalComplianceTemplateController::class, 'show'])->whereNumber('id');
    Route::middleware('role:Manager,System Admin')->group(function () {
        Route::post('legal-compliance-templates', [LegalComplianceTemplateController::class, 'store']);
        Route::put('legal-compliance-templates/{id}/draft', [LegalComplianceTemplateController::class, 'updateDraft'])->whereNumber('id');
        Route::post('legal-compliance-templates/{id}/publish', [LegalComplianceTemplateController::class, 'publish'])->whereNumber('id');
        Route::post('legal-compliance-templates/{id}/default', [LegalComplianceTemplateController::class, 'setDefault'])->whereNumber('id');
        Route::delete('legal-compliance-templates/{id}', [LegalComplianceTemplateController::class, 'destroy'])->whereNumber('id');
    });
    Route::get('legal-compliance-assessments', [LegalComplianceAssessmentController::class, 'index']);
    Route::get('legal-compliance-assessments/{id}/pdf', [LegalComplianceAssessmentController::class, 'pdf'])->whereNumber('id');
    Route::get('legal-compliance-assessments/{id}', [LegalComplianceAssessmentController::class, 'show'])->whereNumber('id');
    Route::post('legal-compliance-assessments', [LegalComplianceAssessmentController::class, 'store']);
    Route::post('legal-compliance-assessments/{id}/revision', [LegalComplianceAssessmentController::class, 'createRevision'])->whereNumber('id');
    Route::delete('legal-compliance-assessments/{id}', [LegalComplianceAssessmentController::class, 'destroy'])->whereNumber('id');

    // Batch 1 — Signature
    Route::get('signature', [SignatureController::class, 'show']);
    Route::post('signature', [SignatureController::class, 'store']);

    // Batch 1 — Sport Events (update uses POST because multipart/form-data)
    Route::get('sport-events', [SportEventController::class, 'index']);
    Route::post('sport-events', [SportEventController::class, 'store']);
    Route::post('sport-events/{id}', [SportEventController::class, 'update']);
    Route::delete('sport-events/{id}', [SportEventController::class, 'destroy']);

    // Batch 1 — Delivery Orders
    Route::get('delivery-orders', [DeliveryOrderController::class, 'index']);
    Route::post('delivery-orders', [DeliveryOrderController::class, 'store']);
    Route::put('delivery-orders/{id}', [DeliveryOrderController::class, 'update']);
    Route::delete('delivery-orders/{id}', [DeliveryOrderController::class, 'destroy']);

    // Commercial debtors
    Route::get('debtors', [DebtorController::class, 'index']);
    Route::post('debtors/manual', [DebtorController::class, 'storeManual']);
    Route::get('debtors/manual/{id}', [DebtorController::class, 'showManual'])->whereNumber('id');
    Route::post('debtors/manual/{id}', [DebtorController::class, 'updateManual'])->whereNumber('id');
    Route::put('debtors/manual/{id}', [DebtorController::class, 'updateManual'])->whereNumber('id');
    Route::patch('debtors/manual/{id}/mark-paid', [DebtorController::class, 'markManualPaid'])->whereNumber('id');
    Route::patch('debtors/manual/{id}/mark-open', [DebtorController::class, 'markManualOpen'])->whereNumber('id');
    Route::delete('debtors/manual/{id}', [DebtorController::class, 'destroyManual'])->whereNumber('id');
    Route::get('debtors/manual/{id}/attachment', [DebtorController::class, 'manualAttachment'])->whereNumber('id');
    Route::get('delivery-orders/{id}/pdf', [DeliveryOrderController::class, 'pdf']);

    // Batch 2 — Staff (legacy-compatible paths)
    // Staff (clean paths)
    Route::get('staff/list', [StaffController::class, 'listStaffDetails']);
    Route::get('staff/manage', [StaffController::class, 'manageStaff']);
    Route::get('staff/by-id', [StaffController::class, 'getStaffById']);
    Route::post('staff', [StaffController::class, 'createStaff']);
    Route::put('staff', [StaffController::class, 'updateStaff']);
    Route::get('staff/profile', [StaffController::class, 'getProfile']);
    Route::put('staff/profile', [StaffController::class, 'updateProfile']);
    Route::get('staff/preferences/{key}', [StaffPreferenceController::class, 'show'])->where('key', '.+');
    Route::put('staff/preferences/{key}', [StaffPreferenceController::class, 'update'])->where('key', '.+');
    Route::get('staff/system-users', [StaffController::class, 'getSystemUsers']);
    Route::get('staff/activities', [StaffController::class, 'getAllActivities']);
    Route::post('staff/activities/report', [StaffController::class, 'generateUserActivityReport']);

    // Batch 2 — Client (legacy-compatible paths)
    // Client (clean paths)
    Route::get('client-companies', [ClientController::class, 'listAll']);
    Route::get('client-companies/basic', [ClientController::class, 'listClients']);
    Route::get('client-companies/options', [ClientController::class, 'listClientOptions']);
    Route::get('client-companies/roi', [ClientController::class, 'roiReport']);
    Route::post('client-companies/refresh-status-from-invoices', [ClientController::class, 'refreshStatusFromInvoices']);
    Route::get('client-companies/{companyId}/commercial-history', [ClientController::class, 'commercialHistory'])->whereNumber('companyId');
    Route::get('client-vendor-registrations', [ClientController::class, 'vendorRegistrations']);
    Route::post('client-vendor-registrations', [ClientController::class, 'storeVendorRegistration']);
    Route::get('client-vendor-registrations/attention-count', [ClientController::class, 'vendorRegistrationAttentionCount']);
    Route::get('client-vendor-registrations/{id}', [ClientController::class, 'showVendorRegistration'])->whereNumber('id');
    Route::post('client-vendor-registrations/{id}', [ClientController::class, 'updateVendorRegistration'])->whereNumber('id');
    Route::delete('client-vendor-registrations/{id}', [ClientController::class, 'deleteVendorRegistration'])->whereNumber('id');
    Route::get('client-vendor-registrations/{id}/certificate', [ClientController::class, 'vendorRegistrationCertificate'])->whereNumber('id');
    Route::get('client-companies/{companyId}', [ClientController::class, 'show'])->whereNumber('companyId');
    Route::post('client-companies', [ClientController::class, 'store']);
    Route::put('client-companies/{companyId}', [ClientController::class, 'update']);
    Route::delete('client-companies/{companyId}', [ClientController::class, 'destroy']);
    Route::get('client-pics', [ClientController::class, 'listPics']);
    Route::put('client-pics/{picId}', [ClientController::class, 'updatePic']);
    Route::post('client-companies/{companyId}/pics/{picId}/unassign', [ClientController::class, 'unassignPic']);
    Route::delete('client-pics/{picId}/unassigned', [ClientController::class, 'deleteUnassignedPic']);
    Route::get('client-companies/{companyId}/pics', [ClientController::class, 'listCompanyPics']);
    Route::get('client-companies/{companyId}/branches', [ClientController::class, 'listCompanyBranches']);
    Route::match(['get', 'post'], 'sales-inquiries', [SalesInquiryController::class, 'index']);
    Route::post('sales-inquiries/create', [SalesInquiryController::class, 'store']);
    Route::post('sales-inquiries/store', [SalesInquiryController::class, 'store']);
    Route::get('sales-inquiries/{id}', [SalesInquiryController::class, 'show']);
    Route::match(['post', 'put'], 'sales-inquiries/{id}', [SalesInquiryController::class, 'update']);
    Route::delete('sales-inquiries/{id}', [SalesInquiryController::class, 'destroy']);
    Route::get('sales-inquiries/{id}/proof', [SalesInquiryController::class, 'proof']);
    Route::get('sales-inquiries/{id}/proofs/{proofId}', [SalesInquiryController::class, 'proofFile']);
    Route::post('sales-inquiries/{id}/link-client', [SalesInquiryController::class, 'linkClient']);
    Route::post('sales-inquiries/{id}/link-quote', [SalesInquiryController::class, 'linkQuote']);
    Route::post('sales-inquiries/{id}/assign-owner', [SalesInquiryController::class, 'assignOwner']);

    // Batch 2 — Catalog (legacy-compatible paths)
    // Catalog (clean paths)
    Route::get('catalog/items', [CatalogController::class, 'index']);
    Route::get('catalog/items/{id}', [CatalogController::class, 'show']);
    Route::post('catalog/items', [CatalogController::class, 'store']);
    Route::post('catalog/items/{id}', [CatalogController::class, 'update']);
    Route::put('catalog/items/{id}', [CatalogController::class, 'update']);
    Route::patch('catalog/items/{id}', [CatalogController::class, 'update']);
    Route::delete('catalog/items/{id}', [CatalogController::class, 'destroy']);
    Route::get('catalog/purchase-orders', [CatalogController::class, 'listPurchaseOrders']);
    Route::post('catalog/purchase-orders', [CatalogController::class, 'storePurchaseOrder']);
    Route::post('catalog/purchase-orders/mark-paid', [CatalogController::class, 'markPurchaseOrderPaid']);
    Route::post('catalog/purchase-orders/delete', [CatalogController::class, 'destroyPurchaseOrder']);
    Route::delete('catalog/purchase-orders/{poId}', [CatalogController::class, 'destroyPurchaseOrder']);
    Route::get('catalog/purchase-orders/pdf', [CatalogController::class, 'purchaseOrderPdf']);
    Route::get('catalog/purchase-orders/{poId}/pdf', [CatalogController::class, 'purchaseOrderPdf']);

    // Batch 2 — Vendor + Vendor LOA (legacy-compatible paths)

    // Vendor + Vendor LOA (clean paths)
    Route::get('vendors', [VendorController::class, 'index']);
    Route::get('vendors/main-details', [VendorController::class, 'mainDetails']);
    Route::post('vendors', [VendorController::class, 'store']);
    Route::put('vendors/{id}', [VendorController::class, 'update']);
    Route::patch('vendors/{id}/deactivate', [VendorController::class, 'deactivate']);
    Route::patch('vendors/{id}/reactivate', [VendorController::class, 'reactivate']);
    Route::delete('vendors/{id}', [VendorController::class, 'destroy']);
    Route::get('vendor-projects', [VendorController::class, 'projectVendors']);
    Route::get('vendor-payments', [VendorController::class, 'listPayments']);
    Route::get('vendor-payments/by-vendor', [VendorController::class, 'vendorPayments']);
    Route::get('vendor-payments/paid-by-vendor', [VendorController::class, 'paidPaymentsByVendor']);
    Route::get('vendor-payments/paid-by-vendor/{vendorId}', [VendorController::class, 'paidPaymentsForVendor'])->whereNumber('vendorId');
    Route::post('vendor-payments', [VendorController::class, 'storePayment']);
    Route::patch('vendor-payments/{id}/check', [VendorController::class, 'checkPayment']);
    Route::patch('vendor-payments/{id}/approve', [VendorController::class, 'approvePayment']);
    Route::patch('vendor-payments/{id}/reject', [VendorController::class, 'rejectPayment']);
    Route::patch('vendor-payments/{id}/return', [VendorController::class, 'returnPayment']);
    Route::patch('vendor-payments/{id}/mark-paid', [VendorController::class, 'markPaymentPaid']);
    Route::delete('vendor-payments/{id}', [VendorController::class, 'deletePayment'])->middleware('role:Manager,System Admin');
    Route::get('vendor-loas', [VendorLoaController::class, 'index']);
    Route::post('vendor-loas/payment-status', [VendorLoaController::class, 'updatePaymentStatus']);

    // Batch 3 (compat) - Tasks
    // Tasks (clean aliases)
    Route::get('tasks', [TaskController::class, 'getAllTasks'])->middleware('role:Manager,System Admin');
    Route::get('tasks/personal', [TaskController::class, 'getPersonalTasks']);
    Route::post('tasks', [TaskController::class, 'createTask']);
    Route::post('tasks/batch', [TaskController::class, 'createTasksBatch']);
    Route::post('tasks/classify', [TaskController::class, 'classifyTask']);
    Route::patch('tasks/{id}/complete', function (Request $request, TaskController $controller, int $id) {
        $request->merge(['task_id' => $id]);

        return $controller->markCompleted($request);
    });
    Route::post('tasks/comments', [TaskController::class, 'createComment']);
    Route::post('tasks/{id}/comments', function (Request $request, TaskController $controller, int $id) {
        $request->merge(['task_id' => $id]);

        return $controller->createComment($request);
    });
    Route::delete('tasks/{id}', function (Request $request, TaskController $controller, int $id) {
        $request->merge(['task_id' => $id]);

        return $controller->deleteTask($request);
    });
    Route::get('tasks/export/pdf', [TaskController::class, 'exportAllTasksPdf'])->middleware('role:Manager,System Admin');
    Route::get('tasks/personal/export/pdf', [TaskController::class, 'exportPersonalTasksPdf']);

    // Batch 3 - Procedures (legacy-compatible paths)
    // Procedures (clean paths)
    Route::get('procedures', [ProcedureController::class, 'index']);
    Route::get('procedures/{id}', [ProcedureController::class, 'show']);
    Route::post('procedures', [ProcedureController::class, 'store']);
    Route::post('procedures/{id}', [ProcedureController::class, 'update']);
    Route::put('procedures/{id}', [ProcedureController::class, 'update']);
    Route::delete('procedures/{id}', [ProcedureController::class, 'destroy']);

    // Batch 3 - Meetings (legacy-compatible paths)
    // Meetings (clean paths)
    Route::get('meetings', [MeetingController::class, 'index']);
    Route::get('meetings/{id}', [MeetingController::class, 'show']);
    Route::post('meetings', [MeetingController::class, 'store']);
    Route::post('meetings/{id}', [MeetingController::class, 'update']);
    Route::put('meetings/{id}', [MeetingController::class, 'update']);
    Route::delete('meetings/{id}', [MeetingController::class, 'destroy']);
    Route::post('meetings/action-items', [MeetingController::class, 'addActionItem']);
    Route::post('meetings/action-items/status', [MeetingController::class, 'updateActionItemStatus']);
    Route::post('meetings/verification', [MeetingController::class, 'updateVerification']);
    Route::get('meetings/{id}/pdf', [MeetingController::class, 'exportPdf']);

    // Batch 3 - Admin migration tools (legacy-compatible paths)
    // Admin (clean paths)
    Route::middleware('role:System Admin')->group(function () {
        Route::get('admin/migration-status', [AdminController::class, 'migrationStatus']);
        Route::post('admin/run-migrations', [AdminController::class, 'runMigrations']);
        Route::get('admin/mail-diagnostics', [AdminMailDiagnosticsController::class, 'show']);
        Route::post('admin/mail-diagnostics/default', [AdminMailDiagnosticsController::class, 'sendDefault']);
        Route::post('admin/mail-diagnostics/quote-pdf', [AdminMailDiagnosticsController::class, 'sendQuotePdf']);
        Route::get('admin/monthly-dashboard-report-test/status', [AdminMonthlyDashboardReportTestController::class, 'status']);
        Route::put('admin/monthly-dashboard-report-test/schedule', [AdminMonthlyDashboardReportTestController::class, 'updateSchedule']);
        Route::post('admin/monthly-dashboard-report-test/trigger', [AdminMonthlyDashboardReportTestController::class, 'trigger']);
        Route::get('admin/task-classification-health', [AdminTaskClassificationExampleController::class, 'health']);
        Route::get('admin/task-classification-examples', [AdminTaskClassificationExampleController::class, 'index']);
        Route::delete('admin/task-classification-examples/{id}', [AdminTaskClassificationExampleController::class, 'destroy'])->whereNumber('id');
    });

    // ─── Batch 4 — Proposal Templates ───────────────────────────────────────────

    // Training (legacy)

    // Manpower (legacy)

    // IH / Industrial Hygiene (legacy)

    // Special (legacy)

    // Proposal Templates (clean paths)
    Route::post('proposal-templates/{type}/{id}/bm-copy', [ProposalTemplateController::class, 'createBmCopy'])
        ->where(['type' => 'training|ih|manpower|special', 'id' => '[0-9]+']);

    Route::get('proposal-templates/training', [ProposalTemplateController::class, 'indexTraining']);
    Route::post('proposal-templates/training', [ProposalTemplateController::class, 'storeTraining']);
    Route::put('proposal-templates/training/{id}', [ProposalTemplateController::class, 'updateTraining']);
    Route::delete('proposal-templates/training/{id}', [ProposalTemplateController::class, 'destroyTraining']);
    Route::get('proposal-templates/training/{id}/pdf', [ProposalTemplateController::class, 'pdfTraining']);

    Route::get('proposal-templates/manpower', [ProposalTemplateController::class, 'indexManpower']);
    Route::get('proposal-templates/manpower/list', [ProposalTemplateController::class, 'listManpower']);
    Route::post('proposal-templates/manpower', [ProposalTemplateController::class, 'storeManpower']);
    Route::put('proposal-templates/manpower/{id}', [ProposalTemplateController::class, 'updateManpower']);
    Route::delete('proposal-templates/manpower/{id}', [ProposalTemplateController::class, 'destroyManpower']);
    Route::get('proposal-templates/manpower/{id}/pdf', [ProposalTemplateController::class, 'pdfManpower']);

    Route::get('proposal-templates/ih', [ProposalTemplateController::class, 'indexIh']);
    Route::get('proposal-templates/ih/list', [ProposalTemplateController::class, 'listIh']);
    Route::post('proposal-templates/ih', [ProposalTemplateController::class, 'storeIh']);
    Route::put('proposal-templates/ih/{id}', [ProposalTemplateController::class, 'updateIh']);
    Route::delete('proposal-templates/ih/{id}', [ProposalTemplateController::class, 'destroyIh']);
    Route::get('proposal-templates/ih/{id}/pdf', [ProposalTemplateController::class, 'pdfIh']);

    Route::get('proposal-templates/special', [ProposalTemplateController::class, 'indexSpecial']);
    Route::get('proposal-templates/special/list', [ProposalTemplateController::class, 'listSpecial']);
    Route::post('proposal-templates/special', [ProposalTemplateController::class, 'storeSpecial']);
    Route::post('proposal-templates/special/{id}', [ProposalTemplateController::class, 'updateSpecial']);
    Route::put('proposal-templates/special/{id}', [ProposalTemplateController::class, 'updateSpecial']);
    Route::delete('proposal-templates/special/{id}', [ProposalTemplateController::class, 'destroySpecial']);
    Route::get('proposal-templates/special/{id}/pdf', [ProposalTemplateController::class, 'pdfSpecial']);

    // ─── Batch 4 — Quotes ───────────────────────────────────────────────────────

    // Equipment (legacy)

    // Manpower (legacy)

    // IH (legacy)

    // Special (legacy)

    // Training (legacy)

    // Shared (legacy)

    // ─── Batch 5 — Quote Records (legacy paths) ────────────────────────────────

    // Training

    // IH

    // Manpower

    // Special

    Route::post('quote-records/{service}/{id}/email', [QuoteRecordEmailController::class, 'send'])
        ->where(['service' => 'training|ih|manpower|special|equipment', 'id' => '[0-9]+']);
    Route::get('quote-price-exceptions', [QuotePriceExceptionController::class, 'index']);
    Route::get('quote-price-exceptions/pending-count', [QuotePriceExceptionController::class, 'pendingCount']);
    Route::post('quote-price-exceptions/pre-quote', [QuotePriceExceptionController::class, 'createPreQuote']);
    Route::get('quote-price-exceptions/{id}', [QuotePriceExceptionController::class, 'show']);
    Route::patch('quote-price-exceptions/{id}/approve', [QuotePriceExceptionController::class, 'approve']);
    Route::patch('quote-price-exceptions/{id}/reject', [QuotePriceExceptionController::class, 'reject']);
    Route::post('quote-records/{service}/{id}/negotiate', [QuotePriceExceptionController::class, 'createForQuote'])
        ->where(['service' => 'training|ih|manpower|special|equipment', 'id' => '[0-9]+']);

    // Equipment

    // Quotes (clean paths)
    Route::get('quotes/equipment/{id}', [QuoteController::class, 'showEquipment']);
    Route::post('quotes/equipment', [QuoteController::class, 'storeEquipment']);
    Route::put('quotes/equipment/{id}', [QuoteController::class, 'updateEquipment']);

    Route::get('quotes/manpower/{id}', [QuoteController::class, 'showManpower']);
    Route::post('quotes/manpower', [QuoteController::class, 'storeManpower']);
    Route::put('quotes/manpower/{id}', [QuoteController::class, 'updateManpower']);

    Route::get('quotes/ih/{id}', [QuoteController::class, 'showIh']);
    Route::post('quotes/ih', [QuoteController::class, 'storeIh']);
    Route::put('quotes/ih/{id}', [QuoteController::class, 'updateIh']);

    Route::get('quotes/special/{id}', [QuoteController::class, 'showSpecial']);
    Route::post('quotes/special', [QuoteController::class, 'storeSpecial']);
    Route::put('quotes/special/{id}', [QuoteController::class, 'updateSpecial']);

    Route::get('quotes/training/{id}', [QuoteController::class, 'showTraining']);
    Route::post('quotes/training', [QuoteController::class, 'storeTraining']);
    Route::put('quotes/training/{id}', [QuoteController::class, 'updateTraining']);

    Route::get('quotes/training-topics', [QuoteController::class, 'listTrainingTopics']);
    Route::post('quotes/inquiry-source', [QuoteController::class, 'saveInquirySource']);

    // ─── Batch 8 — HR: Appraisal ────────────────────────────────────────────────

    // Appraisal (legacy)
    // Appraisal (clean paths)
    Route::get('hr/appraisals', [AppraisalController::class, 'index']);
    Route::get('hr/appraisals/personal', [AppraisalController::class, 'personal']);
    Route::get('hr/appraisals/final', [AppraisalController::class, 'finalIndex']);
    Route::post('hr/appraisals/final', [AppraisalController::class, 'finalStore']);
    Route::get('hr/appraisals/final/{id}', [AppraisalController::class, 'finalShow'])->whereNumber('id');
    Route::put('hr/appraisals/final/{id}', [AppraisalController::class, 'finalUpdate'])->whereNumber('id');
    Route::delete('hr/appraisals/final/{id}', [AppraisalController::class, 'finalDestroy'])->whereNumber('id');
    Route::get('hr/appraisals/{id}', [AppraisalController::class, 'show'])->whereNumber('id');
    Route::post('hr/appraisals', [AppraisalController::class, 'store']);
    Route::put('hr/appraisals/{id}', [AppraisalController::class, 'update'])->whereNumber('id');
    Route::delete('hr/appraisals/{id}', [AppraisalController::class, 'destroy'])->whereNumber('id');

    // ─── Batch 8 — HR: Misc ─────────────────────────────────────────────────────

    // Misc (legacy)
    // Misc (clean paths)
    Route::get('hr/staff', [HrMiscController::class, 'listStaff']);
    Route::get('hr/staff/{id}', [HrMiscController::class, 'viewStaffDetail'])->middleware('role:HR,Manager,System Admin');
    Route::post('hr/staff/{id}/terminate', [HrMiscController::class, 'handleTerminate']);
    Route::get('hr/handbook/current', [HandbookController::class, 'current']);
    Route::post('hr/handbook/publish', [HandbookController::class, 'publish']);
    Route::post('hr/handbook/draft-section', [HandbookController::class, 'saveDraftSection']);
    Route::post('hr/handbook/publish-draft', [HandbookController::class, 'publishDraft']);
    Route::delete('hr/handbook/draft', [HandbookController::class, 'discardDraft']);
    Route::get('hr/handbook/change-logs', [HandbookController::class, 'changeLogs']);
    Route::get('hr/handbook/versions', [HandbookController::class, 'versions']);
    Route::get('hr/handbook/versions/{id}', [HandbookController::class, 'version'])->whereNumber('id');
    Route::post('hr/handbook/versions/{id}/reactivate', [HandbookController::class, 'reactivateVersion'])->whereNumber('id');
    Route::post('hr/handbook/sign', [HandbookController::class, 'sign']);
    Route::get('hr/handbook/signatures', [HandbookController::class, 'signatures']);

    // ─── Batch 8 — HR: KPI ──────────────────────────────────────────────────────

    // KPI Parameters (legacy)
    // KPI Tracker (legacy)
    // KPI (clean paths)
    Route::get('hr/kpi/parameters', [KpiController::class, 'getAllKpiParameters']);
    Route::get('hr/kpi/parameters/mine', [KpiController::class, 'getMyKpiParameters']);
    Route::post('hr/kpi/parameters', [KpiController::class, 'createKpiParameters']);
    Route::put('hr/kpi/parameters', [KpiController::class, 'updateKpiParameters']);
    Route::delete('hr/kpi/parameters/{id}', [KpiController::class, 'deleteKpiParameter']);
    Route::get('hr/kpi/tracker', [KpiController::class, 'getAllKpiTracker']);
    Route::get('hr/kpi/tracker/mine', [KpiController::class, 'getMyKpiTracker']);
    Route::get('hr/kpi/tracker/entry', [KpiController::class, 'getKpiTracker']);
    Route::post('hr/kpi/tracker', [KpiController::class, 'updateKpiTracker']);

    // ─── Batch 8 — HR: Leaves ───────────────────────────────────────────────────

    // Leaves (legacy)
    // Leaves (clean paths)
    Route::get('hr/leaves', [LeaveController::class, 'getAllLeavesData']);
    Route::get('hr/leaves/personal', [LeaveController::class, 'getPersonalLeavesRecord']);
    Route::post('hr/leaves', [LeaveController::class, 'createLeave']);
    Route::post('hr/leaves/{id}/action', [LeaveController::class, 'leaveAction']);
    Route::post('hr/leaves/{id}/cancel', [LeaveController::class, 'cancelLeave']);
    Route::get('hr/leaves/entitlements', [LeaveController::class, 'getAllEntitlements'])->middleware('role:HR,System Admin');
    Route::get('hr/leaves/entitlements/mine', [LeaveController::class, 'getMyEntitlements']);
    Route::get('hr/leaves/entitlements/history/mine', [LeaveController::class, 'getMyEntitlementHistory']);
    Route::get('hr/leaves/entitlements/history', [LeaveController::class, 'getEntitlementHistory'])->middleware('role:HR,System Admin');
    Route::post('hr/leaves/entitlements', [LeaveController::class, 'assignLeavesEntitlement'])->middleware('role:HR,System Admin');
    Route::put('hr/leaves/entitlements/{id}', [LeaveController::class, 'updateEntitlement'])->middleware('role:HR,System Admin');
    Route::delete('hr/leaves/entitlements/{id}', [LeaveController::class, 'deleteEntitlement'])->middleware('role:HR,System Admin');

    Route::get('hr/salary/profile', [SalaryController::class, 'profile']);
    Route::put('hr/salary/profile', [SalaryController::class, 'updateProfile']);
    Route::get('hr/salary/other-claims/financial-records', [SalaryController::class, 'otherClaimFinancialRecords'])->middleware('role:HR,Manager,System Admin,Finance,Account,Bank');
    Route::get('hr/salary/other-claims/financial-records/{id}/claims-pdf', [SalaryController::class, 'otherClaimFinancialClaimsPdf'])->whereNumber('id')->middleware('role:HR,Manager,System Admin,Finance,Account,Bank');
    Route::post('hr/salary/other-claims/financial-records/{id}/action', [SalaryController::class, 'otherClaimFinancialRecordAction'])->whereNumber('id')->middleware('role:HR,Manager,System Admin,Finance,Account,Bank');
    Route::get('hr/salary/other-claims', [SalaryController::class, 'otherClaimRecords']);
    Route::post('hr/salary/other-claims', [SalaryController::class, 'storeOtherClaimApplication']);
    Route::get('hr/salary/other-claims/{id}/claims-pdf', [SalaryController::class, 'otherClaimClaimsPdf'])->whereNumber('id');
    Route::get('hr/salary/other-claims/{id}', [SalaryController::class, 'otherClaimRecord'])->whereNumber('id');
    Route::delete('hr/salary/other-claims/{id}', [SalaryController::class, 'destroyOtherClaimRecord'])->whereNumber('id');
    Route::get('hr/salary/other-claims/draft', [SalaryController::class, 'otherClaimDraftApplication']);
    Route::put('hr/salary/other-claims/draft', [SalaryController::class, 'storeOtherClaimDraftApplication']);
    Route::delete('hr/salary/other-claims/draft', [SalaryController::class, 'destroyOtherClaimDraftApplication']);
    Route::post('hr/salary/other-claims/applications', [SalaryController::class, 'storeOtherClaimApplication']);
    Route::get('hr/salary/other-claim-attachments/{id}', [SalaryController::class, 'otherClaimAttachment'])->whereNumber('id');
    Route::get('hr/salary/financial-records', [SalaryController::class, 'financialRecords'])->middleware('role:HR,Manager,System Admin,Finance,Account,Bank');
    Route::get('hr/salary/financial-records/{id}/claims-pdf', [SalaryController::class, 'financialClaimsPdf'])->whereNumber('id')->middleware('role:HR,Manager,System Admin,Finance,Account,Bank');
    Route::get('hr/salary/financial-records/{id}/payslip-pdf', [SalaryController::class, 'financialPayslipPdf'])->whereNumber('id')->middleware('role:HR,Manager,System Admin,Finance,Account,Bank');
    Route::post('hr/salary/financial-records/{id}/action', [SalaryController::class, 'financialRecordAction'])->whereNumber('id')->middleware('role:HR,Manager,System Admin,Finance,Account,Bank');
    Route::get('hr/salary/records', [SalaryController::class, 'records']);
    Route::get('hr/salary/records/{id}/claims-pdf', [SalaryController::class, 'claimsPdf'])->whereNumber('id');
    Route::get('hr/salary/records/{id}/payslip-pdf', [SalaryController::class, 'payslipPdf'])->whereNumber('id');
    Route::get('hr/salary/records/{id}', [SalaryController::class, 'record'])->whereNumber('id');
    Route::delete('hr/salary/records/{id}', [SalaryController::class, 'destroyRecord'])->whereNumber('id');
    Route::get('hr/salary/applications/draft', [SalaryController::class, 'draftApplication']);
    Route::put('hr/salary/applications/draft', [SalaryController::class, 'storeDraftApplication']);
    Route::delete('hr/salary/applications/draft', [SalaryController::class, 'destroyDraftApplication']);
    Route::post('hr/salary/applications', [SalaryController::class, 'storeApplication']);
    Route::get('hr/salary/attachments/{id}', [SalaryController::class, 'attachment'])->whereNumber('id');

    // ─── Batch 5 — Project (legacy paths) ──────────────────────────────────────

    // ─── Batch 5 — Project (clean paths) ───────────────────────────────────────

    Route::get('projects', [ProjectController::class, 'index']);
    Route::post('projects', [ProjectController::class, 'store']);
    Route::get('projects/options', [ProjectController::class, 'options']);
    Route::get('projects/{id}', [ProjectController::class, 'show']);
    Route::put('projects/{id}', [ProjectController::class, 'update']);
    Route::delete('projects/{id}', [ProjectController::class, 'destroy']);
    Route::post('projects/{id}/close', [ProjectController::class, 'close']);
    Route::post('projects/{id}/reload-po', [ProjectController::class, 'reloadPoNumber']);
    Route::post('projects/{id}/value/impact-preview', [ProjectController::class, 'previewValueImpact']);
    Route::patch('projects/{id}/value', [ProjectController::class, 'updateValue']);
    Route::get('projects/{id}/crm', [ProjectController::class, 'crmDetails']);
    Route::get('projects/{id}/commercial-docs', [ProjectController::class, 'commercialDocs']);
    Route::get('projects/{id}/collaborators', [ProjectController::class, 'listCollaborators']);
    Route::post('projects/{id}/collaborators', [ProjectController::class, 'addCollaborator']);
    Route::delete('projects/{id}/collaborators/{staffId}', function (Request $request, ProjectController $c, int $id, int $staffId) {
        $request->merge(['project_id' => $id, 'staff_id' => $staffId]);

        return $c->removeCollaborator($request);
    });
    Route::get('projects/{id}/vendors', [ProjectController::class, 'listVendors']);
    Route::post('projects/{id}/vendors', [ProjectController::class, 'assignVendor']);
    Route::put('projects/{id}/vendors/{assignmentId}', function (Request $request, ProjectController $c, int $id, int $assignmentId) {
        $request->merge(['project_id' => $id, 'assignment_id' => $assignmentId]);

        return $c->updateVendor($request);
    });
    Route::delete('projects/{id}/vendors/{assignmentId}', function (Request $request, ProjectController $c, int $id, int $assignmentId) {
        $request->merge(['project_id' => $id, 'assignment_id' => $assignmentId]);

        return $c->removeVendor($request);
    });
    Route::get('projects/{id}/loa', [ProjectController::class, 'generateLoa']);
    Route::get('projects/{id}/finance', [ProjectController::class, 'financeData']);
    Route::post('projects/{id}/expenses', [ProjectController::class, 'addExpense']);
    Route::delete('projects/{id}/expenses/{expenseId}', function (Request $request, ProjectController $c, int $id, int $expenseId) {
        $request->merge(['project_id' => $id, 'expense_id' => $expenseId]);

        return $c->deleteExpense($request);
    });
    Route::get('projects/{id}/progress', [ProjectController::class, 'listProgress']);
    Route::post('projects/{id}/progress', [ProjectController::class, 'addProgress']);
    Route::put('projects/{id}/progress/{progressId}', [ProjectController::class, 'updateProgress']);
    Route::delete('projects/{id}/progress/{progressId}', function (Request $request, ProjectController $c, int $id, int $progressId) {
        $request->merge(['project_id' => $id, 'progress_id' => $progressId]);

        return $c->deleteProgress($request);
    });
    Route::get('projects/vendors/all', [ProjectController::class, 'allVendors']);

    // ─── Batch 5 — Quote Records (clean paths) ──────────────────────────────────

    // Equipment
    Route::get('quote-records/equipment', [QuoteRecordController::class, 'listEquipment']);
    Route::post('quote-records/equipment/{id}/follow-up', [QuoteRecordController::class, 'addEquipmentFollowUp']);
    Route::post('quote-records/equipment/{id}/award', [QuoteRecordController::class, 'awardEquipment']);
    Route::post('quote-records/equipment/{id}/fail', [QuoteRecordController::class, 'failEquipment']);
    Route::post('quote-records/equipment/{id}/re-award', [QuoteRecordController::class, 'reAwardEquipment']);
    Route::post('quote-records/equipment/{id}/un-award', [QuoteRecordController::class, 'unAwardEquipment']);
    Route::delete('quote-records/equipment/{id}', [QuoteRecordController::class, 'destroyEquipment']);
    Route::get('quote-records/equipment/{id}/related-docs', [QuoteRecordController::class, 'relatedDocsEquipment']);
    Route::get('quote-records/equipment/{id}/pdf', [QuoteRecordController::class, 'pdfEquipment']);
    Route::post('quote-records/equipment/{id}/sync-client', [QuoteRecordController::class, 'syncClientEquipment']);

    // IH
    Route::get('quote-records/ih', [QuoteRecordController::class, 'listIh']);
    Route::post('quote-records/ih/{id}/follow-up', [QuoteRecordController::class, 'addIhFollowUp']);
    Route::post('quote-records/ih/{id}/award', [QuoteRecordController::class, 'awardIh']);
    Route::post('quote-records/ih/{id}/fail', [QuoteRecordController::class, 'failIh']);
    Route::post('quote-records/ih/{id}/re-award', [QuoteRecordController::class, 'reAwardIh']);
    Route::post('quote-records/ih/{id}/un-award', [QuoteRecordController::class, 'unAwardIh']);
    Route::delete('quote-records/ih/{id}', [QuoteRecordController::class, 'destroyIh']);
    Route::get('quote-records/ih/{id}/related-docs', [QuoteRecordController::class, 'relatedDocsIh']);
    Route::get('quote-records/ih/{id}/pdf', [QuoteRecordController::class, 'pdfIh']);
    Route::post('quote-records/ih/{id}/sync-client', [QuoteRecordController::class, 'syncClientIh']);

    // Manpower
    Route::get('quote-records/manpower', [QuoteRecordController::class, 'listManpower']);
    Route::post('quote-records/manpower/{id}/follow-up', [QuoteRecordController::class, 'addManpowerFollowUp']);
    Route::post('quote-records/manpower/{id}/award', [QuoteRecordController::class, 'awardManpower']);
    Route::post('quote-records/manpower/{id}/fail', [QuoteRecordController::class, 'failManpower']);
    Route::post('quote-records/manpower/{id}/re-award', [QuoteRecordController::class, 'reAwardManpower']);
    Route::post('quote-records/manpower/{id}/un-award', [QuoteRecordController::class, 'unAwardManpower']);
    Route::delete('quote-records/manpower/{id}', [QuoteRecordController::class, 'destroyManpower']);
    Route::get('quote-records/manpower/{id}/related-docs', [QuoteRecordController::class, 'relatedDocsManpower']);
    Route::get('quote-records/manpower/{id}/pdf', [QuoteRecordController::class, 'pdfManpower']);
    Route::post('quote-records/manpower/{id}/sync-client', [QuoteRecordController::class, 'syncClientManpower']);

    // Training
    Route::get('quote-records/training', [QuoteRecordTrainingSpecialController::class, 'listTraining']);
    Route::post('quote-records/training/{id}/follow-up', [QuoteRecordTrainingSpecialController::class, 'addTrainingFollowUp']);
    Route::post('quote-records/training/{id}/award', [QuoteRecordTrainingSpecialController::class, 'awardTraining']);
    Route::post('quote-records/training/{id}/fail', [QuoteRecordTrainingSpecialController::class, 'failTraining']);
    Route::post('quote-records/training/{id}/re-award', [QuoteRecordTrainingSpecialController::class, 'reAwardTraining']);
    Route::post('quote-records/training/{id}/un-award', [QuoteRecordTrainingSpecialController::class, 'unAwardTraining']);
    Route::delete('quote-records/training/{id}', [QuoteRecordTrainingSpecialController::class, 'destroyTraining']);
    Route::get('quote-records/training/{id}/related-docs', [QuoteRecordTrainingSpecialController::class, 'relatedDocsTraining']);
    Route::get('quote-records/training/{id}/pdf', [QuoteRecordTrainingSpecialController::class, 'pdfTraining']);
    Route::post('quote-records/training/{id}/sync-client', [QuoteRecordTrainingSpecialController::class, 'syncClientTraining']);

    // Special
    Route::get('quote-records/special', [QuoteRecordTrainingSpecialController::class, 'listSpecial']);
    Route::get('quote-records/special/line-items', [QuoteRecordTrainingSpecialController::class, 'specialLineItemsByService']);
    Route::post('quote-records/special/{id}/follow-up', [QuoteRecordTrainingSpecialController::class, 'addSpecialFollowUp']);
    Route::post('quote-records/special/{id}/award', [QuoteRecordTrainingSpecialController::class, 'awardSpecial']);
    Route::post('quote-records/special/{id}/fail', [QuoteRecordTrainingSpecialController::class, 'failSpecial']);
    Route::post('quote-records/special/{id}/re-award', [QuoteRecordTrainingSpecialController::class, 'reAwardSpecial']);
    Route::post('quote-records/special/{id}/un-award', [QuoteRecordTrainingSpecialController::class, 'unAwardSpecial']);
    Route::delete('quote-records/special/{id}', [QuoteRecordTrainingSpecialController::class, 'destroySpecial']);
    Route::get('quote-records/special/{id}/related-docs', [QuoteRecordTrainingSpecialController::class, 'relatedDocsSpecial']);
    Route::get('quote-records/special/{id}/pdf', [QuoteRecordTrainingSpecialController::class, 'pdfSpecial']);
    Route::post('quote-records/special/{id}/sync-client', [QuoteRecordTrainingSpecialController::class, 'syncClientSpecial']);

    // ─── Batch 8 — Google Places & Contacts (legacy paths) ──────────────────────

    // ─── Batch 8 — Google Places & Contacts (clean REST) ────────────────────────

    Route::get('google/place-details', [GoogleController::class, 'placeDetails']);
    Route::get('google/places/unregistered', [GoogleController::class, 'listUnregisteredPlaces']);
    Route::post('google/places/seed', [GoogleController::class, 'seedPlaces']);
    Route::get('google/contacts', [GoogleController::class, 'listContacts']);
    Route::post('google/contacts', [GoogleController::class, 'registerContact']);
    Route::put('google/contacts/{id}', [GoogleController::class, 'updateContact']);
    Route::delete('google/contacts/{id}', [GoogleController::class, 'deleteContact']);
    Route::get('google/contacts/{id}/calls', [GoogleController::class, 'listCalls']);
    Route::post('google/contacts/{id}/calls', [GoogleController::class, 'createCall']);
    Route::delete('google/calls/{id}', [GoogleController::class, 'deleteCall']);
    Route::get('google/contacts-with-calls', [GoogleController::class, 'listContactsWithCalls']);
    Route::get('google/call-statistics', [GoogleController::class, 'callStatistics']);

    // ─── Batch 7 — Stats (legacy paths) ─────────────────────────────────────────

    // ─── Batch 7 — Stats (clean REST) ───────────────────────────────────────────

    Route::match(['get', 'post'], 'stats/inquiry', [StatsController::class, 'inquiryStats']);
    Route::match(['get', 'post'], 'stats/inquiry-by-values', [StatsController::class, 'inquiryStatsByValues']);
    Route::match(['get', 'post'], 'stats/quote-value-by-person', [StatsController::class, 'quoteValueByPerson']);
    Route::match(['get', 'post'], 'stats/quote-value-by-service', [StatsController::class, 'quoteValueByService']);
    Route::match(['get', 'post'], 'stats/monthly-quote-value-by-service', [StatsController::class, 'monthlyQuoteValueByService']);
    Route::match(['get', 'post'], 'stats/awarded-value-by-person', [StatsController::class, 'awardedValueByPerson']);
    Route::match(['get', 'post'], 'stats/awarded-value-by-source', [StatsController::class, 'awardedValueBySource']);
    Route::match(['get', 'post'], 'stats/monthly-income-statement', [StatsController::class, 'monthlyIncomeStatement']);
    Route::match(['get', 'post'], 'stats/monthly-invoiced-received-trend', [StatsController::class, 'monthlyInvoicedReceivedTrend']);
    Route::match(['get', 'post'], 'stats/awarded-value-by-service', [StatsController::class, 'awardedValueByService']);
    Route::match(['get', 'post'], 'stats/quote-count-by-person', [StatsController::class, 'quoteCountByPerson']);
    Route::match(['get', 'post'], 'stats/conversion-rate-by-source', [StatsController::class, 'conversionRateBySource']);
    Route::match(['get', 'post'], 'stats/conversion-rate-by-service', [StatsController::class, 'conversionRateByService']);
    Route::match(['get', 'post'], 'stats/debtors', [StatsController::class, 'allDebtors']);
    Route::match(['get', 'post'], 'stats/monthly-quote-value', [StatsController::class, 'monthlyQuoteValue']);
    Route::match(['get', 'post'], 'stats/conversion-rate-by-staff', [StatsController::class, 'conversionRateByStaff']);
    Route::match(['get', 'post'], 'stats/monthly-quote-count', [StatsController::class, 'monthlyQuoteCount']);
    Route::match(['get', 'post'], 'stats/monthly-sales', [StatsController::class, 'monthlySales']);
    Route::get('stats/monthly-dashboard-report/pdf', [StatsController::class, 'monthlyDashboardReportPdf'])->middleware('role:Manager,System Admin');
    Route::get('stats/workload/pdf', [StatsController::class, 'workloadPdf']);
    Route::post('stats/workload/share', [StatsController::class, 'createWorkloadShare']);
    Route::match(['get', 'post'], 'stats/workload/history', [StatsController::class, 'workloadHistory']);
    Route::get('stats/workload/snapshot-health', [StatsController::class, 'workloadSnapshotHealth'])->middleware('role:System Admin');
    Route::match(['get', 'post'], 'stats/workload', [StatsController::class, 'workload']);
    Route::match(['get', 'post'], 'stats/monitoring-pipeline-tools', [StatsController::class, 'monitoringPipelineTools']);
    Route::match(['get', 'post'], 'stats/monitoring-pipeline-status', [StatsController::class, 'monitoringPipelineStatus']);
    Route::match(['get', 'post'], 'stats/monitoring-trends', [StatsController::class, 'monitoringTrends']);
    Route::match(['get', 'post'], 'stats/monitoring-staff-pipeline-matrix', [StatsController::class, 'monitoringStaffPipelineMatrix']);
    Route::match(['get', 'post'], 'stats/monitoring-staff-options', [StatsController::class, 'monitoringStaffOptions']);
    Route::match(['get', 'post'], 'stats/monitoring-manual-pipeline-entries', [StatsController::class, 'monitoringManualPipelineEntries']);
    Route::post('stats/monitoring-manual-pipeline-entry', [StatsController::class, 'createMonitoringManualPipelineEntry']);
    Route::get('stats/monitoring-manual-pipeline-entry/{id}', [StatsController::class, 'monitoringManualPipelineEntry']);
    Route::match(['post', 'put'], 'stats/monitoring-manual-pipeline-entry/{id}', [StatsController::class, 'updateMonitoringManualPipelineEntry']);
    Route::delete('stats/monitoring-manual-pipeline-entry/{id}', [StatsController::class, 'deleteMonitoringManualPipelineEntry']);
    Route::get('stats/monitoring-manual-pipeline-entry/{id}/photo', [StatsController::class, 'viewMonitoringManualPipelineEntryPhoto']);

    // ─── Batch 6 — Invoices (legacy paths) ──────────────────────────────────────

    // ─── Batch 6 — Invoices (clean REST) ────────────────────────────────────────

    Route::get('invoices', [InvoiceController::class, 'index']);
    Route::post('invoices', [InvoiceController::class, 'store']);
    Route::put('invoices', [InvoiceController::class, 'update']);
    Route::delete('invoices', [InvoiceController::class, 'destroy']);
    Route::patch('invoices/{id}/mark-paid', [InvoiceController::class, 'markPaid']);
    Route::patch('invoices/{id}/mark-unpaid', [InvoiceController::class, 'markUnpaid']);
    Route::get('invoices/latest-by-project', [InvoiceController::class, 'latestByProject']);
    Route::patch('invoices/{id}/hrd-claim-ref', [InvoiceController::class, 'updateHrdClaimRef']);
    Route::get('invoices/{id}/pdf', [InvoiceController::class, 'invoicePdf']);
    Route::get('invoices/{id}/receipt-pdf', [InvoiceController::class, 'receiptPdf']);
    Route::get('invoices/quote/training/{id}', [InvoiceController::class, 'quoteTraining']);
    Route::get('invoices/quote/equipment/{id}', [InvoiceController::class, 'quoteEquipment']);
    Route::get('invoices/quote/manpower/{id}', [InvoiceController::class, 'quoteManpower']);
    Route::get('invoices/quote/ih/{id}', [InvoiceController::class, 'quoteIh']);
    Route::get('invoices/quote/special/{id}', [InvoiceController::class, 'quoteSpecial']);
    Route::get('jd14-forms', [InvoiceController::class, 'listJd14']);
    Route::post('jd14-forms', [InvoiceController::class, 'storeJd14']);
    Route::put('jd14-forms/{id}', [InvoiceController::class, 'updateJd14']);
    Route::delete('jd14-forms/{id}', [InvoiceController::class, 'destroyJd14']);
    Route::get('jd14-forms/by-project', [InvoiceController::class, 'jd14ByProject']);
    Route::get('jd14-forms/{id}/pdf', [InvoiceController::class, 'jd14Pdf']);
});
