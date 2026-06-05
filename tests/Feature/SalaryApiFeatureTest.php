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

    public function test_application_create_replace_and_attachment_owner_access(): void
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
                'id' => 'claim-expense',
                'type' => 'Expense',
                'date' => '2026-05-16',
                'description' => 'Parking claim',
                'amount' => 65,
            ],
            [
                'id' => 'claim-mileage',
                'type' => 'Mileage',
                'date' => '2026-05-17',
                'description' => 'Office to Client site',
                'amount' => 8.40,
                'meta' => '12 KM',
                'km' => 12,
                'startLocation' => 'Office',
                'endLocation' => 'Client site',
            ],
            [
                'id' => 'claim-medical',
                'type' => 'Medical',
                'date' => '2026-05-18',
                'description' => 'Clinic claim',
                'amount' => 90,
            ],
        ], [
            'claim-expense' => UploadedFile::fake()->create('parking.pdf', 100, 'application/pdf'),
            'claim-medical' => UploadedFile::fake()->create('clinic.pdf', 100, 'application/pdf'),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('record.status', 'Submitted')
            ->assertJsonPath('record.claims.1.attachment.name', 'parking.pdf');
        $recordId = $response->json('record.id');
        $attachmentId = $response->json('record.claims.1.attachment.id');

        Storage::disk('private')->assertExists(
            DB::table('hr_salary_claim_attachments')->where('id', $attachmentId)->value('stored_path'),
        );

        $this->actingSession()
            ->get("/hr/salary/attachments/{$attachmentId}")
            ->assertOk();

        $this->actingSession(2, 20)
            ->getJson("/hr/salary/attachments/{$attachmentId}")
            ->assertNotFound();

        $this->submitSalary([
            [
                'id' => 'claim-expense-replaced',
                'type' => 'Expense',
                'date' => '2026-05-20',
                'description' => 'Updated parking claim',
                'amount' => 75,
                'attachmentId' => $attachmentId,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('record.id', $recordId)
            ->assertJsonPath('record.claims.0.description', 'Updated parking claim')
            ->assertJsonPath('record.claims.0.attachment.name', 'parking.pdf');

        $this->assertDatabaseCount('hr_salary_applications', 1);
        $this->assertDatabaseCount('hr_salary_claims', 1);
        $this->assertDatabaseCount('hr_salary_claim_attachments', 1);
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
                    [
                        'id' => 'claim-mileage',
                        'type' => 'Mileage',
                        'date' => '2026-05-17',
                        'description' => 'Office to Client site',
                        'km' => 10,
                        'startLocation' => 'Office',
                        'endLocation' => 'Client site',
                    ],
                ]),
            ])
            ->assertOk()
            ->assertJsonPath('record.basicSalary', 4200)
            ->assertJsonPath('record.claimsTotal', 115)
            ->assertJsonPath('record.employeeDeductions', 491.05)
            ->assertJsonPath('record.payableSalary', 3823.95)
            ->assertJsonPath('record.claims.1.amount', 15);

        $stored = DB::table('hr_salary_applications')->where('id', $response->json('record.id'))->first();
        $this->assertSame(4200.0, (float) $stored->basic_salary);
        $this->assertSame(115.0, (float) $stored->claims_total);
        $this->assertSame(491.05, (float) $stored->employee_deductions);
        $this->assertSame(3823.95, (float) $stored->payable_salary);
    }

    public function test_staff_can_delete_submitted_or_rejected_salary_application_only(): void
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

        $this->actingSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->deleteJson("/hr/salary/records/{$checkedId}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only draft, submitted/prepared, or rejected salary applications can be deleted by staff.');
    }

    public function test_salary_claims_pdf_export_is_available_to_record_owner(): void
    {
        $response = $this->submitSalary([
            [
                'id' => 'claim-expense',
                'type' => 'Expense',
                'date' => '2026-05-16',
                'description' => 'Parking claim',
                'amount' => 65,
            ],
        ], [
            'claim-expense' => UploadedFile::fake()->create('parking.pdf', 100, 'application/pdf'),
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

    public function test_financial_salary_claims_pdf_export_is_available_to_workflow_participants(): void
    {
        $response = $this->submitSalary([
            [
                'id' => 'claim-expense',
                'type' => 'Expense',
                'date' => '2026-05-16',
                'description' => 'Parking claim',
                'amount' => 65,
            ],
        ], [
            'claim-expense' => UploadedFile::fake()->create('parking.pdf', 100, 'application/pdf'),
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
                'id' => 'claim-expense',
                'type' => 'Expense',
                'date' => '2026-05-16',
                'description' => 'Parking claim',
                'amount' => 65,
            ],
        ], [
            'claim-expense' => UploadedFile::fake()->create('parking.pdf', 100, 'application/pdf'),
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
            ->assertSee('This salary month has already entered review or final approval and cannot be replaced.');

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

    public function test_application_enforces_claim_attachment_rules(): void
    {
        $missingAttachment = $this->actingSession()
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
            'Expense claims require an attachment.',
            $missingAttachment->json('errors')['claims.0.attachment'][0] ?? null,
        );

        $mileageAttachment = $this->actingSession()
            ->withHeader('Accept', 'application/json')
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->post('/hr/salary/applications', [
                'salary_month' => '2026-05',
                'basic_salary' => '4200',
                'deductions' => json_encode([]),
                'claims' => json_encode([
                    [
                        'id' => 'claim-mileage',
                        'type' => 'Mileage',
                        'date' => '2026-05-17',
                        'description' => 'Office to Client site',
                        'amount' => 6,
                        'km' => 10,
                        'startLocation' => 'Office',
                        'endLocation' => 'Client site',
                    ],
                ]),
                'attachments' => [
                    'claim-mileage' => UploadedFile::fake()->create('mileage.pdf', 100, 'application/pdf'),
                ],
            ])
            ->assertStatus(422);
        $this->assertSame(
            'Mileage claims cannot include attachments.',
            $mileageAttachment->json('errors')['attachments.claim-mileage'][0] ?? null,
        );
    }

    public function test_application_rejects_invalid_attachment_uploads(): void
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
            ->post('/hr/salary/applications', [
                'salary_month' => '2026-05',
                'basic_salary' => '4200',
                'deductions' => json_encode([]),
                'claims' => json_encode($claims),
                'attachments' => [
                    'claim-expense' => UploadedFile::fake()->create('receipt.txt', 10, 'text/plain'),
                    'claim-medical' => UploadedFile::fake()->create('clinic.pdf', 6000, 'application/pdf'),
                    'unknown-claim' => UploadedFile::fake()->create('unknown.pdf', 100, 'application/pdf'),
                ],
            ])
            ->assertStatus(422);
        $this->assertSame(
            'Upload a PDF, JPG, JPEG, or PNG file up to 5 MB.',
            $response->json('errors')['attachments.claim-expense'][0] ?? null,
        );
        $this->assertSame(
            'Upload a PDF, JPG, JPEG, or PNG file up to 5 MB.',
            $response->json('errors')['attachments.claim-medical'][0] ?? null,
        );
        $this->assertSame(
            'Attachment does not match a claim row.',
            $response->json('errors')['attachments.unknown-claim'][0] ?? null,
        );
    }

    public function test_application_enforces_annual_medical_claim_balance(): void
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

        $this->submitSalary([
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
            ->post('/hr/salary/applications', [
                'salary_month' => '2026-06',
                'basic_salary' => '4200',
                'deductions' => json_encode([]),
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
