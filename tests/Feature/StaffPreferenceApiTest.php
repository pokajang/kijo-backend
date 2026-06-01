<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\StaffPreferenceController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StaffPreferenceApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('staff_preferences');
        Schema::create('staff_preferences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('preference_key', 191);
            $table->json('preference_value')->nullable();
            $table->timestamps();
            $table->unique(['staff_id', 'preference_key']);
        });
    }

    public function test_show_requires_authenticated_session(): void
    {
        $controller = app(StaffPreferenceController::class);
        $request = $this->makeRequest('GET');

        $response = $controller->show($request, 'crm-records-all-visible-columns');

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_stats_visibility_preferences_require_authenticated_session(): void
    {
        $controller = app(StaffPreferenceController::class);

        $showResponse = $controller->show(
            $this->makeRequest('GET'),
            'datatable-stats-visible.support.requests.v1',
        );
        $updateResponse = $controller->update(
            $this->makeRequest('PUT', [], ['value' => ['visible' => false]]),
            'datatable-stats-visible.support.requests.v1',
        );

        $this->assertSame(401, $showResponse->getStatusCode());
        $this->assertSame(401, $updateResponse->getStatusCode());
    }

    public function test_controls_visibility_preferences_require_authenticated_session(): void
    {
        $controller = app(StaffPreferenceController::class);

        $showResponse = $controller->show(
            $this->makeRequest('GET'),
            'datatable-controls-visible.support.requests.v1',
        );
        $updateResponse = $controller->update(
            $this->makeRequest('PUT', [], ['value' => ['visible' => false]]),
            'datatable-controls-visible.support.requests.v1',
        );

        $this->assertSame(401, $showResponse->getStatusCode());
        $this->assertSame(401, $updateResponse->getStatusCode());
    }

    public function test_show_returns_stats_visibility_default_when_preference_missing(): void
    {
        $controller = app(StaffPreferenceController::class);

        $response = $controller->show(
            $this->makeRequest('GET', ['user_id' => 1, 'staff_id' => 99]),
            'datatable-stats-visible.support.requests.v1',
        );
        $body = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('datatable-stats-visible.support.requests.v1', $body['data']['key']);
        $this->assertFalse($body['data']['found']);
        $this->assertTrue($body['data']['value']['visible']);
    }

    public function test_stats_visibility_page_preference_saves_and_loads(): void
    {
        $controller = app(StaffPreferenceController::class);

        $updateResponse = $controller->update(
            $this->makeRequest('PUT', ['user_id' => 1, 'staff_id' => 99], [
                'value' => ['visible' => false],
            ]),
            'datatable-stats-visible.support.requests.v1',
        );
        $updateBody = $updateResponse->getData(true);

        $this->assertSame(200, $updateResponse->getStatusCode());
        $this->assertFalse($updateBody['data']['value']['visible']);
        $this->assertDatabaseHas('staff_preferences', [
            'staff_id' => 99,
            'preference_key' => 'datatable.statsVisible.support.requests.v1',
        ]);

        $showResponse = $controller->show(
            $this->makeRequest('GET', ['user_id' => 1, 'staff_id' => 99]),
            'datatable-stats-visible.support.requests.v1',
        );
        $showBody = $showResponse->getData(true);

        $this->assertSame(200, $showResponse->getStatusCode());
        $this->assertTrue($showBody['data']['found']);
        $this->assertFalse($showBody['data']['value']['visible']);
    }

    public function test_show_returns_controls_visibility_default_when_preference_missing(): void
    {
        $controller = app(StaffPreferenceController::class);

        $response = $controller->show(
            $this->makeRequest('GET', ['user_id' => 1, 'staff_id' => 99]),
            'datatable-controls-visible.support.requests.v1',
        );
        $body = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('datatable-controls-visible.support.requests.v1', $body['data']['key']);
        $this->assertFalse($body['data']['found']);
        $this->assertTrue($body['data']['value']['visible']);
    }

    public function test_systemwide_stats_visibility_clears_page_overrides_for_same_staff(): void
    {
        $controller = app(StaffPreferenceController::class);
        $now = now();
        DB::table('staff_preferences')->insert([
            [
                'staff_id' => 99,
                'preference_key' => 'datatable.statsVisible.support.requests.v1',
                'preference_value' => json_encode(['visible' => false]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'staff_id' => 99,
                'preference_key' => 'datatable.statsVisible.staff.tasks.v1',
                'preference_value' => json_encode(['visible' => false]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'staff_id' => 100,
                'preference_key' => 'datatable.statsVisible.support.requests.v1',
                'preference_value' => json_encode(['visible' => false]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $response = $controller->update(
            $this->makeRequest('PUT', ['user_id' => 1, 'staff_id' => 99], [
                'value' => ['visible' => true],
            ]),
            'datatable-stats-visible.systemwide.v1',
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseHas('staff_preferences', [
            'staff_id' => 99,
            'preference_key' => 'datatable.statsVisible.systemwide.v1',
        ]);
        $this->assertDatabaseMissing('staff_preferences', [
            'staff_id' => 99,
            'preference_key' => 'datatable.statsVisible.support.requests.v1',
        ]);
        $this->assertDatabaseMissing('staff_preferences', [
            'staff_id' => 99,
            'preference_key' => 'datatable.statsVisible.staff.tasks.v1',
        ]);
        $this->assertDatabaseHas('staff_preferences', [
            'staff_id' => 100,
            'preference_key' => 'datatable.statsVisible.support.requests.v1',
        ]);
    }

    public function test_controls_visibility_page_preference_saves_and_loads(): void
    {
        $controller = app(StaffPreferenceController::class);

        $updateResponse = $controller->update(
            $this->makeRequest('PUT', ['user_id' => 1, 'staff_id' => 99], [
                'value' => ['visible' => false],
            ]),
            'datatable-controls-visible.support.requests.v1',
        );
        $updateBody = $updateResponse->getData(true);

        $this->assertSame(200, $updateResponse->getStatusCode());
        $this->assertFalse($updateBody['data']['value']['visible']);
        $this->assertDatabaseHas('staff_preferences', [
            'staff_id' => 99,
            'preference_key' => 'datatable.controlsVisible.support.requests.v1',
        ]);

        $showResponse = $controller->show(
            $this->makeRequest('GET', ['user_id' => 1, 'staff_id' => 99]),
            'datatable-controls-visible.support.requests.v1',
        );
        $showBody = $showResponse->getData(true);

        $this->assertSame(200, $showResponse->getStatusCode());
        $this->assertTrue($showBody['data']['found']);
        $this->assertFalse($showBody['data']['value']['visible']);
    }

    public function test_systemwide_controls_visibility_clears_page_overrides_for_same_staff(): void
    {
        $controller = app(StaffPreferenceController::class);
        $now = now();
        DB::table('staff_preferences')->insert([
            [
                'staff_id' => 99,
                'preference_key' => 'datatable.controlsVisible.support.requests.v1',
                'preference_value' => json_encode(['visible' => true]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'staff_id' => 99,
                'preference_key' => 'datatable.controlsVisible.staff.tasks.v1',
                'preference_value' => json_encode(['visible' => true]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'staff_id' => 100,
                'preference_key' => 'datatable.controlsVisible.support.requests.v1',
                'preference_value' => json_encode(['visible' => true]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $response = $controller->update(
            $this->makeRequest('PUT', ['user_id' => 1, 'staff_id' => 99], [
                'value' => ['visible' => false],
            ]),
            'datatable-controls-visible.systemwide.v1',
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseHas('staff_preferences', [
            'staff_id' => 99,
            'preference_key' => 'datatable.controlsVisible.systemwide.v1',
        ]);
        $this->assertDatabaseMissing('staff_preferences', [
            'staff_id' => 99,
            'preference_key' => 'datatable.controlsVisible.support.requests.v1',
        ]);
        $this->assertDatabaseMissing('staff_preferences', [
            'staff_id' => 99,
            'preference_key' => 'datatable.controlsVisible.staff.tasks.v1',
        ]);
        $this->assertDatabaseHas('staff_preferences', [
            'staff_id' => 100,
            'preference_key' => 'datatable.controlsVisible.support.requests.v1',
        ]);
    }

    public function test_show_returns_defaults_when_preference_missing(): void
    {
        $controller = app(StaffPreferenceController::class);
        $request = $this->makeRequest('GET', ['user_id' => 1, 'staff_id' => 99]);

        $response = $controller->show($request, 'crm-records-all-visible-columns');
        $body = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('success', $body['status'] ?? null);
        $this->assertSame('crm-records-all-visible-columns', $body['data']['key'] ?? null);
        $this->assertTrue($body['data']['value']['quotationId'] ?? false);
        $this->assertTrue($body['data']['value']['client'] ?? false);
        $this->assertTrue($body['data']['value']['status'] ?? false);
        $this->assertTrue($body['data']['value']['service'] ?? false);
    }

    public function test_update_persists_preference_and_enforces_required_columns(): void
    {
        $controller = app(StaffPreferenceController::class);

        $updateRequest = $this->makeRequest('PUT', ['user_id' => 1, 'staff_id' => 99], [
            'value' => [
                'service' => false,
                'client' => false,
                'quotationId' => false,
                'status' => false,
                'email' => false,
                'remarks' => true,
            ],
        ]);

        $updateResponse = $controller->update($updateRequest, 'crm-records-all-visible-columns');
        $updateBody = $updateResponse->getData(true);

        $this->assertSame(200, $updateResponse->getStatusCode());
        $this->assertFalse($updateBody['data']['value']['service'] ?? true);
        $this->assertFalse($updateBody['data']['value']['email'] ?? true);
        $this->assertTrue($updateBody['data']['value']['client'] ?? false);
        $this->assertTrue($updateBody['data']['value']['quotationId'] ?? false);
        $this->assertTrue($updateBody['data']['value']['status'] ?? false);

        $showRequest = $this->makeRequest('GET', ['user_id' => 1, 'staff_id' => 99]);
        $showResponse = $controller->show($showRequest, 'crm-records-all-visible-columns');
        $showBody = $showResponse->getData(true);

        $this->assertSame(200, $showResponse->getStatusCode());
        $this->assertFalse($showBody['data']['value']['service'] ?? true);
        $this->assertFalse($showBody['data']['value']['email'] ?? true);
        $this->assertTrue($showBody['data']['value']['client'] ?? false);
        $this->assertTrue($showBody['data']['value']['quotationId'] ?? false);
        $this->assertTrue($showBody['data']['value']['status'] ?? false);
    }

    public function test_handbook_acknowledgement_column_preferences_are_supported(): void
    {
        $controller = app(StaffPreferenceController::class);

        $updateRequest = $this->makeRequest('PUT', ['user_id' => 1, 'staff_id' => 99], [
            'value' => [
                'version' => false,
                'fullName' => false,
                'signedAt' => false,
                'ipAddress' => false,
                'userAgent' => true,
                'unsupportedColumn' => false,
            ],
        ]);

        $updateResponse = $controller->update(
            $updateRequest,
            'handbook-acknowledgements-visible-columns',
        );
        $updateBody = $updateResponse->getData(true);

        $this->assertSame(200, $updateResponse->getStatusCode());
        $this->assertSame('handbook-acknowledgements-visible-columns', $updateBody['data']['key']);
        $this->assertFalse($updateBody['data']['value']['version']);
        $this->assertFalse($updateBody['data']['value']['ipAddress']);
        $this->assertTrue($updateBody['data']['value']['fullName']);
        $this->assertTrue($updateBody['data']['value']['signedAt']);
        $this->assertArrayNotHasKey('unsupportedColumn', $updateBody['data']['value']);

        $showRequest = $this->makeRequest('GET', ['user_id' => 1, 'staff_id' => 99]);
        $showResponse = $controller->show($showRequest, 'handbook-acknowledgements-visible-columns');
        $showBody = $showResponse->getData(true);

        $this->assertSame(200, $showResponse->getStatusCode());
        $this->assertFalse($showBody['data']['value']['version']);
        $this->assertFalse($showBody['data']['value']['ipAddress']);
        $this->assertTrue($showBody['data']['value']['fullName']);
        $this->assertTrue($showBody['data']['value']['signedAt']);
    }

    public function test_handbook_change_log_column_preferences_are_supported(): void
    {
        $controller = app(StaffPreferenceController::class);

        $updateRequest = $this->makeRequest('PUT', ['user_id' => 1, 'staff_id' => 99], [
            'value' => [
                'version' => false,
                'action' => false,
                'section' => false,
                'summary' => false,
                'changedBy' => false,
                'changedAt' => false,
                'unsupportedColumn' => false,
            ],
        ]);

        $updateResponse = $controller->update(
            $updateRequest,
            'handbook-change-log-visible-columns',
        );
        $updateBody = $updateResponse->getData(true);

        $this->assertSame(200, $updateResponse->getStatusCode());
        $this->assertSame('handbook-change-log-visible-columns', $updateBody['data']['key']);
        $this->assertFalse($updateBody['data']['value']['action']);
        $this->assertFalse($updateBody['data']['value']['section']);
        $this->assertFalse($updateBody['data']['value']['changedBy']);
        $this->assertTrue($updateBody['data']['value']['version']);
        $this->assertTrue($updateBody['data']['value']['summary']);
        $this->assertTrue($updateBody['data']['value']['changedAt']);
        $this->assertArrayNotHasKey('unsupportedColumn', $updateBody['data']['value']);

        $showRequest = $this->makeRequest('GET', ['user_id' => 1, 'staff_id' => 99]);
        $showResponse = $controller->show($showRequest, 'handbook-change-log-visible-columns');
        $showBody = $showResponse->getData(true);

        $this->assertSame(200, $showResponse->getStatusCode());
        $this->assertFalse($showBody['data']['value']['action']);
        $this->assertFalse($showBody['data']['value']['section']);
        $this->assertFalse($showBody['data']['value']['changedBy']);
        $this->assertTrue($showBody['data']['value']['version']);
        $this->assertTrue($showBody['data']['value']['summary']);
        $this->assertTrue($showBody['data']['value']['changedAt']);
    }

    public function test_meeting_record_column_preferences_are_supported(): void
    {
        $controller = app(StaffPreferenceController::class);

        $updateRequest = $this->makeRequest('PUT', ['user_id' => 1, 'staff_id' => 99], [
            'value' => [
                'title' => false,
                'meetingDate' => false,
                'meetingType' => false,
                'pendingItems' => false,
                'unsupportedColumn' => false,
            ],
        ]);

        $updateResponse = $controller->update($updateRequest, 'meetings-records-visible-columns');
        $updateBody = $updateResponse->getData(true);

        $this->assertSame(200, $updateResponse->getStatusCode());
        $this->assertSame('meetings-records-visible-columns', $updateBody['data']['key']);
        $this->assertTrue($updateBody['data']['value']['title']);
        $this->assertFalse($updateBody['data']['value']['meetingDate']);
        $this->assertFalse($updateBody['data']['value']['meetingType']);
        $this->assertTrue($updateBody['data']['value']['pendingItems']);
        $this->assertArrayNotHasKey('unsupportedColumn', $updateBody['data']['value']);

        $showRequest = $this->makeRequest('GET', ['user_id' => 1, 'staff_id' => 99]);
        $showResponse = $controller->show($showRequest, 'meetings-records-visible-columns');
        $showBody = $showResponse->getData(true);

        $this->assertSame(200, $showResponse->getStatusCode());
        $this->assertTrue($showBody['data']['value']['title']);
        $this->assertFalse($showBody['data']['value']['meetingDate']);
        $this->assertFalse($showBody['data']['value']['meetingType']);
        $this->assertTrue($showBody['data']['value']['pendingItems']);
    }

    public function test_versioned_meeting_record_column_preferences_are_supported(): void
    {
        $controller = app(StaffPreferenceController::class);

        $updateRequest = $this->makeRequest('PUT', ['user_id' => 1, 'staff_id' => 99], [
            'value' => [
                'title' => false,
                'meetingDate' => false,
                'meetingType' => false,
                'pendingItems' => false,
            ],
        ]);

        $updateResponse = $controller->update($updateRequest, 'meetings-records-visible-columns-v3');
        $updateBody = $updateResponse->getData(true);

        $this->assertSame(200, $updateResponse->getStatusCode());
        $this->assertSame('meetings-records-visible-columns-v3', $updateBody['data']['key']);
        $this->assertTrue($updateBody['data']['value']['title']);
        $this->assertFalse($updateBody['data']['value']['meetingDate']);
        $this->assertFalse($updateBody['data']['value']['meetingType']);
        $this->assertTrue($updateBody['data']['value']['pendingItems']);
        $this->assertDatabaseHas('staff_preferences', [
            'staff_id' => 99,
            'preference_key' => 'meetings.records.visibleColumns.v3',
        ]);

        $showRequest = $this->makeRequest('GET', ['user_id' => 1, 'staff_id' => 99]);
        $showResponse = $controller->show($showRequest, 'meetings-records-visible-columns-v3');
        $showBody = $showResponse->getData(true);

        $this->assertSame(200, $showResponse->getStatusCode());
        $this->assertFalse($showBody['data']['value']['meetingDate']);
        $this->assertFalse($showBody['data']['value']['meetingType']);
    }

    public function test_versioned_service_record_column_preferences_are_supported(): void
    {
        $controller = app(StaffPreferenceController::class);

        $updateRequest = $this->makeRequest('PUT', ['user_id' => 1, 'staff_id' => 99], [
            'value' => [
                'quotationId' => false,
                'client' => false,
                'email' => false,
                'status' => false,
                'amount' => false,
            ],
        ]);

        $updateResponse = $controller->update(
            $updateRequest,
            'crm-records-service-visible-columns.audit.v3',
        );
        $updateBody = $updateResponse->getData(true);

        $this->assertSame(200, $updateResponse->getStatusCode());
        $this->assertSame('crm-records-service-visible-columns.audit.v3', $updateBody['data']['key']);
        $this->assertTrue($updateBody['data']['value']['quotationId']);
        $this->assertTrue($updateBody['data']['value']['client']);
        $this->assertTrue($updateBody['data']['value']['status']);
        $this->assertFalse($updateBody['data']['value']['email']);
        $this->assertFalse($updateBody['data']['value']['amount']);
        $this->assertDatabaseHas('staff_preferences', [
            'staff_id' => 99,
            'preference_key' => 'crm.records.service.visibleColumns.audit.v3',
        ]);
    }

    public function test_malformed_versioned_preference_key_is_rejected(): void
    {
        $controller = app(StaffPreferenceController::class);
        $request = $this->makeRequest('GET', ['user_id' => 1, 'staff_id' => 99]);

        $response = $controller->show($request, 'meetings-records-visible-columns-vnext');

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_malformed_stats_visibility_preference_key_is_rejected(): void
    {
        $controller = app(StaffPreferenceController::class);
        $request = $this->makeRequest('GET', ['user_id' => 1, 'staff_id' => 99]);

        $response = $controller->show($request, 'datatable-stats-visible.support.requests');

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_malformed_controls_visibility_preference_key_is_rejected(): void
    {
        $controller = app(StaffPreferenceController::class);
        $request = $this->makeRequest('GET', ['user_id' => 1, 'staff_id' => 99]);

        $response = $controller->show($request, 'datatable-controls-visible.support.requests');

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_procedure_record_column_preferences_are_supported(): void
    {
        $controller = app(StaffPreferenceController::class);

        $updateRequest = $this->makeRequest('PUT', ['user_id' => 1, 'staff_id' => 99], [
            'value' => [
                'title' => false,
                'category' => false,
                'description' => false,
                'date' => false,
                'createdBy' => true,
                'unsupportedColumn' => false,
            ],
        ]);

        $updateResponse = $controller->update($updateRequest, 'procedure-records-visible-columns');
        $updateBody = $updateResponse->getData(true);

        $this->assertSame(200, $updateResponse->getStatusCode());
        $this->assertSame('procedure-records-visible-columns', $updateBody['data']['key']);
        $this->assertTrue($updateBody['data']['value']['title']);
        $this->assertTrue($updateBody['data']['value']['category']);
        $this->assertFalse($updateBody['data']['value']['description']);
        $this->assertFalse($updateBody['data']['value']['date']);
        $this->assertTrue($updateBody['data']['value']['createdBy']);
        $this->assertArrayNotHasKey('unsupportedColumn', $updateBody['data']['value']);

        $showRequest = $this->makeRequest('GET', ['user_id' => 1, 'staff_id' => 99]);
        $showResponse = $controller->show($showRequest, 'procedure-records-visible-columns');
        $showBody = $showResponse->getData(true);

        $this->assertSame(200, $showResponse->getStatusCode());
        $this->assertTrue($showBody['data']['value']['title']);
        $this->assertTrue($showBody['data']['value']['category']);
        $this->assertFalse($showBody['data']['value']['description']);
        $this->assertFalse($showBody['data']['value']['date']);
        $this->assertTrue($showBody['data']['value']['createdBy']);
    }

    public function test_versioned_procedure_record_column_preferences_are_supported(): void
    {
        $controller = app(StaffPreferenceController::class);

        $updateRequest = $this->makeRequest('PUT', ['user_id' => 1, 'staff_id' => 99], [
            'value' => [
                'title' => false,
                'category' => false,
                'description' => false,
                'date' => false,
                'createdBy' => false,
            ],
        ]);

        $updateResponse = $controller->update($updateRequest, 'procedure-records-visible-columns-v3');
        $updateBody = $updateResponse->getData(true);

        $this->assertSame(200, $updateResponse->getStatusCode());
        $this->assertSame('procedure-records-visible-columns-v3', $updateBody['data']['key']);
        $this->assertTrue($updateBody['data']['value']['title']);
        $this->assertTrue($updateBody['data']['value']['category']);
        $this->assertFalse($updateBody['data']['value']['description']);
        $this->assertFalse($updateBody['data']['value']['date']);
        $this->assertFalse($updateBody['data']['value']['createdBy']);
        $this->assertDatabaseHas('staff_preferences', [
            'staff_id' => 99,
            'preference_key' => 'procedure.records.visibleColumns.v3',
        ]);

        $showRequest = $this->makeRequest('GET', ['user_id' => 1, 'staff_id' => 99]);
        $showResponse = $controller->show($showRequest, 'procedure-records-visible-columns-v3');
        $showBody = $showResponse->getData(true);

        $this->assertSame(200, $showResponse->getStatusCode());
        $this->assertFalse($showBody['data']['value']['description']);
        $this->assertFalse($showBody['data']['value']['date']);
        $this->assertFalse($showBody['data']['value']['createdBy']);
    }

    public function test_versioned_task_manager_task_column_preferences_are_supported(): void
    {
        $controller = app(StaffPreferenceController::class);

        $updateRequest = $this->makeRequest('PUT', ['user_id' => 1, 'staff_id' => 99], [
            'value' => [
                'title' => false,
                'statusText' => false,
                'createdAt' => false,
                'dueDate' => true,
                'daysLapsed' => false,
                'commentSummary' => true,
                'unsupportedColumn' => false,
            ],
        ]);

        $updateResponse = $controller->update(
            $updateRequest,
            'task-manager-tasks-visible-columns-v3',
        );
        $updateBody = $updateResponse->getData(true);

        $this->assertSame(200, $updateResponse->getStatusCode());
        $this->assertSame('task-manager-tasks-visible-columns-v3', $updateBody['data']['key']);
        $this->assertTrue($updateBody['data']['value']['title']);
        $this->assertTrue($updateBody['data']['value']['statusText']);
        $this->assertFalse($updateBody['data']['value']['createdAt']);
        $this->assertTrue($updateBody['data']['value']['dueDate']);
        $this->assertFalse($updateBody['data']['value']['daysLapsed']);
        $this->assertTrue($updateBody['data']['value']['commentSummary']);
        $this->assertArrayNotHasKey('unsupportedColumn', $updateBody['data']['value']);
        $this->assertDatabaseHas('staff_preferences', [
            'staff_id' => 99,
            'preference_key' => 'taskManager.tasks.visibleColumns.v3',
        ]);

        $showRequest = $this->makeRequest('GET', ['user_id' => 1, 'staff_id' => 99]);
        $showResponse = $controller->show(
            $showRequest,
            'task-manager-tasks-visible-columns-v3',
        );
        $showBody = $showResponse->getData(true);

        $this->assertSame(200, $showResponse->getStatusCode());
        $this->assertFalse($showBody['data']['value']['createdAt']);
        $this->assertFalse($showBody['data']['value']['daysLapsed']);
    }

    public function test_task_manager_task_column_preferences_default_created_on_hidden(): void
    {
        $controller = app(StaffPreferenceController::class);

        $showRequest = $this->makeRequest('GET', ['user_id' => 1, 'staff_id' => 100]);
        $showResponse = $controller->show(
            $showRequest,
            'task-manager-tasks-visible-columns-v7',
        );
        $showBody = $showResponse->getData(true);

        $this->assertSame(200, $showResponse->getStatusCode());
        $this->assertTrue($showBody['data']['value']['title']);
        $this->assertTrue($showBody['data']['value']['statusText']);
        $this->assertFalse($showBody['data']['value']['createdAt']);
        $this->assertTrue($showBody['data']['value']['dueDate']);
        $this->assertTrue($showBody['data']['value']['daysLapsed']);
        $this->assertTrue($showBody['data']['value']['commentSummary']);
    }

    private function makeRequest(string $method, array $sessionData = [], array $payload = []): Request
    {
        $request = Request::create('/staff/preferences/test', $method, $payload);

        $session = app('session')->driver();
        $session->start();
        foreach ($sessionData as $key => $value) {
            $session->put($key, $value);
        }

        $request->setLaravelSession($session);

        return $request;
    }
}
