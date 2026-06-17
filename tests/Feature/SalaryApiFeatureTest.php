<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SalaryApiFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        $this->createSystemUsersTable();
        $this->createStaffGeneralTable();
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_05_30_110000_create_hr_salary_tables.php',
            '--realpath' => false,
        ])->run();
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_05_30_113000_add_salary_application_workflow_columns.php',
            '--realpath' => false,
        ])->run();
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_06_02_090000_create_hr_salary_year_snapshots_table.php',
            '--realpath' => false,
        ])->run();
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_05_31_220000_create_hr_other_claim_tables.php',
            '--realpath' => false,
        ])->run();
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_05_30_140000_create_workflow_tables.php',
            '--realpath' => false,
        ])->run();
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_06_17_210000_add_salary_claim_amendment_cancellation_audit.php',
            '--realpath' => false,
        ])->run();
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_06_17_220000_create_salary_payment_runs.php',
            '--realpath' => false,
        ])->run();
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_06_17_230000_harden_salary_payment_run_voids.php',
            '--realpath' => false,
        ])->run();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_profile_show_update_and_recurring_allowance_replace(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-02 09:00:00'));

        $this->actingSession()
            ->getJson('/hr/salary/profile')
            ->assertOk()
            ->assertJsonPath('profile.basicSalary', '3000')
            ->assertJsonPath('profile.previousYearSnapshot.source', 'missing')
            ->assertJsonPath('profile.previousYearSnapshot.message', '2025 snapshot not configured. Set in Salary Settings.');

        $this->actingSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->putJson('/hr/salary/profile', [
                'basic_salary' => '4200',
                'effective_month' => '2026-05',
                'default_mileage_rate' => '0.70',
                'yearly_medical_claim' => '1200',
                'notes' => 'Confirmed by HR',
                'previous_year_snapshot' => [
                    'year' => '2025',
                    'basic_salary' => '3600',
                    'allowance_total' => '240',
                    'increment_amount' => '100',
                ],
                'recurring_allowances' => [
                    ['description' => 'Phone allowance', 'amount' => '200', 'start_month' => '2026-05'],
                    ['description' => 'Internet allowance', 'amount' => '80', 'start_month' => null],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('profile.basicSalary', '4200')
            ->assertJsonPath('profile.yearlyMedicalClaim', '1200')
            ->assertJsonPath('profile.previousYearSnapshot.source', 'manual')
            ->assertJsonPath('profile.previousYearSnapshot.basicSalary', '3600')
            ->assertJsonPath('profile.previousYearSnapshot.allowanceTotal', '240')
            ->assertJsonPath('profile.previousYearSnapshot.incrementAmount', '100')
            ->assertJsonPath('profile.previousYearSnapshot.total', '3940')
            ->assertJsonPath('profile.recurringAllowances.0.description', 'Phone allowance');

        $this->actingSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->putJson('/hr/salary/profile', [
                'basic_salary' => '4300',
                'effective_month' => '2026-06',
                'default_mileage_rate' => '0.60',
                'yearly_medical_claim' => '900',
                'notes' => '',
                'previous_year_snapshot' => [
                    'year' => '2025',
                    'basic_salary' => '3700',
                    'allowance_total' => '260',
                    'increment_amount' => '120',
                ],
                'recurring_allowances' => [
                    ['description' => 'Meal allowance', 'amount' => '120', 'start_month' => null],
                ],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'profile.recurringAllowances')
            ->assertJsonPath('profile.previousYearSnapshot.total', '4080')
            ->assertJsonPath('profile.recurringAllowances.0.description', 'Meal allowance');
    }

    public function test_salary_profile_previous_year_snapshot_prefers_approved_december_record(): void
    {
        $this->actingSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->putJson('/hr/salary/profile', [
                'basic_salary' => '4200',
                'effective_month' => '2026-05',
                'default_mileage_rate' => '0.60',
                'yearly_medical_claim' => '1200',
                'previous_year_snapshot' => [
                    'year' => '2025',
                    'basic_salary' => '3500',
                    'allowance_total' => '150',
                    'increment_amount' => '75',
                ],
                'recurring_allowances' => [],
            ])
            ->assertOk()
            ->assertJsonPath('profile.previousYearSnapshot.source', 'manual');

        $recordId = DB::table('hr_salary_applications')->insertGetId([
            'staff_id' => 10,
            'salary_month' => '2025-12',
            'salary_month_label' => 'December 2025',
            'basic_salary' => 3800,
            'claims_total' => 250,
            'employee_deductions' => 410,
            'employer_contributions' => 520,
            'payable_salary' => 3640,
            'status' => 'Approved',
            'deductions_json' => json_encode(['employeeTotal' => 410, 'employerTotal' => 520]),
            'submitted_at' => now(),
            'approved_at' => now(),
            'approved_by' => 40,
            'approved_status' => 'Approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('hr_salary_claims')->insert([
            'application_id' => $recordId,
            'client_claim_id' => 'allowance-december',
            'type' => 'Allowance',
            'claim_date' => '2025-12-01',
            'description' => 'Phone allowance',
            'amount' => 250,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingSession()
            ->getJson('/hr/salary/profile')
            ->assertOk()
            ->assertJsonPath('profile.previousYearSnapshot.source', 'auto')
            ->assertJsonPath('profile.previousYearSnapshot.sourceLabel', 'Approved Dec 2025 salary record')
            ->assertJsonPath('profile.previousYearSnapshot.editable', false)
            ->assertJsonPath('profile.previousYearSnapshot.basicSalary', '3800')
            ->assertJsonPath('profile.previousYearSnapshot.allowanceTotal', '250')
            ->assertJsonPath('profile.previousYearSnapshot.incrementAmount', '0')
            ->assertJsonPath('profile.previousYearSnapshot.total', '4050');
    }

    public function test_application_create_replace_and_payroll_adjustments_only(): void
    {
        $this->actingSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->putJson('/hr/salary/profile', [
                'basic_salary' => '4200',
                'effective_month' => '2026-05',
                'default_mileage_rate' => '0.60',
                'yearly_medical_claim' => '1200',
                'recurring_allowances' => [],
            ])
            ->assertOk();

        $response = $this->submitSalary([
            [
                'id' => 'claim-allowance',
                'type' => 'Allowance',
                'date' => '2026-05-15',
                'description' => 'Phone allowance',
                'amount' => 200,
                'source' => 'manual',
                'sourceLabel' => 'Manual adjustment',
            ],
            [
                'id' => 'claim-adjustment',
                'type' => 'Allowance',
                'date' => '2026-05-16',
                'description' => 'Payroll correction',
                'amount' => 65,
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('record.status', 'Submitted')
            ->assertJsonPath('record.claimsTotal', 265)
            ->assertJsonPath('record.claims.1.description', 'Payroll correction')
            ->assertJsonPath('record.claims.1.attachment', null);
        $recordId = $response->json('record.id');

        $this->submitSalary([
            [
                'id' => 'claim-adjustment-replaced',
                'type' => 'Allowance',
                'date' => '2026-05-20',
                'description' => 'Updated payroll correction',
                'amount' => 75,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('record.id', $recordId)
            ->assertJsonPath('record.claims.0.description', 'Updated payroll correction')
            ->assertJsonPath('record.claims.0.attachment', null);

        $this->assertDatabaseCount('hr_salary_applications', 1);
        $this->assertDatabaseCount('hr_salary_claims', 1);
        $this->assertDatabaseCount('hr_salary_claim_attachments', 0);
    }

    public function test_application_totals_are_recomputed_on_the_server(): void
    {
        $this->actingSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->putJson('/hr/salary/profile', [
                'basic_salary' => '4200',
                'effective_month' => '2026-05',
                'default_mileage_rate' => '0.75',
                'yearly_medical_claim' => '0',
                'recurring_allowances' => [],
            ])
            ->assertOk();

        $response = $this->actingSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->post('/hr/salary/applications', [
                'salary_month' => '2026-05',
                'basic_salary' => '1000',
                'claims_total' => '9999',
                'employee_deductions' => '1',
                'employer_contributions' => '1',
                'payable_salary' => '9999',
                'deductions' => json_encode(['employeeTotal' => 1, 'employerTotal' => 1]),
                'claims' => json_encode([
                    [
                        'id' => 'claim-allowance',
                        'type' => 'Allowance',
                        'date' => '2026-05-15',
                        'description' => 'Phone allowance',
                        'amount' => 100,
                    ],
                ]),
            ])
            ->assertOk()
            ->assertJsonPath('record.basicSalary', 4200)
            ->assertJsonPath('record.claimsTotal', 100)
            ->assertJsonPath('record.employeeDeductions', 491.05)
            ->assertJsonPath('record.payableSalary', 3808.95)
            ->assertJsonPath('record.claims.0.amount', 100);

        $stored = DB::table('hr_salary_applications')->where('id', $response->json('record.id'))->first();
        $this->assertSame(4200.0, (float) $stored->basic_salary);
        $this->assertSame(100.0, (float) $stored->claims_total);
        $this->assertSame(491.05, (float) $stored->employee_deductions);
        $this->assertSame(3808.95, (float) $stored->payable_salary);
    }

    public function test_staff_can_delete_submitted_salary_and_cancel_checked_salary_with_reason(): void
    {
        $response = $this->submitSalary([])->assertOk();
        $recordId = $response->json('record.id');

        $this->actingSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->deleteJson("/hr/salary/records/{$recordId}")
            ->assertOk()
            ->assertJsonPath('message', 'Salary application deleted.');

        $this->assertDatabaseMissing('hr_salary_applications', ['id' => $recordId]);

        $checkedId = DB::table('hr_salary_applications')->insertGetId([
            'staff_id' => 10,
            'salary_month' => '2026-06',
            'salary_month_label' => 'June 2026',
            'basic_salary' => 3000,
            'claims_total' => 0,
            'employee_deductions' => 350.65,
            'employer_contributions' => 447.55,
            'payable_salary' => 2649.35,
            'status' => 'Checked',
            'deductions_json' => json_encode(['employeeTotal' => 350.65]),
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $checkedInstanceId = DB::table('workflow_instances')->insertGetId([
            'template_id' => DB::table('workflow_templates')->where('process_key', 'salary-application')->value('id'),
            'subject_type' => 'salary_application',
            'subject_id' => $checkedId,
            'current_step_id' => $this->salaryWorkflowStepId('approve'),
            'status' => 'Checked',
            'maker_staff_id' => 10,
            'submitted_by' => 10,
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->deleteJson("/hr/salary/records/{$checkedId}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Enter a reason before cancelling a checked or approved salary record.');

        $this->actingSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->deleteJson("/hr/salary/records/{$checkedId}", ['reason' => 'Submitted wrong amount'])
            ->assertOk()
            ->assertJsonPath('message', 'Salary application cancelled.');

        $this->assertDatabaseHas('hr_salary_applications', [
            'id' => $checkedId,
            'status' => 'Cancelled',
            'cancelled_by' => 10,
            'cancel_reason' => 'Submitted wrong amount',
        ]);
        $this->assertDatabaseHas('hr_salary_workflow_events', [
            'subject_type' => 'salary_application',
            'subject_id' => $checkedId,
            'action' => 'cancel',
            'reason' => 'Submitted wrong amount',
        ]);
        $this->assertDatabaseHas('workflow_actions', [
            'instance_id' => $checkedInstanceId,
            'action' => 'cancel',
            'status_to' => 'Cancelled',
            'actor_staff_id' => 10,
            'remarks' => 'Submitted wrong amount',
        ]);
        $this->assertDatabaseHas('workflow_instances', [
            'id' => $checkedInstanceId,
            'status' => 'Cancelled',
            'current_step_id' => null,
        ]);

        $this->actingSession()
            ->getJson("/hr/salary/records/{$checkedId}")
            ->assertNotFound();
        $this->actingSession()
            ->get("/hr/salary/records/{$checkedId}/claims-pdf")
            ->assertNotFound();
        $this->actingSession(3, 30, ['Manager'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson("/workflows/instances/{$checkedInstanceId}/actions", [
                'action' => 'approve',
                'remarks' => 'Should not action cancelled record',
            ])
            ->assertNotFound();

        $this->actingSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->post('/hr/salary/applications', [
                'salary_month' => '2026-06',
                'basic_salary' => '4200',
                'claims' => json_encode([]),
                'deductions' => json_encode([]),
            ])
            ->assertOk()
            ->assertJsonPath('record.salaryMonthValue', '2026-06');
    }

    public function test_salary_claims_pdf_export_is_available_to_record_owner(): void
    {
        $response = $this->submitSalary([
            [
                'id' => 'claim-allowance',
                'type' => 'Allowance',
                'date' => '2026-05-16',
                'description' => 'Payroll adjustment',
                'amount' => 65,
            ],
        ])->assertOk();
        $recordId = $response->json('record.id');

        $pdf = $this->actingSession()
            ->get("/hr/salary/records/{$recordId}/claims-pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="salary-claims-may-2026.pdf"');

        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->actingSession(2, 20)
            ->get("/hr/salary/records/{$recordId}/claims-pdf")
            ->assertNotFound();
    }

    public function test_checked_salary_edit_with_reason_resets_workflow_and_audits(): void
    {
        $response = $this->submitSalary([
            [
                'id' => 'claim-allowance',
                'type' => 'Allowance',
                'date' => '2026-05-10',
                'description' => 'Meal allowance',
                'amount' => 100,
            ],
        ])->assertOk();
        $recordId = $response->json('record.id');

        DB::table('hr_salary_applications')->where('id', $recordId)->update([
            'status' => 'Checked',
            'checked_by' => 30,
            'checked_at' => now(),
            'checked_status' => 'Checked',
            'checked_remarks' => 'Checked by finance',
        ]);
        DB::table('workflow_instances')
            ->where('subject_type', 'salary_application')
            ->where('subject_id', $recordId)
            ->update(['status' => 'Checked']);

        $this->actingSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->post('/hr/salary/applications', [
                'salary_month' => '2026-05',
                'basic_salary' => '4200',
                'claims' => json_encode([
                    [
                        'id' => 'claim-allowance-revised',
                        'type' => 'Allowance',
                        'date' => '2026-05-11',
                        'description' => 'Revised allowance',
                        'amount' => 150,
                    ],
                ]),
                'deductions' => json_encode([]),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amendment_reason']);

        $this->actingSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->post('/hr/salary/applications', [
                'salary_month' => '2026-05',
                'basic_salary' => '4200',
                'claims' => json_encode([
                    [
                        'id' => 'claim-allowance-revised',
                        'type' => 'Allowance',
                        'date' => '2026-05-11',
                        'description' => 'Revised allowance',
                        'amount' => 150,
                    ],
                ]),
                'deductions' => json_encode([]),
                'amendment_reason' => 'Corrected allowance amount',
            ])
            ->assertOk()
            ->assertJsonPath('record.id', $recordId)
            ->assertJsonPath('record.status', 'Submitted')
            ->assertJsonPath('record.checkedBy', null)
            ->assertJsonPath('record.claimsTotal', 150);

        $this->assertDatabaseHas('hr_salary_workflow_events', [
            'subject_type' => 'salary_application',
            'subject_id' => $recordId,
            'action' => 'amend',
            'status_from' => 'Checked',
            'status_to' => 'Submitted',
            'reason' => 'Corrected allowance amount',
        ]);
        $this->assertSame(
            'Submitted',
            DB::table('workflow_instances')
                ->where('subject_type', 'salary_application')
                ->where('subject_id', $recordId)
                ->value('status'),
        );
    }

    public function test_paid_salary_edit_and_delete_are_rejected(): void
    {
        $response = $this->submitSalary([])->assertOk();
        $recordId = $response->json('record.id');
        DB::table('hr_salary_applications')->where('id', $recordId)->update(['status' => 'Paid']);

        $this->actingSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->post('/hr/salary/applications', [
                'salary_month' => '2026-05',
                'basic_salary' => '4200',
                'claims' => json_encode([]),
                'deductions' => json_encode([]),
                'amendment_reason' => 'Try edit paid',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Paid salary records cannot be changed.');

        $this->actingSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->deleteJson("/hr/salary/records/{$recordId}", ['reason' => 'Try delete paid'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Paid salary records cannot be changed.');
    }

    public function test_financial_salary_claims_pdf_export_is_available_to_workflow_participants(): void
    {
        $response = $this->submitSalary([
            [
                'id' => 'claim-allowance',
                'type' => 'Allowance',
                'date' => '2026-05-16',
                'description' => 'Payroll adjustment',
                'amount' => 65,
            ],
        ])->assertOk();
        $recordId = $response->json('record.id');

        $checkStepId = $this->salaryWorkflowStepId('check');
        DB::table('workflow_step_recipients')->insert([
            'step_id' => $checkStepId,
            'staff_id' => 30,
            'sort_order' => 0,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pdf = $this->actingSession(3, 30, ['Manager'])
            ->get("/hr/salary/financial-records/{$recordId}/claims-pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="salary-claims-may-2026.pdf"');

        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->actingSession(4, 40, ['System Admin'])
            ->get("/hr/salary/financial-records/{$recordId}/claims-pdf")
            ->assertForbidden();

        $this->actingSession(1, 10, ['Staff'])
            ->get("/hr/salary/financial-records/{$recordId}/claims-pdf")
            ->assertForbidden();
    }

    public function test_salary_payslip_pdf_requires_approval_and_closed_salary_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-31 09:00:00'));

        $response = $this->submitSalary([])->assertOk();
        $recordId = $response->json('record.id');

        $this->actingSession()
            ->get("/hr/salary/records/{$recordId}/payslip-pdf")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Payslip is available after salary approval.');

        DB::table('hr_salary_applications')->where('id', $recordId)->update([
            'status' => 'Approved',
            'approved_status' => 'Approved',
            'approved_by' => 40,
            'approved_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingSession()
            ->get("/hr/salary/records/{$recordId}/payslip-pdf")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Payslip is available from 01-Jun-2026 after salary month closes.');
    }

    public function test_salary_payslip_pdf_export_is_available_after_month_close_to_owner_and_past_approver(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 09:00:00'));

        $response = $this->submitSalary([
            [
                'id' => 'claim-allowance',
                'type' => 'Allowance',
                'date' => '2026-05-16',
                'description' => 'Payroll adjustment',
                'amount' => 65,
            ],
        ])->assertOk();
        $recordId = $response->json('record.id');

        DB::table('hr_salary_applications')->where('id', $recordId)->update([
            'status' => 'Approved',
            'approved_status' => 'Approved',
            'approved_by' => 40,
            'approved_at' => now(),
            'updated_at' => now(),
        ]);

        $pdf = $this->actingSession()
            ->get("/hr/salary/records/{$recordId}/payslip-pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="salary-payslip-may-2026.pdf"');

        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $financialPdf = $this->actingSession(4, 40, ['System Admin'])
            ->get("/hr/salary/financial-records/{$recordId}/payslip-pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="salary-payslip-may-2026.pdf"');

        $this->assertStringStartsWith('%PDF', $financialPdf->getContent());

        $this->actingSession(2, 20)
            ->get("/hr/salary/records/{$recordId}/payslip-pdf")
            ->assertNotFound();

        $this->actingSession(3, 30, ['Manager'])
            ->get("/hr/salary/financial-records/{$recordId}/payslip-pdf")
            ->assertForbidden();

        $this->actingSession(1, 10, ['Staff'])
            ->get("/hr/salary/financial-records/{$recordId}/payslip-pdf")
            ->assertForbidden();
    }

    public function test_salary_claim_pdf_template_renders_previous_year_reference_values_or_settings_notice(): void
    {
        $html = $this->salaryClaimPdfHtml([
            'year' => '2025',
            'available' => true,
            'basicSalary' => '3600',
            'allowanceTotal' => '240',
            'incrementAmount' => '100',
            'total' => '3940',
            'message' => '',
        ]);

        $this->assertStringContainsString('3,600.00', $html);
        $this->assertStringContainsString('240.00', $html);
        $this->assertStringContainsString('100.00', $html);
        $this->assertStringContainsString('3,940.00', $html);

        $missingHtml = $this->salaryClaimPdfHtml([
            'year' => '2025',
            'available' => false,
            'message' => '2025 snapshot not configured. Set in Salary Settings.',
        ]);

        $this->assertStringContainsString(
            '2025 snapshot not configured. Set in Salary Settings.',
            $missingHtml,
        );
        $this->assertStringNotContainsString('2025 data not configured yet', $missingHtml);
    }

    public function test_paid_month_cannot_be_replaced(): void
    {
        DB::table('hr_salary_applications')->insert([
            'staff_id' => 10,
            'salary_month' => '2026-05',
            'salary_month_label' => 'May 2026',
            'basic_salary' => 3000,
            'claims_total' => 0,
            'employee_deductions' => 350.65,
            'employer_contributions' => 447.55,
            'payable_salary' => 2649.35,
            'status' => 'Paid',
            'deductions_json' => json_encode(['employeeTotal' => 350.65]),
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->submitSalary([])
            ->assertStatus(422)
            ->assertSee('Paid salary records cannot be changed.');

        $this->assertDatabaseHas('hr_salary_applications', [
            'staff_id' => 10,
            'salary_month' => '2026-05',
            'status' => 'Paid',
        ]);
    }

    public function test_application_rejects_invalid_json_payload(): void
    {
        $this->actingSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/hr/salary/applications', [
                'salary_month' => '2026-05',
                'basic_salary' => '4200',
                'claims_total' => '0',
                'employee_deductions' => '491.05',
                'employer_contributions' => '625.65',
                'payable_salary' => '3708.95',
                'deductions' => '{"employeeTotal":491.05}',
                'claims' => '{bad-json',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.claims.0', 'The claims field must contain valid JSON.');
    }

    public function test_salary_application_rejects_non_payroll_claim_types_and_attachments(): void
    {
        $expense = $this->actingSession()
            ->withHeader('Accept', 'application/json')
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->post('/hr/salary/applications', [
                'salary_month' => '2026-05',
                'basic_salary' => '4200',
                'deductions' => json_encode([]),
                'claims' => json_encode([
                    [
                        'id' => 'claim-expense',
                        'type' => 'Expense',
                        'date' => '2026-05-16',
                        'description' => 'Parking claim',
                        'amount' => 65,
                    ],
                ]),
            ])
            ->assertStatus(422);
        $this->assertSame(
            'Salary applications only accept payroll allowance or adjustment rows. Use Other Claim for expense, mileage, and medical claims.',
            $expense->json('errors')['claims.0.type'][0] ?? null,
        );

        $allowanceAttachment = $this->actingSession()
            ->withHeader('Accept', 'application/json')
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->post('/hr/salary/applications', [
                'salary_month' => '2026-05',
                'basic_salary' => '4200',
                'deductions' => json_encode([]),
                'claims' => json_encode([
                    [
                        'id' => 'claim-allowance',
                        'type' => 'Allowance',
                        'date' => '2026-05-17',
                        'description' => 'Payroll adjustment',
                        'amount' => 60,
                    ],
                ]),
                'attachments' => [
                    'claim-allowance' => UploadedFile::fake()->create('adjustment.pdf', 100, 'application/pdf'),
                ],
            ])
            ->assertStatus(422);
        $this->assertSame(
            'Salary adjustments cannot include claim attachments. Use Other Claim for attachment-backed reimbursements.',
            $allowanceAttachment->json('errors')['attachments.claim-allowance'][0] ?? null,
        );
    }

    public function test_salary_draft_rejects_non_payroll_claim_types_and_attachments(): void
    {
        $claims = [
            [
                'id' => 'claim-expense',
                'type' => 'Expense',
                'date' => '2026-05-16',
                'description' => 'Parking claim',
                'amount' => 65,
            ],
            [
                'id' => 'claim-medical',
                'type' => 'Medical',
                'date' => '2026-05-18',
                'description' => 'Clinic claim',
                'amount' => 90,
            ],
        ];

        $response = $this->actingSession()
            ->withHeader('Accept', 'application/json')
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->put('/hr/salary/applications/draft', [
                'salary_month' => '2026-05',
                'basic_salary' => '4200',
                'claims' => json_encode($claims),
                'attachments' => [
                    'claim-expense' => UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
                ],
            ])
            ->assertStatus(422);
        $this->assertSame(
            'Salary drafts only accept payroll allowance or adjustment rows. Use Other Claim for expense, mileage, and medical claims.',
            $response->json('errors')['claims.0.type'][0] ?? null,
        );
        $this->assertSame(
            'Salary adjustments cannot include claim attachments. Use Other Claim for attachment-backed reimbursements.',
            $response->json('errors')['attachments.claim-expense'][0] ?? null,
        );
    }

    public function test_other_claim_application_enforces_annual_medical_claim_balance(): void
    {
        $this->actingSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->putJson('/hr/salary/profile', [
                'basic_salary' => '4200',
                'effective_month' => '2026-05',
                'default_mileage_rate' => '0.60',
                'yearly_medical_claim' => '100',
                'recurring_allowances' => [],
            ])
            ->assertOk();

        $this->submitOtherClaim([
            [
                'id' => 'claim-medical-may',
                'type' => 'Medical',
                'date' => '2026-05-18',
                'description' => 'Clinic claim',
                'amount' => 80,
            ],
        ], [
            'claim-medical-may' => UploadedFile::fake()->create('clinic.pdf', 100, 'application/pdf'),
        ])->assertOk();

        $this->actingSession()
            ->withHeader('Accept', 'application/json')
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->post('/hr/salary/other-claims', [
                'claim_month' => '2026-06',
                'claims' => json_encode([
                    [
                        'id' => 'claim-medical-june',
                        'type' => 'Medical',
                        'date' => '2026-06-18',
                        'description' => 'Clinic follow-up',
                        'amount' => 30,
                    ],
                ]),
                'attachments' => [
                    'claim-medical-june' => UploadedFile::fake()->create('follow-up.pdf', 100, 'application/pdf'),
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.claims.0', 'Medical claims exceed the annual medical claim balance of 20.00.');
    }

    public function test_other_claim_draft_can_be_saved_restored_and_cleared(): void
    {
        $claims = [
            [
                'id' => 'other-allowance-draft',
                'type' => 'Allowance',
                'date' => '2026-05-10',
                'description' => 'Meal allowance',
                'amount' => 88,
            ],
        ];

        $this->actingSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->put('/hr/salary/other-claims/draft', [
                'claim_month' => '2026-05',
                'claims' => json_encode($claims),
                'draft_payload' => json_encode(['notice' => 'saved locally first']),
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Other claim draft saved.')
            ->assertJsonPath('record.status', 'Draft')
            ->assertJsonPath('record.claimMonthValue', '2026-05')
            ->assertJsonPath('record.claimsTotal', 88)
            ->assertJsonPath('record.claims.0.description', 'Meal allowance')
            ->assertJsonPath('record.draftPayload.notice', 'saved locally first');

        $this->actingSession()
            ->getJson('/hr/salary/other-claims/draft?claim_month=2026-05')
            ->assertOk()
            ->assertJsonPath('record.status', 'Draft')
            ->assertJsonPath('record.draftPayload.notice', 'saved locally first');

        $this->actingSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->deleteJson('/hr/salary/other-claims/draft?claim_month=2026-05')
            ->assertOk()
            ->assertJsonPath('message', 'Other claim draft cleared.');

        $this->actingSession()
            ->getJson('/hr/salary/other-claims/draft?claim_month=2026-05')
            ->assertOk()
            ->assertJsonPath('record', null);
    }

    public function test_other_claim_application_create_replace_attachment_owner_access_and_pdf(): void
    {
        $this->updateSalaryProfile(defaultMileageRate: '0.60', yearlyMedicalClaim: '1200');

        $response = $this->submitOtherClaim([
            [
                'id' => 'other-allowance',
                'type' => 'Allowance',
                'date' => '2026-05-10',
                'description' => 'Meal allowance',
                'amount' => 100,
            ],
            [
                'id' => 'other-expense',
                'type' => 'Expense',
                'date' => '2026-05-11',
                'description' => 'Parking claim',
                'amount' => 65,
            ],
            [
                'id' => 'other-mileage',
                'type' => 'Mileage',
                'date' => '2026-05-12',
                'description' => 'Office to Client site',
                'km' => 12,
                'startLocation' => 'Office',
                'endLocation' => 'Client site',
            ],
            [
                'id' => 'other-medical',
                'type' => 'Medical',
                'date' => '2026-05-13',
                'description' => 'Clinic claim',
                'amount' => 90,
            ],
        ], [
            'other-expense' => UploadedFile::fake()->create('parking.pdf', 100, 'application/pdf'),
            'other-medical' => UploadedFile::fake()->create('clinic.pdf', 100, 'application/pdf'),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Other claim was submitted for review.')
            ->assertJsonPath('record.status', 'Submitted')
            ->assertJsonPath('record.claimMonthValue', '2026-05')
            ->assertJsonPath('record.claimsTotal', 269.4)
            ->assertJsonPath('record.claims.2.amount', 14.4)
            ->assertJsonPath('record.claims.2.meta', '12 KM one-way / 24 KM return')
            ->assertJsonPath('record.claims.1.attachment.name', 'parking.pdf');

        $recordId = $response->json('record.id');
        $attachmentId = $response->json('record.claims.1.attachment.id');

        Storage::disk('private')->assertExists(
            DB::table('hr_other_claim_attachments')->where('id', $attachmentId)->value('stored_path'),
        );

        $this->actingSession()
            ->get("/hr/salary/other-claim-attachments/{$attachmentId}")
            ->assertOk();

        $this->actingSession(2, 20)
            ->getJson("/hr/salary/other-claim-attachments/{$attachmentId}")
            ->assertNotFound();

        $this->actingSession()
            ->getJson('/hr/salary/other-claims')
            ->assertOk()
            ->assertJsonPath('records.0.id', $recordId)
            ->assertJsonPath('records.0.claimsTotal', 269.4);

        $this->actingSession()
            ->getJson("/hr/salary/other-claims/{$recordId}")
            ->assertOk()
            ->assertJsonPath('record.claims.1.attachment.id', $attachmentId);

        $pdf = $this->actingSession()
            ->get("/hr/salary/other-claims/{$recordId}/claims-pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="other-claims-may-2026.pdf"');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->submitOtherClaim([
            [
                'id' => 'other-expense-replaced',
                'type' => 'Expense',
                'date' => '2026-05-20',
                'description' => 'Updated parking claim',
                'amount' => 75,
                'attachmentId' => $attachmentId,
            ],
        ], [], ['application_id' => $recordId])
            ->assertOk()
            ->assertJsonPath('record.id', $recordId)
            ->assertJsonPath('record.claimsTotal', 75)
            ->assertJsonPath('record.claims.0.description', 'Updated parking claim')
            ->assertJsonPath('record.claims.0.attachment.name', 'parking.pdf');

        $this->assertDatabaseCount('hr_other_claim_applications', 1);
        $this->assertDatabaseCount('hr_other_claim_items', 1);
        $this->assertDatabaseCount('hr_other_claim_attachments', 1);

        $this->actingSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->deleteJson("/hr/salary/other-claims/{$recordId}")
            ->assertOk()
            ->assertJsonPath('message', 'Other claim deleted.');

        $this->assertDatabaseMissing('hr_other_claim_applications', ['id' => $recordId]);
    }

    public function test_approved_other_claim_edit_with_reason_resets_workflow_and_audits(): void
    {
        $response = $this->submitOtherClaim([
            [
                'id' => 'other-allowance',
                'type' => 'Allowance',
                'date' => '2026-05-10',
                'description' => 'Meal allowance',
                'amount' => 100,
            ],
        ])->assertOk();
        $recordId = $response->json('record.id');

        DB::table('hr_other_claim_applications')->where('id', $recordId)->update([
            'status' => 'Approved',
            'checked_by' => 30,
            'checked_at' => now(),
            'checked_status' => 'Checked',
            'approved_by' => 40,
            'approved_at' => now(),
            'approved_status' => 'Approved',
            'approved_remarks' => 'Approved',
        ]);
        DB::table('workflow_instances')
            ->where('subject_type', 'other_claim_application')
            ->where('subject_id', $recordId)
            ->update(['status' => 'Approved', 'completed_at' => now()]);

        $this->actingSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/hr/salary/other-claims', [
                'application_id' => $recordId,
                'claim_month' => '2026-05',
                'claims' => json_encode([
                    [
                        'id' => 'other-allowance-revised',
                        'type' => 'Allowance',
                        'date' => '2026-05-11',
                        'description' => 'Revised meal allowance',
                        'amount' => 125,
                    ],
                ]),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amendment_reason']);

        $this->submitOtherClaim([
            [
                'id' => 'other-allowance-revised',
                'type' => 'Allowance',
                'date' => '2026-05-11',
                'description' => 'Revised meal allowance',
                'amount' => 125,
            ],
        ], [], [
            'application_id' => $recordId,
            'amendment_reason' => 'Corrected submitted claim',
        ])
            ->assertOk()
            ->assertJsonPath('record.id', $recordId)
            ->assertJsonPath('record.status', 'Submitted')
            ->assertJsonPath('record.checkedBy', null)
            ->assertJsonPath('record.approvedBy', null)
            ->assertJsonPath('record.claimsTotal', 125);

        $this->assertDatabaseHas('hr_salary_workflow_events', [
            'subject_type' => 'other_claim_application',
            'subject_id' => $recordId,
            'action' => 'amend',
            'status_from' => 'Approved',
            'status_to' => 'Submitted',
            'reason' => 'Corrected submitted claim',
        ]);
    }

    public function test_paid_other_claim_edit_and_delete_are_rejected(): void
    {
        $response = $this->submitOtherClaim([
            [
                'id' => 'other-allowance',
                'type' => 'Allowance',
                'date' => '2026-05-10',
                'description' => 'Meal allowance',
                'amount' => 100,
            ],
        ])->assertOk();
        $recordId = $response->json('record.id');
        DB::table('hr_other_claim_applications')->where('id', $recordId)->update(['status' => 'Paid']);

        $this->actingSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/hr/salary/other-claims', [
                'application_id' => $recordId,
                'claim_month' => '2026-05',
                'claims' => json_encode([
                    [
                        'id' => 'other-allowance-revised',
                        'type' => 'Allowance',
                        'date' => '2026-05-11',
                        'description' => 'Revised meal allowance',
                        'amount' => 125,
                    ],
                ]),
                'amendment_reason' => 'Try edit paid',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Paid other claim records cannot be changed.')
            ->assertJsonValidationErrors(['application_id']);

        $this->actingSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->deleteJson("/hr/salary/other-claims/{$recordId}", ['reason' => 'Try delete paid'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Paid other claim records cannot be changed.');
    }

    public function test_financial_other_claim_records_can_be_checked_and_approved(): void
    {
        $this->updateSalaryProfile(defaultMileageRate: '0.60', yearlyMedicalClaim: '1200');
        $response = $this->submitOtherClaim([
            [
                'id' => 'other-allowance',
                'type' => 'Allowance',
                'date' => '2026-05-10',
                'description' => 'Meal allowance',
                'amount' => 100,
            ],
        ])->assertOk();
        $recordId = $response->json('record.id');

        $this->actingSession(3, 30, ['Manager'])
            ->getJson('/hr/salary/other-claims/financial-records')
            ->assertOk()
            ->assertJsonPath('records.0.id', $recordId)
            ->assertJsonPath('records.0.staffCode', 'STA')
            ->assertJsonPath('records.0.workflow.availableActions.0.action', 'check')
            ->assertJsonPath('records.0.workflow.availableActions.1.action', 'reject');

        $this->actingSession(1, 10, ['Staff'])
            ->getJson('/hr/salary/other-claims/financial-records')
            ->assertForbidden();

        $this->actingSession(3, 30, ['Manager'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson("/hr/salary/other-claims/financial-records/{$recordId}/action", [
                'action' => 'approve',
                'remarks' => 'Approve too early',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Other claim record must be checked before approval.');

        $this->actingSession(3, 30, ['Manager'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson("/hr/salary/other-claims/financial-records/{$recordId}/action", [
                'action' => 'check',
                'remarks' => 'Checked by finance',
            ])
            ->assertOk()
            ->assertJsonPath('record.status', 'Checked')
            ->assertJsonPath('record.checkedBy', 30)
            ->assertJsonPath('record.checkedStatus', 'Checked');

        $this->actingSession(4, 40, ['System Admin'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson("/hr/salary/other-claims/financial-records/{$recordId}/action", [
                'action' => 'approve',
                'remarks' => 'Approved for payment',
            ])
            ->assertOk()
            ->assertJsonPath('record.status', 'Approved')
            ->assertJsonPath('record.approvedBy', 40)
            ->assertJsonPath('record.approvedStatus', 'Approved');

        $financialPdf = $this->actingSession(4, 40, ['System Admin'])
            ->get("/hr/salary/other-claims/financial-records/{$recordId}/claims-pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $financialPdf->getContent());
    }

    public function test_payment_queue_aggregates_approved_salary_and_other_claims_with_privacy_redaction(): void
    {
        $salaryId = DB::table('hr_salary_applications')->insertGetId([
            'staff_id' => 10,
            'salary_month' => '2026-05',
            'salary_month_label' => 'May 2026',
            'basic_salary' => 4200,
            'claims_total' => 100,
            'employee_deductions' => 491.05,
            'employer_contributions' => 625.65,
            'payable_salary' => 3808.95,
            'status' => 'Approved',
            'deductions_json' => json_encode(['employeeTotal' => 491.05]),
            'submitted_at' => now(),
            'checked_by' => 30,
            'checked_at' => now(),
            'checked_status' => 'Checked',
            'approved_by' => 50,
            'approved_at' => now(),
            'approved_status' => 'Approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherClaimId = DB::table('hr_other_claim_applications')->insertGetId([
            'staff_id' => 10,
            'claim_month' => '2026-05',
            'claim_month_label' => 'May 2026',
            'claims_total' => 125,
            'status' => 'Approved',
            'submitted_at' => now(),
            'checked_by' => 30,
            'checked_at' => now(),
            'checked_status' => 'Checked',
            'approved_by' => 50,
            'approved_at' => now(),
            'approved_status' => 'Approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createApprovedWorkflow('salary_application', $salaryId, actorStaffId: 50);
        $this->createApprovedWorkflow('other_claim_application', $otherClaimId, actorStaffId: 50);

        $this->actingSession(3, 30, ['Manager'])
            ->getJson('/hr/salary/payment-queue')
            ->assertOk()
            ->assertJsonCount(1, 'records')
            ->assertJsonPath('records.0.staffId', 10)
            ->assertJsonPath('records.0.period', '2026-05')
            ->assertJsonPath('records.0.salaryDue', 3808.95)
            ->assertJsonPath('records.0.otherClaimDue', 125)
            ->assertJsonPath('records.0.totalDue', 3933.95)
            ->assertJsonPath('records.0.itemCount', 2)
            ->assertJsonPath('records.0.canViewValues', true)
            ->assertJsonPath('records.0.canMarkPaid', true);

        $this->actingSession(4, 40, ['System Admin'])
            ->getJson('/hr/salary/payment-queue')
            ->assertOk()
            ->assertJsonPath('records.0.staffName', 'Restricted')
            ->assertJsonPath('records.0.salaryDue', null)
            ->assertJsonPath('records.0.otherClaimDue', null)
            ->assertJsonPath('records.0.totalDue', null)
            ->assertJsonPath('records.0.canViewValues', false)
            ->assertJsonPath('records.0.canMarkPaid', false);

        $this->actingSession(4, 40, ['System Admin'])
            ->getJson('/hr/salary/payment-queue/10/2026-05')
            ->assertOk()
            ->assertJsonPath('row.staffName', 'Restricted')
            ->assertJsonPath('row.salaryDue', null)
            ->assertJsonPath('row.otherClaimDue', null)
            ->assertJsonPath('row.totalDue', null)
            ->assertJsonPath('row.canViewValues', false)
            ->assertJsonPath('row.canMarkPaid', false)
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.label', 'Restricted')
            ->assertJsonPath('items.0.amount', null);

        DB::table('system_users')->insert([
            'id' => 99,
            'staff_id' => 10,
            'email' => 'sysadmin.staff@example.test',
            'role' => json_encode(['System Admin']),
            'is_active' => 1,
            'total_lock' => 0,
            'account_locked_until' => null,
        ]);

        $this->actingSession(99, 10, ['System Admin'])
            ->getJson('/hr/salary/payment-queue')
            ->assertOk()
            ->assertJsonPath('records.0.staffName', 'Restricted')
            ->assertJsonPath('records.0.salaryDue', null)
            ->assertJsonPath('records.0.otherClaimDue', null)
            ->assertJsonPath('records.0.totalDue', null)
            ->assertJsonPath('records.0.canViewValues', false)
            ->assertJsonPath('records.0.canMarkPaid', false);

        $this->actingSession(3, 30, ['Manager'])
            ->getJson('/hr/salary/payment-queue/10/2026-05')
            ->assertOk()
            ->assertJsonPath('row.totalDue', 3933.95)
            ->assertJsonCount(2, 'items');
    }

    public function test_payment_queue_mark_paid_requires_workflow_visibility_even_for_payment_roles(): void
    {
        $salaryId = DB::table('hr_salary_applications')->insertGetId([
            'staff_id' => 10,
            'salary_month' => '2026-05',
            'salary_month_label' => 'May 2026',
            'basic_salary' => 4200,
            'claims_total' => 0,
            'employee_deductions' => 491.05,
            'employer_contributions' => 625.65,
            'payable_salary' => 3708.95,
            'status' => 'Approved',
            'deductions_json' => json_encode(['employeeTotal' => 491.05]),
            'submitted_at' => now(),
            'checked_by' => 30,
            'checked_at' => now(),
            'checked_status' => 'Checked',
            'approved_by' => 30,
            'approved_at' => now(),
            'approved_status' => 'Approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createApprovedWorkflow('salary_application', $salaryId, actorStaffId: 30);

        $this->actingSession(5, 50, ['Bank'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/hr/salary/payment-queue/mark-paid', [
                'staff_id' => 10,
                'payment_period' => '2026-05',
                'payment_date' => '2026-06-30',
                'payment_reference' => 'BATCH-OUTSIDE-WORKFLOW',
                'payment_method' => 'Bank Transfer',
                'idempotency_key' => 'pay-2026-05-staff-10-outside-workflow',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'You are not authorized to mark this payment as paid.');

        $this->assertDatabaseHas('hr_salary_applications', ['id' => $salaryId, 'status' => 'Approved']);
        $this->assertDatabaseCount('hr_salary_payment_runs', 0);
        $this->assertDatabaseCount('hr_salary_payment_run_items', 0);
    }

    public function test_payment_queue_blocks_duplicate_approved_salary_records_for_same_period(): void
    {
        foreach ([3708.95, 1200.00] as $index => $payableSalary) {
            $salaryId = DB::table('hr_salary_applications')->insertGetId([
                'staff_id' => 10,
                'salary_month' => '2026-05',
                'salary_month_label' => 'May 2026',
                'basic_salary' => $index === 0 ? 4200 : 1200,
                'claims_total' => 0,
                'employee_deductions' => $index === 0 ? 491.05 : 0,
                'employer_contributions' => 625.65,
                'payable_salary' => $payableSalary,
                'status' => 'Approved',
                'deductions_json' => json_encode(['employeeTotal' => $index === 0 ? 491.05 : 0]),
                'submitted_at' => now(),
                'checked_by' => 30,
                'checked_at' => now(),
                'checked_status' => 'Checked',
                'approved_by' => 30,
                'approved_at' => now(),
                'approved_status' => 'Approved',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->createApprovedWorkflow('salary_application', $salaryId, actorStaffId: 30);
        }

        $this->actingSession(3, 30, ['Manager'])
            ->getJson('/hr/salary/payment-queue')
            ->assertOk()
            ->assertJsonCount(1, 'records')
            ->assertJsonPath('records.0.status', 'Blocked')
            ->assertJsonPath('records.0.salaryCount', 2)
            ->assertJsonPath('records.0.canMarkPaid', false)
            ->assertJsonPath(
                'records.0.blockReason',
                'Multiple approved salary records exist for this employee and period. Resolve duplicates before payment.',
            );
    }

    public function test_payment_queue_excludes_and_refuses_approved_records_without_valid_workflow_completion(): void
    {
        $salaryId = DB::table('hr_salary_applications')->insertGetId([
            'staff_id' => 10,
            'salary_month' => '2026-05',
            'salary_month_label' => 'May 2026',
            'basic_salary' => 4200,
            'claims_total' => 0,
            'employee_deductions' => 491.05,
            'employer_contributions' => 625.65,
            'payable_salary' => 3708.95,
            'status' => 'Approved',
            'deductions_json' => json_encode(['employeeTotal' => 491.05]),
            'submitted_at' => now(),
            'checked_by' => 50,
            'checked_at' => now(),
            'checked_status' => 'Checked',
            'approved_by' => 50,
            'approved_at' => now(),
            'approved_status' => 'Approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingSession(5, 50, ['Bank'])
            ->getJson('/hr/salary/payment-queue')
            ->assertOk()
            ->assertJsonCount(0, 'records');

        $this->actingSession(5, 50, ['Bank'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/hr/salary/payment-queue/mark-paid', [
                'staff_id' => 10,
                'payment_period' => '2026-05',
                'payment_date' => '2026-06-30',
                'payment_reference' => 'BATCH-MISSING-WORKFLOW',
                'payment_method' => 'Bank Transfer',
                'idempotency_key' => 'pay-2026-05-staff-10-missing-workflow',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Payment queue row changed. Refresh before marking paid.');

        $this->assertDatabaseHas('hr_salary_applications', ['id' => $salaryId, 'status' => 'Approved']);
        $this->assertDatabaseCount('hr_salary_payment_runs', 0);
        $this->assertDatabaseCount('hr_salary_payment_run_items', 0);
    }

    public function test_payment_queue_mark_paid_is_atomic_idempotent_visible_and_undoable(): void
    {
        $salaryId = DB::table('hr_salary_applications')->insertGetId([
            'staff_id' => 10,
            'salary_month' => '2026-05',
            'salary_month_label' => 'May 2026',
            'basic_salary' => 4200,
            'claims_total' => 0,
            'employee_deductions' => 491.05,
            'employer_contributions' => 625.65,
            'payable_salary' => 3708.95,
            'status' => 'Approved',
            'deductions_json' => json_encode(['employeeTotal' => 491.05]),
            'submitted_at' => now(),
            'checked_by' => 30,
            'checked_at' => now(),
            'checked_status' => 'Checked',
            'approved_by' => 50,
            'approved_at' => now(),
            'approved_status' => 'Approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherClaimId = DB::table('hr_other_claim_applications')->insertGetId([
            'staff_id' => 10,
            'claim_month' => '2026-05',
            'claim_month_label' => 'May 2026',
            'claims_total' => 200,
            'status' => 'Approved',
            'submitted_at' => now(),
            'checked_by' => 30,
            'checked_at' => now(),
            'checked_status' => 'Checked',
            'approved_by' => 50,
            'approved_at' => now(),
            'approved_status' => 'Approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createApprovedWorkflow('salary_application', $salaryId, actorStaffId: 50);
        $this->createApprovedWorkflow('other_claim_application', $otherClaimId, actorStaffId: 50);

        $payload = [
            'staff_id' => 10,
            'payment_period' => '2026-05',
            'payment_date' => '2026-06-30',
            'payment_reference' => 'BATCH-001',
            'payment_method' => 'Bank Transfer',
            'remarks' => 'Monthly payout',
            'idempotency_key' => 'pay-2026-05-staff-10',
        ];

        $this->actingSession(5, 50, ['Bank'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/hr/salary/payment-queue/mark-paid', $payload)
            ->assertOk()
            ->assertJsonPath('message', 'Payment marked as paid.');

        $this->assertDatabaseHas('hr_salary_applications', ['id' => $salaryId, 'status' => 'Paid']);
        $this->assertDatabaseHas('hr_other_claim_applications', ['id' => $otherClaimId, 'status' => 'Paid']);
        $this->assertDatabaseHas('hr_salary_payment_runs', [
            'staff_id' => 10,
            'payment_period' => '2026-05',
            'total_paid' => 3908.95,
            'idempotency_key' => 'pay-2026-05-staff-10',
        ]);
        $this->assertDatabaseCount('hr_salary_payment_run_items', 2);

        $this->actingSession(5, 50, ['Bank'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/hr/salary/payment-queue/mark-paid', $payload)
            ->assertOk()
            ->assertJsonPath('idempotent', true);

        $this->actingSession(5, 50, ['Bank'])
            ->getJson('/hr/salary/payment-queue')
            ->assertOk()
            ->assertJsonCount(1, 'records')
            ->assertJsonPath('records.0.status', 'Paid')
            ->assertJsonPath('records.0.canMarkPaid', false)
            ->assertJsonPath('records.0.canUndoPaid', true)
            ->assertJsonPath('records.0.totalDue', 3908.95);

        $this->actingSession(5, 50, ['Bank'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/hr/salary/payment-queue/undo-paid', [
                'staff_id' => 10,
                'payment_period' => '2026-05',
                'reason' => 'Payment entered against the wrong bank batch.',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Payment was undone.');

        $this->assertDatabaseHas('hr_salary_applications', ['id' => $salaryId, 'status' => 'Approved']);
        $this->assertDatabaseHas('hr_other_claim_applications', ['id' => $otherClaimId, 'status' => 'Approved']);
        $this->assertDatabaseMissing('hr_salary_payment_runs', [
            'staff_id' => 10,
            'payment_period' => '2026-05',
            'voided_at' => null,
        ]);

        $this->actingSession(5, 50, ['Bank'])
            ->getJson('/hr/salary/payment-queue')
            ->assertOk()
            ->assertJsonCount(1, 'records')
            ->assertJsonPath('records.0.status', 'Pending Payment')
            ->assertJsonPath('records.0.canMarkPaid', true);

        $payload['idempotency_key'] = 'pay-2026-05-staff-10-after-undo';
        $this->actingSession(5, 50, ['Bank'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/hr/salary/payment-queue/mark-paid', $payload)
            ->assertOk()
            ->assertJsonPath('message', 'Payment marked as paid.');

        $this->assertDatabaseCount('hr_salary_payment_runs', 2);
    }

    public function test_payment_queue_bulk_mark_paid_and_bulk_undo_report_selected_row_results(): void
    {
        foreach ([10 => '2026-05', 20 => '2026-06'] as $staffId => $period) {
            $salaryId = DB::table('hr_salary_applications')->insertGetId([
                'staff_id' => $staffId,
                'salary_month' => $period,
                'salary_month_label' => Carbon::parse($period.'-01')->format('F Y'),
                'basic_salary' => 3000,
                'claims_total' => 0,
                'employee_deductions' => 300,
                'employer_contributions' => 450,
                'payable_salary' => 2700,
                'status' => 'Approved',
                'deductions_json' => json_encode(['employeeTotal' => 300]),
                'submitted_at' => now(),
                'checked_by' => 30,
                'checked_at' => now(),
                'checked_status' => 'Checked',
                'approved_by' => 50,
                'approved_at' => now(),
                'approved_status' => 'Approved',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->createApprovedWorkflow('salary_application', $salaryId, actorStaffId: 50);
        }

        $this->actingSession(5, 50, ['Bank'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/hr/salary/payment-queue/bulk-mark-paid', [
                'rows' => [
                    ['staff_id' => 10, 'payment_period' => '2026-05'],
                    ['staff_id' => 20, 'payment_period' => '2026-06'],
                ],
                'payment_date' => '2026-06-30',
                'payment_reference' => 'BULK-001',
                'payment_method' => 'Bank Transfer',
            ])
            ->assertOk()
            ->assertJsonPath('summary.success', 2)
            ->assertJsonPath('summary.skipped', 0)
            ->assertJsonPath('summary.failed', 0);

        $this->assertDatabaseCount('hr_salary_payment_runs', 2);
        $this->assertDatabaseHas('hr_salary_applications', [
            'staff_id' => 10,
            'salary_month' => '2026-05',
            'status' => 'Paid',
        ]);
        $this->assertDatabaseHas('hr_salary_applications', [
            'staff_id' => 20,
            'salary_month' => '2026-06',
            'status' => 'Paid',
        ]);

        $this->actingSession(5, 50, ['Bank'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/hr/salary/payment-queue/bulk-undo-paid', [
                'rows' => [
                    ['staff_id' => 10, 'payment_period' => '2026-05'],
                    ['staff_id' => 20, 'payment_period' => '2026-07'],
                ],
                'reason' => 'Bulk payment batch was reversed.',
            ])
            ->assertOk()
            ->assertJsonPath('summary.success', 1)
            ->assertJsonPath('summary.skipped', 1)
            ->assertJsonPath('summary.failed', 0);

        $this->assertDatabaseHas('hr_salary_applications', [
            'staff_id' => 10,
            'salary_month' => '2026-05',
            'status' => 'Approved',
        ]);
        $this->assertDatabaseHas('hr_salary_applications', [
            'staff_id' => 20,
            'salary_month' => '2026-06',
            'status' => 'Paid',
        ]);
    }

    public function test_payment_queue_does_not_hide_new_unpaid_items_after_prior_paid_run(): void
    {
        $salaryId = DB::table('hr_salary_applications')->insertGetId([
            'staff_id' => 10,
            'salary_month' => '2026-05',
            'salary_month_label' => 'May 2026',
            'basic_salary' => 3000,
            'claims_total' => 0,
            'employee_deductions' => 300,
            'employer_contributions' => 450,
            'payable_salary' => 2700,
            'status' => 'Approved',
            'deductions_json' => json_encode(['employeeTotal' => 300]),
            'submitted_at' => now(),
            'checked_by' => 30,
            'checked_at' => now(),
            'checked_status' => 'Checked',
            'approved_by' => 50,
            'approved_at' => now(),
            'approved_status' => 'Approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createApprovedWorkflow('salary_application', $salaryId, actorStaffId: 50);

        $this->actingSession(5, 50, ['Bank'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/hr/salary/payment-queue/mark-paid', [
                'staff_id' => 10,
                'payment_period' => '2026-05',
                'payment_date' => '2026-06-30',
                'payment_reference' => 'SALARY-BATCH',
                'payment_method' => 'Bank Transfer',
                'idempotency_key' => 'salary-first-2026-05-staff-10',
            ])
            ->assertOk();

        $otherClaimId = DB::table('hr_other_claim_applications')->insertGetId([
            'staff_id' => 10,
            'claim_month' => '2026-05',
            'claim_month_label' => 'May 2026',
            'claims_total' => 125,
            'status' => 'Approved',
            'submitted_at' => now(),
            'checked_by' => 30,
            'checked_at' => now(),
            'checked_status' => 'Checked',
            'approved_by' => 50,
            'approved_at' => now(),
            'approved_status' => 'Approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createApprovedWorkflow('other_claim_application', $otherClaimId, actorStaffId: 50);

        $this->actingSession(5, 50, ['Bank'])
            ->getJson('/hr/salary/payment-queue')
            ->assertOk()
            ->assertJsonCount(1, 'records')
            ->assertJsonPath('records.0.status', 'Pending Payment')
            ->assertJsonPath('records.0.salaryDue', 0)
            ->assertJsonPath('records.0.otherClaimDue', 125)
            ->assertJsonPath('records.0.totalDue', 125)
            ->assertJsonPath('records.0.canMarkPaid', true);

        $this->actingSession(5, 50, ['Bank'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/hr/salary/payment-queue/mark-paid', [
                'staff_id' => 10,
                'payment_period' => '2026-05',
                'payment_date' => '2026-07-01',
                'payment_reference' => 'CLAIM-BATCH',
                'payment_method' => 'Bank Transfer',
                'idempotency_key' => 'claim-second-2026-05-staff-10',
            ])
            ->assertOk();

        $this->actingSession(5, 50, ['Bank'])
            ->getJson('/hr/salary/payment-queue')
            ->assertOk()
            ->assertJsonCount(1, 'records')
            ->assertJsonPath('records.0.status', 'Paid')
            ->assertJsonPath('records.0.itemCount', 2)
            ->assertJsonPath('records.0.totalDue', 2825);

        $this->assertDatabaseCount('hr_salary_payment_runs', 2);
        $this->assertDatabaseHas('hr_other_claim_applications', [
            'id' => $otherClaimId,
            'status' => 'Paid',
        ]);

        $this->actingSession(5, 50, ['Bank'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/hr/salary/payment-queue/undo-paid', [
                'staff_id' => 10,
                'payment_period' => '2026-05',
                'reason' => 'Reverse the combined month payment.',
            ])
            ->assertOk()
            ->assertJsonCount(2, 'paymentRunIds');

        $this->assertDatabaseHas('hr_salary_applications', [
            'id' => $salaryId,
            'status' => 'Approved',
        ]);
        $this->assertDatabaseHas('hr_other_claim_applications', [
            'id' => $otherClaimId,
            'status' => 'Approved',
        ]);
        $this->assertDatabaseMissing('hr_salary_payment_runs', [
            'staff_id' => 10,
            'payment_period' => '2026-05',
            'voided_at' => null,
        ]);
    }

    public function test_financial_records_lists_all_submitted_salary_records_for_privileged_roles(): void
    {
        $this->submitSalary([
            [
                'id' => 'claim-allowance',
                'type' => 'Allowance',
                'date' => '2026-05-15',
                'description' => 'Phone allowance',
                'amount' => 200,
            ],
        ])->assertOk();

        $this->actingSession(2, 20)
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->post('/hr/salary/applications', [
                'salary_month' => '2026-06',
                'basic_salary' => '3200',
                'claims_total' => '0',
                'employee_deductions' => '375.00',
                'employer_contributions' => '470.00',
                'payable_salary' => '2825.00',
                'deductions' => json_encode(['employeeTotal' => 375, 'employerTotal' => 470]),
                'claims' => json_encode([]),
            ])
            ->assertOk();

        $checkStepId = $this->salaryWorkflowStepId('check');
        DB::table('workflow_step_recipients')->insert([
            'step_id' => $checkStepId,
            'staff_id' => 30,
            'sort_order' => 0,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingSession(3, 30, ['Manager'])
            ->getJson('/hr/salary/financial-records')
            ->assertOk()
            ->assertJsonCount(2, 'records')
            ->assertJsonPath('records.0.staffId', 20)
            ->assertJsonPath('records.0.staffName', 'Other Staff')
            ->assertJsonPath('records.0.canViewSalaryDetails', true)
            ->assertJsonPath('records.0.salaryRestricted', false)
            ->assertJsonPath('records.0.workflow.availableActions.0.action', 'check')
            ->assertJsonPath('records.0.workflow.availableActions.0.tone', 'info')
            ->assertJsonPath('records.0.workflow.availableActions.1.action', 'reject')
            ->assertJsonPath('records.1.staffCode', 'STA');

        $this->actingSession(4, 40, ['System Admin'])
            ->getJson('/hr/salary/financial-records')
            ->assertOk()
            ->assertJsonPath('records.0.staffId', null)
            ->assertJsonPath('records.0.staffName', 'Restricted')
            ->assertJsonPath('records.0.basicSalary', null)
            ->assertJsonPath('records.0.payableSalary', null)
            ->assertJsonPath('records.0.canViewSalaryDetails', false)
            ->assertJsonPath('records.0.salaryRestricted', true)
            ->assertJsonPath('records.0.workflow.availableActions', []);

        $this->actingSession(5, 50, ['Bank'])
            ->getJson('/hr/salary/financial-records')
            ->assertOk()
            ->assertJsonPath('records.0.staffName', 'Restricted')
            ->assertJsonPath('records.0.claimsTotal', null)
            ->assertJsonPath('records.0.deductions', null)
            ->assertJsonPath('records.0.canViewSalaryDetails', false)
            ->assertJsonPath('records.0.workflow.availableActions', []);

        $this->actingSession(1, 10, ['Staff'])
            ->getJson('/hr/salary/financial-records')
            ->assertForbidden();
    }

    public function test_unassigned_manager_sees_redacted_financial_salary_records(): void
    {
        $response = $this->submitSalary([])->assertOk();
        $recordId = $response->json('record.id');
        $instanceId = $response->json('record.workflow.instanceId');

        $checkStepId = $this->salaryWorkflowStepId('check');
        DB::table('workflow_step_recipients')->insert([
            'step_id' => $checkStepId,
            'staff_id' => 50,
            'sort_order' => 0,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingSession(3, 30, ['Manager'])
            ->getJson('/hr/salary/financial-records')
            ->assertOk()
            ->assertJsonPath('records.0.staffName', 'Restricted')
            ->assertJsonPath('records.0.staffCode', '')
            ->assertJsonPath('records.0.basicSalary', null)
            ->assertJsonPath('records.0.employeeDeductions', null)
            ->assertJsonPath('records.0.canViewSalaryDetails', false)
            ->assertJsonPath('records.0.workflow.availableActions', []);

        $this->actingSession(3, 30, ['Manager'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson("/hr/salary/financial-records/{$recordId}/action", [
                'action' => 'check',
                'remarks' => 'Direct bypass attempt',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'You are not assigned to this workflow step.');

        $this->actingSession(3, 30, ['Manager'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson("/workflows/instances/{$instanceId}/actions", [
                'action' => 'check',
                'remarks' => 'Workflow bypass attempt',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'You are not assigned to this workflow step.');
    }

    public function test_salary_workflow_uses_fallback_roles_when_no_step_recipients_are_configured(): void
    {
        $response = $this->submitSalary([])->assertOk();
        $recordId = $response->json('record.id');
        $instanceId = $response->json('record.workflow.instanceId');

        $this->actingSession(3, 30, ['Manager'])
            ->getJson('/hr/salary/financial-records')
            ->assertOk()
            ->assertJsonPath('records.0.id', $recordId)
            ->assertJsonPath('records.0.canViewSalaryDetails', true)
            ->assertJsonPath('records.0.workflow.availableActions.0.action', 'check')
            ->assertJsonPath('records.0.workflow.availableActions.1.action', 'reject');

        $this->actingSession(3, 30, ['Manager'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson("/workflows/instances/{$instanceId}/actions", [
                'action' => 'check',
                'remarks' => 'Fallback checker',
            ])
            ->assertOk()
            ->assertJsonPath('record.status', 'Checked')
            ->assertJsonPath('record.checkedBy', 30);
    }

    public function test_financial_salary_records_can_be_checked_and_approved(): void
    {
        $response = $this->submitSalary([])->assertOk();
        $recordId = $response->json('record.id');
        $this->assignSalaryWorkflowRecipient('check', 30);
        $this->assignSalaryWorkflowRecipient('approve', 40);

        $this->actingSession(3, 30, ['Manager'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson("/hr/salary/financial-records/{$recordId}/action", [
                'action' => 'approve',
                'remarks' => 'Approve too early',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Salary record must be checked before approval.');

        $this->actingSession(3, 30, ['Manager'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson("/hr/salary/financial-records/{$recordId}/action", [
                'action' => 'check',
                'remarks' => 'Checked by finance',
            ])
            ->assertOk()
            ->assertJsonPath('record.status', 'Checked')
            ->assertJsonPath('record.checkedBy', 30)
            ->assertJsonPath('record.checkedStatus', 'Checked')
            ->assertJsonPath('record.checkedRemarks', 'Checked by finance')
            ->assertJsonPath('record.checkerCode', 'MGR');

        $this->actingSession(4, 40, ['System Admin'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson("/hr/salary/financial-records/{$recordId}/action", [
                'action' => 'approve',
                'remarks' => 'Approved for payment',
            ])
            ->assertOk()
            ->assertJsonPath('record.status', 'Approved')
            ->assertJsonPath('record.approvedBy', 40)
            ->assertJsonPath('record.approvedStatus', 'Approved')
            ->assertJsonPath('record.approvedRemarks', 'Approved for payment');
    }

    public function test_workflow_template_settings_have_role_protection_and_save_salary_recipients(): void
    {
        $this->actingSession(1, 10, ['Staff'])
            ->getJson('/workflows/templates')
            ->assertOk()
            ->assertJsonPath('templates.0.key', 'salary-application');

        $template = $this->actingSession(3, 30, ['Manager'])
            ->getJson('/workflows/templates/salary-application')
            ->assertOk()
            ->json('template');
        $checkStep = collect($template['steps'])->firstWhere('stepKey', 'check');

        $this->actingSession(1, 10, ['Staff'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->putJson('/workflows/templates/salary-application', [
                'steps' => [
                    ['id' => $checkStep['id'], 'recipient_staff_ids' => [30]],
                ],
            ])
            ->assertForbidden();

        $this->actingSession(3, 30, ['Manager'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->putJson('/workflows/templates/salary-application', [
                'steps' => [
                    ['id' => $checkStep['id'], 'recipient_staff_ids' => [30]],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('template.steps.0.recipients.0.staff_id', 30);
    }

    public function test_salary_workflow_blocks_maker_same_checker_approver_and_rejected_followups(): void
    {
        $response = $this->submitSalary([])->assertOk();
        $instanceId = $response->json('record.workflow.instanceId');
        $this->assignSalaryWorkflowRecipient('check', 30);
        $this->assignSalaryWorkflowRecipient('approve', 30);

        $this->actingSession(1, 10, ['Manager'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson("/workflows/instances/{$instanceId}/actions", [
                'action' => 'check',
                'remarks' => 'Self check',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'The maker cannot check or approve their own salary application.');

        $this->actingSession(3, 30, ['Manager'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson("/workflows/instances/{$instanceId}/actions", [
                'action' => 'check',
                'remarks' => 'Checked',
            ])
            ->assertOk()
            ->assertJsonPath('record.status', 'Checked');

        $this->actingSession(3, 30, ['Manager'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson("/workflows/instances/{$instanceId}/actions", [
                'action' => 'approve',
                'remarks' => 'Same actor',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Checker and approver must be different staff.');

        $second = $this->actingSession(2, 20, ['Staff'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->post('/hr/salary/applications', [
                'salary_month' => '2026-06',
                'basic_salary' => '4200',
                'claims_total' => '0',
                'employee_deductions' => '491.05',
                'employer_contributions' => '625.65',
                'payable_salary' => '3708.95',
                'deductions' => json_encode(['employeeTotal' => 491.05, 'employerTotal' => 625.65]),
                'claims' => json_encode([]),
            ])
            ->assertOk();
        $secondInstanceId = $second->json('record.workflow.instanceId');

        $this->actingSession(3, 30, ['Manager'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson("/workflows/instances/{$secondInstanceId}/actions", [
                'action' => 'reject',
                'remarks' => 'Rejected at check',
            ])
            ->assertOk()
            ->assertJsonPath('record.status', 'Rejected');

        $this->actingSession(3, 30, ['Manager'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson("/workflows/instances/{$secondInstanceId}/actions", [
                'action' => 'check',
                'remarks' => 'Try again',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Rejected salary records cannot be actioned further.');
    }

    public function test_system_admin_cannot_action_own_salary_workflow_without_assignment_bypass(): void
    {
        $response = $this->actingSession(4, 40, ['System Admin'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->post('/hr/salary/applications', [
                'salary_month' => '2026-07',
                'basic_salary' => '5000',
                'claims_total' => '0',
                'employee_deductions' => '580.00',
                'employer_contributions' => '720.00',
                'payable_salary' => '4420.00',
                'deductions' => json_encode(['employeeTotal' => 580, 'employerTotal' => 720]),
                'claims' => json_encode([]),
            ])
            ->assertOk();
        $recordId = $response->json('record.id');
        $instanceId = $response->json('record.workflow.instanceId');
        $this->assignSalaryWorkflowRecipient('check', 40);

        $this->actingSession(4, 40, ['System Admin'])
            ->getJson('/hr/salary/financial-records')
            ->assertOk()
            ->assertJsonPath('records.0.id', $recordId)
            ->assertJsonPath('records.0.staffName', 'Restricted')
            ->assertJsonPath('records.0.workflow.availableActions', []);

        $this->actingSession(4, 40, ['System Admin'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson("/workflows/instances/{$instanceId}/actions", [
                'action' => 'check',
                'remarks' => 'Admin checked own submitted salary',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'The maker cannot check or approve their own salary application.');
    }

    private function submitSalary(array $claims, array $attachments = [])
    {
        return $this->actingSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->post('/hr/salary/applications', [
                'salary_month' => '2026-05',
                'basic_salary' => '4200',
                'claims_total' => collect($claims)->sum('amount'),
                'employee_deductions' => '491.05',
                'employer_contributions' => '625.65',
                'payable_salary' => '4082.35',
                'deductions' => json_encode([
                    'employeeEpf' => 462,
                    'employeeSocso' => 21.35,
                    'employeeEis' => 7.70,
                    'employeeTotal' => 491.05,
                    'employerEpf' => 546,
                    'employerSocso' => 71.95,
                    'employerEis' => 7.70,
                    'employerTotal' => 625.65,
                ]),
                'claims' => json_encode($claims),
                'attachments' => $attachments,
            ]);
    }

    private function salaryWorkflowStepId(string $stepKey): int
    {
        return (int) DB::table('workflow_template_steps as step')
            ->join('workflow_templates as template', 'template.id', '=', 'step.template_id')
            ->where('template.process_key', 'salary-application')
            ->where('step.step_key', $stepKey)
            ->value('step.id');
    }

    private function assignSalaryWorkflowRecipient(string $stepKey, int $staffId): void
    {
        DB::table('workflow_step_recipients')->insert([
            'step_id' => $this->salaryWorkflowStepId($stepKey),
            'staff_id' => $staffId,
            'sort_order' => 0,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function salaryClaimPdfHtml(array $previousYearReference): string
    {
        return view('pdf.salary-claims-report', [
            'record' => [
                'id' => 1,
                'staffId' => 10,
                'staffName' => 'Staff Example',
                'staffCode' => 'STA',
                'salaryMonth' => 'May 2026',
                'salaryMonthValue' => '2026-05',
                'basicSalary' => 4200,
                'claimsTotal' => 0,
                'employeeDeductions' => 0,
                'employerContributions' => 0,
                'payableSalary' => 4200,
                'status' => 'Submitted',
                'deductions' => [],
                'submittedAt' => '2026-05-31 10:00:00',
            ],
            'claims' => [],
            'generatedAt' => Carbon::parse('2026-06-01 09:00:00'),
            'claimDate' => '2026-05-31 10:00:00',
            'applicantSignature' => [],
            'approverSignature' => [],
            'vehicle' => '-',
            'mileageRate' => 0.6,
            'medicalBalance' => [
                'currentLeft' => 0,
                'afterClaim' => 0,
            ],
            'previousYearReference' => $previousYearReference,
            'logoDataUri' => null,
        ])->render();
    }

    private function submitOtherClaim(array $claims, array $attachments = [], array $overrides = [])
    {
        return $this->actingSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->post('/hr/salary/other-claims', [
                ...$overrides,
                'claim_month' => $overrides['claim_month'] ?? '2026-05',
                'claims' => json_encode($claims),
                'attachments' => $attachments,
            ]);
    }

    private function updateSalaryProfile(string $defaultMileageRate = '0.60', string $yearlyMedicalClaim = '1200'): void
    {
        $this->actingSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->putJson('/hr/salary/profile', [
                'basic_salary' => '4200',
                'effective_month' => '2026-05',
                'default_mileage_rate' => $defaultMileageRate,
                'yearly_medical_claim' => $yearlyMedicalClaim,
                'recurring_allowances' => [],
            ])
            ->assertOk();
    }

    private function createApprovedWorkflow(
        string $subjectType,
        int $subjectId,
        int $makerStaffId = 10,
        int $actorStaffId = 50,
    ): void {
        $templateId = (int) DB::table('workflow_templates')
            ->where('process_key', 'salary-application')
            ->value('id');
        $approveStepId = (int) DB::table('workflow_template_steps')
            ->where('template_id', $templateId)
            ->where('step_key', 'approve')
            ->value('id');

        $instanceId = (int) DB::table('workflow_instances')->insertGetId([
            'template_id' => $templateId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'current_step_id' => $approveStepId ?: null,
            'status' => 'Approved',
            'maker_staff_id' => $makerStaffId,
            'submitted_by' => $makerStaffId,
            'submitted_at' => now(),
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('workflow_actions')->insert([
            'instance_id' => $instanceId,
            'step_id' => $approveStepId ?: null,
            'action' => 'approve',
            'status_from' => 'Checked',
            'status_to' => 'Approved',
            'actor_staff_id' => $actorStaffId,
            'remarks' => 'Approved for payment',
            'acted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function actingSession(int $userId = 1, int $staffId = 10, array $roles = ['Staff'])
    {
        return $this->withSession([
            '_token' => 'test-csrf-token',
            'user_id' => $userId,
            'staff_id' => $staffId,
            'roles' => $roles,
        ]);
    }

    private function createSystemUsersTable(): void
    {
        Schema::dropIfExists('system_users');
        Schema::create('system_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('email')->nullable();
            $table->json('role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('total_lock')->default(false);
            $table->timestamp('account_locked_until')->nullable();
        });

        DB::table('system_users')->insert([
            [
                'id' => 1,
                'staff_id' => 10,
                'email' => 'staff@example.test',
                'role' => json_encode(['Staff']),
                'is_active' => 1,
                'total_lock' => 0,
                'account_locked_until' => null,
            ],
            [
                'id' => 2,
                'staff_id' => 20,
                'email' => 'other@example.test',
                'role' => json_encode(['Staff']),
                'is_active' => 1,
                'total_lock' => 0,
                'account_locked_until' => null,
            ],
            [
                'id' => 3,
                'staff_id' => 30,
                'email' => 'manager@example.test',
                'role' => json_encode(['Manager']),
                'is_active' => 1,
                'total_lock' => 0,
                'account_locked_until' => null,
            ],
            [
                'id' => 4,
                'staff_id' => 40,
                'email' => 'admin@example.test',
                'role' => json_encode(['System Admin']),
                'is_active' => 1,
                'total_lock' => 0,
                'account_locked_until' => null,
            ],
            [
                'id' => 5,
                'staff_id' => 50,
                'email' => 'bank@example.test',
                'role' => json_encode(['Bank']),
                'is_active' => 1,
                'total_lock' => 0,
                'account_locked_until' => null,
            ],
        ]);
    }

    private function createStaffGeneralTable(): void
    {
        Schema::dropIfExists('staff_general');
        Schema::create('staff_general', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id')->unique();
            $table->string('full_name')->nullable();
            $table->string('name_code')->nullable();
            $table->string('status')->default('Active');
            $table->string('email')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        DB::table('staff_general')->insert([
            ['staff_id' => 10, 'full_name' => 'Staff Example', 'name_code' => 'STA', 'status' => 'Active', 'email' => 'staff@example.test'],
            ['staff_id' => 20, 'full_name' => 'Other Staff', 'name_code' => 'OTH', 'status' => 'Active', 'email' => 'other@example.test'],
            ['staff_id' => 30, 'full_name' => 'Manager Example', 'name_code' => 'MGR', 'status' => 'Active', 'email' => 'manager@example.test'],
            ['staff_id' => 40, 'full_name' => 'Admin Example', 'name_code' => 'ADM', 'status' => 'Active', 'email' => 'admin@example.test'],
            ['staff_id' => 50, 'full_name' => 'Bank Example', 'name_code' => 'BNK', 'status' => 'Active', 'email' => 'bank@example.test'],
        ]);
    }
}
