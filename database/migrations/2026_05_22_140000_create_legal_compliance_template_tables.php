<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_compliance_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 191)->unique();
            $table->text('description')->nullable();
            $table->json('draft_content')->nullable();
            $table->unsignedBigInteger('active_version_id')->nullable()->index();
            $table->boolean('is_default')->default(false)->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('legal_compliance_template_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('template_id')->index();
            $table->unsignedInteger('version_number');
            $table->json('content');
            $table->unsignedBigInteger('published_by')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['template_id', 'version_number'], 'legal_template_version_unique');
        });

        Schema::table('legal_compliance_assessments', function (Blueprint $table) {
            if (! Schema::hasColumn('legal_compliance_assessments', 'template_id')) {
                $table->unsignedBigInteger('template_id')->nullable()->after('staff_id')->index();
            }
            if (! Schema::hasColumn('legal_compliance_assessments', 'template_version_id')) {
                $table->unsignedBigInteger('template_version_id')->nullable()->after('template_id')->index();
            }
            if (! Schema::hasColumn('legal_compliance_assessments', 'template_snapshot')) {
                $table->json('template_snapshot')->nullable()->after('template_version');
            }
        });

        $content = $this->defaultTemplateContent();

        $now = now();
        $templateId = DB::table('legal_compliance_templates')->insertGetId([
            'name' => 'Free Legal Compliance Assessment',
            'slug' => 'free-legal-compliance-assessment',
            'description' => 'Default legal compliance assessment template.',
            'draft_content' => json_encode($content),
            'is_default' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $versionId = DB::table('legal_compliance_template_versions')->insertGetId([
            'template_id' => $templateId,
            'version_number' => 1,
            'content' => json_encode($content),
            'metadata' => json_encode([
                'change_note' => 'Initial default template seeded.',
                'changed_by_staff_id' => null,
                'changed_by_name' => 'System',
            ]),
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('legal_compliance_templates')
            ->where('id', $templateId)
            ->update(['active_version_id' => $versionId]);
    }

    public function down(): void
    {
        Schema::table('legal_compliance_assessments', function (Blueprint $table) {
            if (Schema::hasColumn('legal_compliance_assessments', 'template_snapshot')) {
                $table->dropColumn('template_snapshot');
            }
            if (Schema::hasColumn('legal_compliance_assessments', 'template_version_id')) {
                $table->dropColumn('template_version_id');
            }
            if (Schema::hasColumn('legal_compliance_assessments', 'template_id')) {
                $table->dropColumn('template_id');
            }
        });

        Schema::dropIfExists('legal_compliance_template_versions');
        Schema::dropIfExists('legal_compliance_templates');
    }

    private function defaultTemplateContent(): array
    {
        return [
            'title' => 'Free Legal Compliance Assessment',
            'description' => 'Default legal compliance assessment template.',
            'groups' => [
                [
                    'id' => 'osha-1994',
                    'title' => 'Occupational Safety and Health Act (OSHA) 1994',
                    'clauses' => [
                        $this->clause('osha-15a', 'Section 15(a), OSHA 1994 - Duty to Establish Safe Operating Procedure (SOP) for All Work Activities', "'(a) provide and maintain system of works that are safe and without risk to health.'"),
                        $this->clause('osha-15b', 'Section 15(b), OSHA 1994 - Duty to Establish Risk Assessment to All Work Activities', "'(b) make arrangements to ensure absence of safety and health risks related to operation, handling, storage and transportation of plant and substances.'"),
                        $this->clause('osha-15c', 'Section 15(c), OSHA 1994 - Duty to Inform, Instruct, Train and Supervise all Employees, Visitors and Contractors', "'(c) provide information, instruction, training and supervision related to safety and health to all employees and related personnel.'"),
                        $this->clause('osha-15f', 'Section 15(f), OSHA 1994 - Duty to Managing Emergency Response at Workplace', "'(f) the development and implementation of procedures for dealing with emergencies that may arise while his employees are at work.'"),
                        $this->clause('osha-16', 'Section 16, OSHA 1994 - Duty to Formulate Safety and Health Policy', 'It shall be the duty of every employer and every self-employed person to prepare and revise a written safety and health policy and to ensure all employees notice of the policy, including any revisions made thereafter.'),
                        $this->clause('osha-18b', 'Section 18B, OSHA 1994 - Duty to Conduct and Implement Risk Assessment', 'Every employer, self-employed person or principal shall conduct a risk assessment in relation to the safety and health risk posed to any person who may be affected by his undertaking at the place of work.'),
                        $this->clause('osha-27d', 'Section 27D, OSHA 1994 - Certificate of Fitness', 'No person shall operate or cause or permit to be operated any plant that has been installed under section 27C unless the plant has a certificate of fitness issued by an officer or a licensed person.'),
                        $this->clause('osha-29', 'Section 29, OSHA 1994 - Duty to Hire a Safety and Health Officer (SHO)', 'The section shall apply to industries stipulated in the Occupational Safety and Health (Safety and Health Officer) Order 1997. The SHO shall be employed purposely for the specified place of work.'),
                        $this->clause('osha-29a', 'Section 29A, OSHA 1994 - Appointment of OSH Coordinator', 'An employer whose place of work is not included in any class or description of place of work published under subsection 29(1) shall appoint an employee to act as an occupational safety and health coordinator if he employs five or more employees.'),
                        $this->clause('osha-30', 'Section 30, OSHA 1994 - Establishment of Safety and Health Committee at Place of Work', 'Safety and Health Committee must be established when there are 40 or more persons employed at the workplace, or as directed by the Director General.'),
                        $this->clause('osha-32', 'Section 32, OSHA 1994 - Notification of Accidents, Dangerous Occurrence, Occupational Poisoning and Occupational Diseases', 'Employers shall notify the nearest DOSH office for any accident, dangerous occurrence, occupational poisoning or occupational disease that occurred at the workplace.'),
                    ],
                ],
                [
                    'id' => 'usechh-2000',
                    'title' => 'Occupational Safety and Health (Use and Standards of Exposure of Chemicals Hazardous to Health) (USECHH) Regulations 2000',
                    'clauses' => [
                        $this->clause('usechh-5', 'Regulation 5, USECHH 2000 - Register of Chemicals Hazardous to Health', 'Employer shall identify and record all chemicals hazardous to health in a register. The register must be maintained in good order and condition and updated from time to time.'),
                        $this->clause('usechh-9', 'Regulation 9, USECHH 2000 - Chemical Health Risk Assessment (CHRA)', 'Employer cannot carry any work which exposes its employees to any chemical hazardous to health unless a written assessment of the risks is conducted.'),
                        $this->clause('usechh-14', 'Regulation 14, USECHH 2000 - Action to Control Exposure', 'If control measures are required as indicated by the chemical health risk assessment, employer must carry out such action within one month from receipt of the report.'),
                        $this->clause('usechh-16', 'Regulation 16, USECHH 2000 - Use of Approved Personal Protective Equipment', 'If control measures in the form of approved protective equipment are specified by the chemical health risk assessment, it must be implemented and the equipment must be provided, maintained, inspected and employees trained on its use.'),
                        $this->clause('usechh-17', 'Regulation 17, USECHH 2000 - Engineering Control Equipment: Local Exhaust Ventilation System (LEV)', 'Any engineering control equipment shall be inspected every month and tested for effectiveness by a DOSH registered hygiene technician every 12 months. The LEV shall be maintained and remain operated when work activity is being conducted.'),
                        $this->clause('usechh-20', 'Regulation 20, USECHH 2000 - Duty of Employer to Ensure Labeling of Hazardous Chemicals', 'Employers shall ensure that all hazardous chemicals supplied or purchased and available at the workplace are labeled and that labels are not removed, defaced, modified or altered.'),
                        $this->clause('usechh-22', 'Regulation 22, USECHH 2000 - Duty of Employer to Ensure Information, Instruction, and Training are Given to Employees', 'Employees exposed to hazardous chemicals should be trained on the health risk they are exposed to while working with the chemicals. The training programme shall be reviewed and conducted once every two years or when relevant changes occur.'),
                    ],
                ],
                [
                    'id' => 'noise-exposure-2019',
                    'title' => 'Occupational Safety and Health (Noise Exposure) Regulations 2019',
                    'clauses' => [
                        $this->clause('noise-4', 'Regulation 4, Noise Exposure Regulations 2019 - Noise Risk Assessment', 'If employees are exposed to excessive noise, noise risk assessment should be conducted by a DOSH registered noise risk assessor.'),
                    ],
                ],
                [
                    'id' => 'eqa-1974',
                    'title' => 'Environmental Quality Act 1974',
                    'clauses' => [
                        $this->clause('eqa-22', 'Section 22, EQA 1974 - Restriction on Pollution of the Atmosphere', 'No persons shall release or emit any environmentally hazardous substances, pollutants or wastes into the atmosphere unless licensed to do so.'),
                        $this->clause('eqa-25', 'Section 25, EQA 1974 - Restriction on Pollution of the Inland Waters', 'No persons shall release or emit any environmentally hazardous substances, pollutants or wastes into any inland waters unless licensed to do so.'),
                        $this->clause('eqa-34b', 'Section 34B, EQA 1974 - Prohibition Against Placing, Deposit, etc. of Scheduled Wastes', 'No persons shall place, deposit, dispose of, receive, send or transit scheduled wastes without prior written approval by the Department of Environment, Malaysia.'),
                    ],
                ],
            ],
        ];
    }

    private function clause(string $id, string $title, string $excerpt): array
    {
        return [
            'id' => $id,
            'reference' => '',
            'title' => $title,
            'excerpt' => $excerpt,
            'fields' => $this->defaultClauseFields(),
        ];
    }

    private function defaultClauseFields(): array
    {
        return [
            [
                'key' => 'complianceStatus',
                'label' => 'Compliance Status',
                'type' => 'radio',
                'required' => true,
                'options' => [
                    ['value' => 'comply', 'label' => 'Comply'],
                    ['value' => 'not_comply', 'label' => 'Not comply'],
                ],
            ],
            [
                'key' => 'finding',
                'label' => 'Assessment Finding',
                'type' => 'textarea',
                'required' => true,
                'rows' => 2,
            ],
        ];
    }
};
