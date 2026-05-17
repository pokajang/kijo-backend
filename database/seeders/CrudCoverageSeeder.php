<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CrudCoverageSeeder extends Seeder
{
    private array $columns = [];

    public function run(): void
    {
        $now = now();

        DB::beginTransaction();

        try {
            $this->call(BatchTwoSmokeSeeder::class);

            $staff  = DB::table('staff_general')->where('email', 'seed.batch2.staff@kijo.local')->first();
            $client = DB::table('client_company')->where('company_name', 'Batch2 Seed Client Sdn Bhd')->first();
            $vendor = DB::table('vendor_main_details')->where('vendor_name', 'Batch2 Seed Vendor Sdn Bhd')->first();
            $project = DB::table('projects_main')->where('project_name', 'Batch2 Seed Project')->first();
            $catalogItem = DB::table('catalog_items')->where('item_name', 'Batch2 Seed N95 Mask')->first();
            $pic = DB::table('client_pic')->where('email', 'seed.batch2.pic@client.local')->first();

            if (! $staff || ! $client || ! $vendor || ! $project || ! $catalogItem) {
                throw new \RuntimeException('BatchTwoSmokeSeeder prerequisite records are missing.');
            }

            $staffId      = (int) $staff->staff_id;
            $staffName    = (string) $staff->full_name;
            $staffCode    = (string) $staff->name_code;
            $clientId     = (int) $client->company_id;
            $vendorId     = (int) $vendor->vendor_id;
            $projectId    = (int) $project->id;
            $catalogItemId = (int) $catalogItem->id;

            $feedbackId = $this->firstOrInsert(
                'system_feedbacks',
                ['feedback' => 'CRUD coverage feedback seed'],
                [
                    'feedback'      => 'CRUD coverage feedback seed',
                    'reported_by'   => $staffId,
                    'date_reported' => $now,
                    'status'        => 'Open',
                    'action_date'   => null,
                    'remarks'       => 'Seeded for Laravel CRUD coverage.',
                    'updated_at'    => $now,
                ]
            );

            $toolRequestId = $this->firstOrInsert(
                'tool_requests',
                ['staff_id' => $staffId, 'equipment_detail' => 'CRUD seed laptop'],
                [
                    'staff_id'         => $staffId,
                    'equipment_detail' => 'CRUD seed laptop',
                    'use_start_date'   => $now->toDateString(),
                    'use_end_date'     => $now->copy()->addDays(2)->toDateString(),
                    'purpose'          => 'Laravel CRUD smoke coverage',
                    'remarks'          => 'Seeded tool request',
                    'achievement'      => 0,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ]
            );

            $sportEventId = $this->firstOrInsert(
                'sport_events',
                ['event_name' => 'CRUD Coverage Futsal'],
                [
                    'event_name'    => 'CRUD Coverage Futsal',
                    'event_datetime'=> $now->copy()->addWeek(),
                    'image_path'    => '',
                    'image_name'    => '',
                    'image_size'    => 0,
                    'image_mime'    => '',
                    'created_by'    => $staffId,
                    'created_name'  => $staffName,
                    'created_code'  => $staffCode,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]
            );

            $this->updateOrInsert(
                'sport_event_attendees',
                ['event_id' => $sportEventId, 'staff_id' => $staffId],
                [
                    'event_id'    => $sportEventId,
                    'staff_id'    => $staffId,
                    'staff_name'  => $staffName,
                    'staff_code'  => $staffCode,
                    'created_at'  => $now,
                ]
            );

            $deliveryOrderId = $this->firstOrInsert(
                'do_details',
                ['do_number' => 'DO-CRUD-SEED-0001'],
                [
                    'project_id'               => $projectId,
                    'do_number'                => 'DO-CRUD-SEED-0001',
                    'client_name'              => (string) $client->company_name,
                    'client_address'           => trim(implode(', ', array_filter([
                        $client->address ?? null,
                        $client->city ?? null,
                        $client->state ?? null,
                        $client->zip ?? null,
                    ]))),
                    'client_contact_name'      => (string) ($pic->full_name ?? 'Batch2 PIC'),
                    'client_contact_position'  => (string) ($pic->position ?? 'Manager'),
                    'client_contact_email'     => (string) ($pic->email ?? 'seed.batch2.pic@client.local'),
                    'client_contact_phone'     => (string) ($pic->mobile_number ?? '60112223344'),
                    'company_contact_name'     => $staffName,
                    'company_contact_email'    => (string) $staff->email,
                    'company_contact_phone'    => (string) $staff->mobile_number,
                    'project_name'             => (string) $project->project_name,
                    'project_code'             => 'PRJ-CRUD-SEED',
                    'project_award_date'       => $project->award_date ?? $now->toDateString(),
                    'project_type'             => (string) $project->project_type,
                    'project_description'      => (string) $project->description,
                    'project_service_period'   => $this->servicePeriod($project->service_start_date ?? null, $project->service_end_date ?? null),
                    'created_by'               => $staffId,
                    'created_at'               => $now,
                    'updated_at'               => $now,
                ]
            );

            $this->updateOrInsert(
                'do_breakdown',
                ['do_id' => $deliveryOrderId, 'item_name' => 'Batch2 Seed N95 Mask'],
                [
                    'do_id'       => $deliveryOrderId,
                    'item_name'   => 'Batch2 Seed N95 Mask',
                    'description' => 'CRUD coverage seeded delivery order item',
                    'quantity'    => 1,
                    'unit'        => 'box',
                    'created_at'  => $now,
                ]
            );

            $taskId = $this->firstOrInsert(
                'tasks',
                ['title' => 'CRUD coverage task'],
                [
                    'staff_id'      => $staffId,
                    'title'         => 'CRUD coverage task',
                    'status'        => 'Pending',
                    'created_at'    => $now,
                    'due_date'      => $now->copy()->addDays(3),
                    'completed_at'  => null,
                ]
            );

            $this->updateOrInsert(
                'task_comments',
                ['task_id' => $taskId, 'comment' => 'CRUD coverage task comment'],
                [
                    'task_id'    => $taskId,
                    'comment'    => 'CRUD coverage task comment',
                    'created_at' => $now,
                ]
            );

            $procedureId = $this->firstOrInsert(
                'procedures',
                ['title' => 'CRUD coverage procedure'],
                [
                    'title'        => 'CRUD coverage procedure',
                    'description'  => 'Seeded procedure for Laravel CRUD verification.',
                    'file_path'    => '',
                    'file_name'    => '',
                    'file_size'    => 0,
                    'mime_type'    => '',
                    'created_by'   => $staffId,
                    'created_name' => $staffName,
                    'created_code' => $staffCode,
                    'created_at'   => $now,
                    'category'     => 'Operations',
                ]
            );

            $meetingId = $this->firstOrInsert(
                'meeting_minutes',
                ['meeting_title' => 'CRUD coverage standup'],
                [
                    'meeting_title'       => 'CRUD coverage standup',
                    'meeting_type'        => 'Internal',
                    'meeting_datetime'    => $now,
                    'venue'               => 'HQ Meeting Room',
                    'guest_attendees_text'=> '',
                    'agenda'              => 'Laravel migration coverage',
                    'minutes_text'        => 'Reviewed CRUD migration coverage data set.',
                    'action_items'        => 'Keep CRUD smoke data available.',
                    'attachment_path'     => '',
                    'attachment_name'     => '',
                    'attachment_size'     => 0,
                    'attachment_mime'     => '',
                    'created_by'          => $staffId,
                    'created_name'        => $staffName,
                    'created_code'        => $staffCode,
                    'updated_by'          => $staffId,
                    'updated_name'        => $staffName,
                    'updated_code'        => $staffCode,
                    'verification_status' => 'Pending',
                    'verified_by'         => null,
                    'verified_name'       => null,
                    'verified_code'       => null,
                    'verified_at'         => null,
                    'concurred_by'        => null,
                    'concurred_name'      => null,
                    'concurred_code'      => null,
                    'concurred_at'        => null,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]
            );

            $this->updateOrInsert(
                'meeting_minute_attendees',
                ['meeting_id' => $meetingId, 'staff_id' => $staffId],
                [
                    'meeting_id'  => $meetingId,
                    'staff_id'    => $staffId,
                    'staff_name'  => $staffName,
                    'staff_code'  => $staffCode,
                    'created_at'  => $now,
                ]
            );

            $this->updateOrInsert(
                'meeting_minute_comments',
                ['meeting_id' => $meetingId, 'comment_text' => 'CRUD coverage verification note'],
                [
                    'meeting_id'   => $meetingId,
                    'comment_type' => 'verification',
                    'comment_text' => 'CRUD coverage verification note',
                    'actor_id'     => $staffId,
                    'actor_name'   => $staffName,
                    'actor_code'   => $staffCode,
                    'created_at'   => $now,
                ]
            );

            $trainingTemplateId = $this->firstOrInsert(
                'proposal_template_training_main',
                ['training_code' => 'TR-CRUD-SEED'],
                [
                    'is_deleted'              => 0,
                    'deleted_by'              => null,
                    'deleted_at'              => null,
                    'training_title'          => 'CRUD Coverage Training',
                    'training_code'           => 'TR-CRUD-SEED',
                    'hrd_no'                  => 'HRD-CRUD-001',
                    'introduction'            => 'Training template seeded for CRUD coverage.',
                    'objectives'              => 'Verify Laravel training proposal CRUD.',
                    'modules'                 => 'Module A, Module B',
                    'training_requirements'   => 'Projector and room',
                    'additional_requirements' => 'Attendance list',
                    'training_materials'      => 'Slides',
                    'lecture_medium'          => 'English',
                    'method_theory'           => 1,
                    'method_theory_desc'      => 'Lecture',
                    'method_practical'        => 1,
                    'method_practical_desc'   => 'Workshop',
                    'created_at'              => $now,
                    'duration'                => '2 days',
                    'service_type'            => 'training',
                ]
            );

            $this->updateOrInsert(
                'proposal_template_training_agenda',
                ['template_id' => $trainingTemplateId, 'day' => 1, 'topic' => 'CRUD Coverage Kickoff'],
                [
                    'template_id' => $trainingTemplateId,
                    'day'         => 1,
                    'start_time'  => '09:00:00',
                    'end_time'    => '12:00:00',
                    'topic'       => 'CRUD Coverage Kickoff',
                    'created_at'  => $now,
                ]
            );

            $manpowerTemplateId = $this->firstOrInsert(
                'proposal_template_manpower',
                ['service_code' => 'MP-CRUD-SEED'],
                [
                    'is_deleted'                    => 0,
                    'deleted_by'                    => null,
                    'deleted_at'                    => null,
                    'service_title'                 => 'CRUD Coverage Manpower',
                    'service_code'                  => 'MP-CRUD-SEED',
                    'introduction'                  => 'Seed manpower proposal.',
                    'service_deliverables'          => 'Assigned manpower support',
                    'supplied_manpower_deliverables'=> 'Two technicians',
                    'custom_section'                => 'Night shift available',
                    'created_at'                    => $now,
                    'service_type'                  => 'manpower',
                ]
            );

            $ihTemplateId = $this->firstOrInsert(
                'proposal_template_ih',
                ['service_code' => 'IH-CRUD-SEED'],
                [
                    'is_deleted'   => 0,
                    'deleted_by'   => null,
                    'deleted_at'   => null,
                    'service_title'=> 'CRUD Coverage IH',
                    'service_code' => 'IH-CRUD-SEED',
                    'introduction' => 'Seed industrial hygiene proposal.',
                    'objectives'   => 'Verify IH CRUD.',
                    'work_scope'   => 'Sampling and report',
                    'schedule'     => 'As agreed',
                    'reference'    => 'Internal standard',
                    'other_fields' => 'None',
                    'created_at'   => $now,
                    'service_type' => 'ih',
                ]
            );

            $specialTemplateId = $this->firstOrInsert(
                'proposal_template_special',
                ['service_code' => 'SP-CRUD-SEED'],
                [
                    'is_deleted'   => 0,
                    'deleted_by'   => null,
                    'deleted_at'   => null,
                    'service_title'=> 'CRUD Coverage Special Service',
                    'service_code' => 'SP-CRUD-SEED',
                    'content'      => 'Seeded special proposal body.',
                    'created_at'   => $now,
                    'service_type' => 'special',
                ]
            );

            $equipmentQuoteId = $this->firstOrInsert(
                'quotes_equipment',
                ['quote_ref_no' => 'EQ-CRUD-SEED-0001'],
                [
                    'service_group'      => 'equipment',
                    'quote_running_no'   => 900001,
                    'quote_ref_no'       => 'EQ-CRUD-SEED-0001',
                    'revision_no'        => 0,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                    'status'             => 'Pending',
                    'status_remarks'     => 'Seeded for CRUD coverage',
                    'award_date'         => null,
                    'client_award_ref_no'=> null,
                    'created_by_id'      => $staffId,
                    'created_by_name'    => $staffName,
                    'created_by_code'    => $staffCode,
                    'client_id'          => $clientId,
                    'client_name'        => (string) $client->company_name,
                    'client_ssm'         => (string) ($client->ssm_number ?? ''),
                    'client_address'     => (string) ($client->address ?? ''),
                    'client_city'        => (string) ($client->city ?? ''),
                    'client_state'       => (string) ($client->state ?? ''),
                    'client_zip'         => (string) ($client->zip ?? ''),
                    'pic_name'           => (string) ($pic->full_name ?? 'Batch2 PIC'),
                    'pic_email'          => (string) ($pic->email ?? 'seed.batch2.pic@client.local'),
                    'pic_phone'          => (string) ($pic->mobile_number ?? '60112223344'),
                    'pic_position'       => (string) ($pic->position ?? 'Manager'),
                    'inquiry_remarks'    => 'Seeded equipment quote',
                    'discount'           => 0,
                    'delivery_charge'    => 0,
                    'misc_charge'        => 0,
                    'sst_percent'        => 0,
                    'sst_amount'         => 0,
                    'sub_total'          => 120,
                    'grand_total'        => 120,
                    'attach_proposal'    => 0,
                ]
            );

            $this->updateOrInsert(
                'quotes_equipment_items',
                ['quote_id' => $equipmentQuoteId, 'item_id' => $catalogItemId],
                [
                    'quote_id'         => $equipmentQuoteId,
                    'item_id'          => $catalogItemId,
                    'quantity'         => 1,
                    'unit_price'       => 100,
                    'marked_up_price'  => 120,
                    'line_total'       => 120,
                    'created_by'       => $staffId,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ]
            );

            $manpowerQuoteId = $this->firstOrInsert(
                'quotes_manpower',
                ['quote_ref_no' => 'MP-CRUD-SEED-0001'],
                [
                    'service_group'       => 'manpower',
                    'quote_running_no'    => 900002,
                    'quote_ref_no'        => 'MP-CRUD-SEED-0001',
                    'revision_no'         => 0,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                    'status'              => 'Pending',
                    'status_remarks'      => 'Seeded for CRUD coverage',
                    'award_date'          => null,
                    'client_award_ref_no' => null,
                    'created_by_id'       => $staffId,
                    'created_by_name'     => $staffName,
                    'created_by_code'     => $staffCode,
                    'client_id'           => $clientId,
                    'client_name'         => (string) $client->company_name,
                    'client_ssm'          => (string) ($client->ssm_number ?? ''),
                    'client_address'      => (string) ($client->address ?? ''),
                    'client_city'         => (string) ($client->city ?? ''),
                    'client_state'        => (string) ($client->state ?? ''),
                    'client_zip'          => (string) ($client->zip ?? ''),
                    'pic_name'            => (string) ($pic->full_name ?? 'Batch2 PIC'),
                    'pic_email'           => (string) ($pic->email ?? 'seed.batch2.pic@client.local'),
                    'pic_phone'           => (string) ($pic->mobile_number ?? '60112223344'),
                    'pic_position'        => (string) ($pic->position ?? 'Manager'),
                    'mp_id'               => $manpowerTemplateId,
                    'service_title'       => 'CRUD Coverage Manpower',
                    'service_code'        => 'MP-CRUD-SEED',
                    'nature_of_work'      => 'Support operations',
                    'site_location'       => 'Kajang',
                    'duration_months'     => 1,
                    'no_of_pax'           => 2,
                    'unit_cost'           => 1000,
                    'discount'            => 0,
                    'sst_percent'         => 0,
                    'sst_amount'          => 0,
                    'sub_total'           => 2000,
                    'grand_total'         => 2000,
                    'inquiry_remarks'     => 'Seeded manpower quote',
                    'attach_proposal'     => 1,
                ]
            );

            $ihQuoteId = $this->firstOrInsert(
                'quotes_ih',
                ['quote_ref_no' => 'IH-CRUD-SEED-0001'],
                [
                    'service_group'       => 'ih',
                    'client_id'           => $clientId,
                    'client_name'         => (string) $client->company_name,
                    'client_ssm'          => (string) ($client->ssm_number ?? ''),
                    'client_address'      => (string) ($client->address ?? ''),
                    'client_city'         => (string) ($client->city ?? ''),
                    'client_state'        => (string) ($client->state ?? ''),
                    'client_zip'          => (string) ($client->zip ?? ''),
                    'pic_name'            => (string) ($pic->full_name ?? 'Batch2 PIC'),
                    'pic_email'           => (string) ($pic->email ?? 'seed.batch2.pic@client.local'),
                    'pic_phone'           => (string) ($pic->mobile_number ?? '60112223344'),
                    'pic_position'        => (string) ($pic->position ?? 'Manager'),
                    'service_id'          => $ihTemplateId,
                    'service_title'       => 'CRUD Coverage IH',
                    'service_code'        => 'IH-CRUD-SEED',
                    'site_address'        => 'Kajang Plant',
                    'travel_charge'       => 0,
                    'sample_counts'       => 3,
                    'sample_unit'         => 'samples',
                    'num_work_units'      => 1,
                    'complexity_rating'   => 1,
                    'complexity_markup'   => 0,
                    'inquiry_remarks'     => 'Seeded IH quote',
                    'unit_price'          => 1500,
                    'discount'            => 0,
                    'sst_percent'         => 0,
                    'sst_amount'          => 0,
                    'sub_total'           => 1500,
                    'grand_total'         => 1500,
                    'quote_running_no'    => 900003,
                    'quote_ref_no'        => 'IH-CRUD-SEED-0001',
                    'revision_no'         => 0,
                    'attach_proposal'     => 1,
                    'status'              => 'Pending',
                    'status_remarks'      => 'Seeded for CRUD coverage',
                    'award_date'          => null,
                    'client_award_ref_no' => null,
                    'created_by_id'       => $staffId,
                    'created_by_name'     => $staffName,
                    'created_by_code'     => $staffCode,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]
            );

            $specialQuoteId = $this->firstOrInsert(
                'quotes_special',
                ['quote_ref_no' => 'SP-CRUD-SEED-0001'],
                [
                    'service_group'       => 'special',
                    'quote_running_no'    => 900004,
                    'quote_ref_no'        => 'SP-CRUD-SEED-0001',
                    'revision_no'         => 0,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                    'status'              => 'Pending',
                    'status_remarks'      => 'Seeded for CRUD coverage',
                    'award_date'          => null,
                    'client_award_ref_no' => null,
                    'created_by_id'       => $staffId,
                    'created_by_name'     => $staffName,
                    'created_by_code'     => $staffCode,
                    'client_id'           => $clientId,
                    'client_name'         => (string) $client->company_name,
                    'client_ssm'          => (string) ($client->ssm_number ?? ''),
                    'client_address'      => (string) ($client->address ?? ''),
                    'client_city'         => (string) ($client->city ?? ''),
                    'client_state'        => (string) ($client->state ?? ''),
                    'client_zip'          => (string) ($client->zip ?? ''),
                    'pic_name'            => (string) ($pic->full_name ?? 'Batch2 PIC'),
                    'pic_email'           => (string) ($pic->email ?? 'seed.batch2.pic@client.local'),
                    'pic_phone'           => (string) ($pic->mobile_number ?? '60112223344'),
                    'pic_position'        => (string) ($pic->position ?? 'Manager'),
                    'sp_id'               => $specialTemplateId,
                    'service_title'       => 'CRUD Coverage Special Service',
                    'service_code'        => 'SP-CRUD-SEED',
                    'general_remarks'     => 'Seeded special quote',
                    'inquiry_remarks'     => 'Seeded special quote',
                    'unit_cost'           => 800,
                    'discount'            => 0,
                    'sst_percent'         => 0,
                    'sst_amount'          => 0,
                    'sub_total'           => 800,
                    'grand_total'         => 800,
                    'attach_proposal'     => 1,
                ]
            );

            $this->updateOrInsert(
                'quotes_special_items',
                ['quote_id' => $specialQuoteId, 'line_item_title' => 'CRUD coverage special line'],
                [
                    'quote_id'         => $specialQuoteId,
                    'service_id'       => $specialTemplateId,
                    'line_item_title'  => 'CRUD coverage special line',
                    'description'      => 'Seeded special line item',
                    'unit'             => 'job',
                    'unit_price'       => 800,
                    'quantity'         => 1,
                    'line_total'       => 800,
                    'created_by'       => $staffId,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ]
            );

            $trainingQuoteId = $this->firstOrInsert(
                'quotes_training',
                ['quote_ref_no' => 'TR-CRUD-SEED-0001'],
                [
                    'service_group'         => 'training',
                    'quote_running_no'      => 900005,
                    'quote_ref_no'          => 'TR-CRUD-SEED-0001',
                    'revision_no'           => 0,
                    'created_at'            => $now,
                    'updated_at'            => $now,
                    'attach_proposal'       => 1,
                    'proposal_id'           => $trainingTemplateId,
                    'client_id'             => $clientId,
                    'training_id'           => $trainingTemplateId,
                    'client_name'           => (string) $client->company_name,
                    'client_ssm'            => (string) ($client->ssm_number ?? ''),
                    'client_address'        => (string) ($client->address ?? ''),
                    'client_city'           => (string) ($client->city ?? ''),
                    'client_state'          => (string) ($client->state ?? ''),
                    'client_zip'            => (string) ($client->zip ?? ''),
                    'pic_name'              => (string) ($pic->full_name ?? 'Batch2 PIC'),
                    'pic_email'             => (string) ($pic->email ?? 'seed.batch2.pic@client.local'),
                    'pic_phone'             => (string) ($pic->mobile_number ?? '60112223344'),
                    'pic_position'          => (string) ($pic->position ?? 'Manager'),
                    'training_title'        => 'CRUD Coverage Training',
                    'training_type'         => 'In-House',
                    'payment_method'        => 'Bank Transfer',
                    'proposed_date'         => $now->copy()->addWeeks(2)->toDateString(),
                    'to_be_confirmed'       => 0,
                    'venue'                 => 'Kajang HQ',
                    'remarks'               => 'Seeded training quote',
                    'target_groups'         => 'Operations team',
                    'pax'                   => 10,
                    'session_count'         => 1,
                    'duration_per_session'  => 2,
                    'duration_unit'         => 'days',
                    'unit_price'            => 3000,
                    'travel_charge'         => 0,
                    'meals_provided'        => 0,
                    'meal_price'            => 0,
                    'discount_type'         => 'flat',
                    'discount_value'        => 0,
                    'sst_rate'              => 0,
                    'hrd_charge'            => 0,
                    'training_total'        => 3000,
                    'meal_total'            => 0,
                    'mobilization_cost'     => 0,
                    'discount_amount'       => 0,
                    'subtotal'              => 3000,
                    'sst_amount'            => 0,
                    'hrd_amount'            => 0,
                    'grand_total'           => 3000,
                    'status'                => 'Pending',
                    'created_by_id'         => $staffId,
                    'created_by_name'       => $staffName,
                    'created_by_code'       => $staffCode,
                    'award_date'            => null,
                    'client_award_ref_no'   => null,
                    'status_remarks'        => 'Seeded for CRUD coverage',
                ]
            );

            $this->updateOrInsert(
                'quote_inquiry_sources',
                ['quote_ref_no' => 'TR-CRUD-SEED-0001', 'source' => 'Google Search'],
                [
                    'quote_id'      => $trainingQuoteId,
                    'quote_ref_no'  => 'TR-CRUD-SEED-0001',
                    'client_id'     => $clientId,
                    'service_type'  => 'training',
                    'source'        => 'Google Search',
                    'remarks'       => 'Seeded inquiry source',
                    'staff_id'      => $staffId,
                    'created_by'    => $staffName,
                    'created_at'    => $now,
                ]
            );

            $this->updateOrInsert(
                'quote_followups',
                ['quote_id' => $trainingQuoteId, 'quote_type' => 'training', 'remarks' => 'CRUD coverage follow-up'],
                [
                    'quote_id'        => $trainingQuoteId,
                    'quote_type'      => 'training',
                    'remarks'         => 'CRUD coverage follow-up',
                    'follow_up_date'  => $now->copy()->addDays(5)->toDateString(),
                    'created_by'      => $staffId,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]
            );

            $crudProjectId = $this->firstOrInsert(
                'projects_main',
                ['project_name' => 'CRUD Coverage Project'],
                [
                    'client_id'           => $clientId,
                    'quote_id'            => $trainingQuoteId,
                    'project_name'        => 'CRUD Coverage Project',
                    'project_type'        => 'Training',
                    'po_loa_number'       => 'PO-CRUD-SEED-0001',
                    'description'         => 'Project seeded for Laravel CRUD verification.',
                    'status'              => 'Active',
                    'quote_value'         => 3000,
                    'award_date'          => $now->toDateString(),
                    'service_start_date'  => $now->copy()->addDays(7)->toDateString(),
                    'service_end_date'    => $now->copy()->addDays(8)->toDateString(),
                    'created_at'          => $now,
                    'created_by'          => $staffId,
                    'updated_by'          => $staffId,
                    'updated_at'          => $now,
                    'quote_type'          => 'training',
                ]
            );

            $this->updateOrInsert(
                'project_collaborators',
                ['project_id' => $crudProjectId, 'staff_id' => $staffId],
                [
                    'project_id'        => $crudProjectId,
                    'staff_id'          => $staffId,
                    'role_description'  => 'Seeded project owner',
                    'project_role'      => 'Leader',
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ]
            );

            $this->updateOrInsert(
                'project_vendors',
                ['project_id' => $crudProjectId, 'vendor_id' => $vendorId],
                [
                    'project_id'           => $crudProjectId,
                    'vendor_id'            => $vendorId,
                    'award_value'          => 1200,
                    'award_date'           => $now->toDateString(),
                    'awarded_by'           => $staffId,
                    'position'             => 'Trainer',
                    'remarks'              => 'CRUD coverage vendor assignment',
                    'services_description' => 'Training facilitation',
                    'venue_details'        => 'Kajang HQ',
                    'fee_breakdown'        => 'RM1200 fixed',
                    'payment_terms'        => '30 days',
                    'loa_running_no'       => 999,
                    'loa_ref_no'           => 'LOA-CRUD-SEED-0001',
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ]
            );

            $this->updateOrInsert(
                'project_expenses',
                ['project_id' => $crudProjectId, 'remarks' => 'CRUD coverage travel expense'],
                [
                    'project_id'  => $crudProjectId,
                    'date'        => $now->toDateString(),
                    'amount'      => 150,
                    'remarks'     => 'CRUD coverage travel expense',
                    'file_path'   => null,
                    'created_by'  => $staffId,
                    'created_at'  => $now,
                ]
            );

            $this->updateOrInsert(
                'project_progress',
                ['project_id' => $crudProjectId, 'progress_text' => 'CRUD coverage progress update'],
                [
                    'project_id'     => $crudProjectId,
                    'progress_date'  => $now->toDateString(),
                    'progress_text'  => 'CRUD coverage progress update',
                    'updated_by'     => $staffId,
                    'updated_on'     => $now,
                ]
            );

            $invoiceId = $this->firstOrInsert(
                'invoices',
                ['invoice_ref_no' => 'INV-CRUD-SEED-0001'],
                [
                    'service_type'          => 'training',
                    'project_id'            => $crudProjectId,
                    'client_id'             => $clientId,
                    'invoice_client_name'   => (string) $client->company_name,
                    'invoice_client_ssm'    => (string) ($client->ssm_number ?? ''),
                    'invoice_client_tin'    => (string) ($client->tax_id_no_tin ?? ''),
                    'invoice_client_address'=> (string) ($client->address ?? ''),
                    'invoice_client_city'   => (string) ($client->city ?? ''),
                    'invoice_client_state'  => (string) ($client->state ?? ''),
                    'invoice_client_zip'    => (string) ($client->zip ?? ''),
                    'invoice_pic_name'      => (string) ($pic->full_name ?? 'Batch2 PIC'),
                    'invoice_pic_phone'     => (string) ($pic->mobile_number ?? '60112223344'),
                    'invoice_pic_email'     => (string) ($pic->email ?? 'seed.batch2.pic@client.local'),
                    'invoice_pic_position'  => (string) ($pic->position ?? 'Manager'),
                    'quote_id'              => $trainingQuoteId,
                    'created_by'            => $staffId,
                    'invoice_ref_no'        => 'INV-CRUD-SEED-0001',
                    'invoice_running_no'    => 990001,
                    'invoice_loa_no'        => 'PO-CRUD-SEED-0001',
                    'invoice_purpose'       => 'Training service',
                    'invoice_date'          => $now->toDateString(),
                    'amount'                => 3000,
                    'payment_method'        => 'Bank Transfer',
                    'grant_approval_no'     => 'GA-CRUD-SEED-0001',
                    'hrd_claim_ref'         => null,
                    'remarks'               => 'Seeded invoice',
                    'status'                => 'Unpaid',
                    'paid_date'             => null,
                    'paid_amount'           => null,
                    'paid_remarks'          => null,
                    'created_at'            => $now,
                    'updated_at'            => $now,
                    'sst_amount'            => 0,
                    'hrd_amount'            => 0,
                    'grand_total'           => 3000,
                    'receipt_no'            => null,
                ]
            );

            $this->updateOrInsert(
                'invoice_breakdown',
                ['invoice_id' => $invoiceId, 'item_description' => 'CRUD coverage training service'],
                [
                    'invoice_id'       => $invoiceId,
                    'item_description' => 'CRUD coverage training service',
                    'unit'             => 'session',
                    'quantity'         => 1,
                    'unit_price'       => 3000,
                    'sort_order'       => 1,
                    'subtotal'         => 3000,
                    'description'      => 'Seeded invoice breakdown row',
                ]
            );

            $this->firstOrInsert(
                'invoices_jd14form',
                ['approval_no' => 'JD14-CRUD-SEED-0001'],
                [
                    'project_id'          => $crudProjectId,
                    'created_by'          => $staffId,
                    'employer_name'       => (string) $client->company_name,
                    'employer_address'    => trim(implode(', ', array_filter([
                        $client->address ?? null,
                        $client->city ?? null,
                        $client->state ?? null,
                        $client->zip ?? null,
                    ]))),
                    'approval_no'         => 'JD14-CRUD-SEED-0001',
                    'employer_code'       => 'EMP-CRUD',
                    'group_approved'      => 10,
                    'group_claimed'       => 10,
                    'course_title'        => 'CRUD Coverage Training',
                    'training_venue'      => 'Kajang HQ',
                    'commenced_date'      => $now->copy()->addDays(7)->toDateString(),
                    'end_date'            => $now->copy()->addDays(8)->toDateString(),
                    'no_of_pax'           => 10,
                    'total_fee_approved'  => 3000,
                    'total_fee_claimed'   => 3000,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]
            );

            $this->firstOrInsert(
                'hr_appraisal',
                ['staff_id' => $staffId, 'section' => 'Performance', 'feedback' => 'CRUD coverage appraisal'],
                [
                    'staff_id'    => $staffId,
                    'section'     => 'Performance',
                    'event_date'  => $now->toDateString(),
                    'feedback'    => 'CRUD coverage appraisal',
                    'created_by'  => $staffId,
                    'created_at'  => $now,
                ]
            );

            $kpiId = $this->firstOrInsert(
                'hr_kpi_parameters',
                ['staff_id' => $staffId, 'parameter_name' => 'CRUD coverage KPI', 'year' => (int) $now->format('Y')],
                [
                    'staff_id'       => $staffId,
                    'parameter_name' => 'CRUD coverage KPI',
                    'description'    => 'Seeded KPI parameter',
                    'annual_target'  => 12,
                    'unit'           => 'tasks',
                    'weightage'      => 10,
                    'year'           => (int) $now->format('Y'),
                    'created_by'     => $staffId,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]
            );

            $this->updateOrInsert(
                'hr_kpi_parameters_tracker',
                ['kpi_id' => $kpiId, 'staff_id' => $staffId, 'for_month' => $now->copy()->startOfMonth()->toDateString()],
                [
                    'kpi_id'        => $kpiId,
                    'staff_id'      => $staffId,
                    'for_month'     => $now->copy()->startOfMonth()->toDateString(),
                    'actual_value'  => 1,
                    'remarks'       => 'Seeded KPI tracker row',
                    'created_by'    => $staffId,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]
            );

            $this->firstOrInsert(
                'hr_leaves_allocation',
                ['staff_id' => $staffId, 'leave_type' => 'Annual Leave', 'year' => (int) $now->format('Y')],
                [
                    'staff_id'         => $staffId,
                    'leave_type'       => 'Annual Leave',
                    'year'             => (int) $now->format('Y'),
                    'total_days'       => 14,
                    'used_days'        => 0,
                    'carried_forward'  => 0,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ]
            );

            $this->firstOrInsert(
                'hr_leaves_application',
                ['staff_id' => $staffId, 'reason' => 'CRUD coverage leave application'],
                [
                    'staff_id'          => $staffId,
                    'type'              => 'Annual Leave',
                    'reason'            => 'CRUD coverage leave application',
                    'start_date'        => $now->copy()->addMonth()->toDateString(),
                    'start_time'        => '09:00:00',
                    'end_date'          => $now->copy()->addMonth()->toDateString(),
                    'end_time'          => '18:00:00',
                    'duration_days'     => 1,
                    'status'            => 'Pending',
                    'applied_at'        => $now,
                    'reviewed_by'       => null,
                    'reviewed_at'       => null,
                    'reviewed_status'   => null,
                    'reviewed_remarks'  => null,
                    'approved_by'       => null,
                    'approved_at'       => null,
                    'approved_status'   => null,
                    'approved_remarks'  => null,
                    'cancelled_by'      => null,
                    'cancelled_at'      => null,
                    'cancel_reason'     => null,
                ]
            );

            $contactId = $this->firstOrInsert(
                'google_contacts',
                ['phone_normalized' => '60112223355'],
                [
                    'place_id'          => null,
                    'name'              => 'CRUD Coverage Contact',
                    'phone'             => '+60 11-2223 355',
                    'phone_normalized'  => '60112223355',
                    'address'           => 'Kajang, Selangor',
                    'note'              => 'Seeded Google contact',
                    'website'           => 'https://example.com',
                    'created_by'        => $staffId,
                    'created_by_code'   => $staffCode,
                    'source'            => 'user',
                    'created_at'        => $now,
                ]
            );

            $this->updateOrInsert(
                'google_call_records',
                ['contact_id' => $contactId, 'note' => 'CRUD coverage call note'],
                [
                    'contact_id'      => $contactId,
                    'called_at'       => $now,
                    'outcome'         => 'Connected',
                    'note'            => 'CRUD coverage call note',
                    'next_action_at'  => $now->copy()->addDays(7),
                    'called_by'       => $staffId,
                    'called_by_code'  => $staffCode,
                    'duration_sec'    => 180,
                    'created_at'      => $now,
                ]
            );

            $this->updateOrInsert(
                'hr_handbook_sign',
                ['staff_id' => $staffId, 'full_name' => $staffName],
                [
                    'staff_id'    => $staffId,
                    'full_name'   => $staffName,
                    'ic_number'   => '900101-10-1234',
                    'signed_at'   => $now,
                    'ip_address'  => '127.0.0.1',
                    'user_agent'  => 'CrudCoverageSeeder',
                ]
            );

            DB::commit();

            if ($this->command) {
                $this->command->info(sprintf(
                    'CrudCoverageSeeder completed. feedback=%d tool_request=%d procedure=%d project=%d invoice=%d',
                    $feedbackId,
                    $toolRequestId,
                    $procedureId,
                    $crudProjectId,
                    $invoiceId
                ));
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function firstOrInsert(string $table, array $criteria, array $payload, string $idColumn = 'id'): int
    {
        if (! $this->hasTable($table)) {
            return 0;
        }

        $query = DB::table($table);
        foreach ($criteria as $column => $value) {
            $query->where($column, $value);
        }

        $existing = $query->first([$idColumn]);
        if ($existing && isset($existing->{$idColumn})) {
            return (int) $existing->{$idColumn};
        }

        return (int) DB::table($table)->insertGetId($this->filterColumns($table, $payload));
    }

    private function updateOrInsert(string $table, array $criteria, array $payload): void
    {
        if (! $this->hasTable($table)) {
            return;
        }

        DB::table($table)->updateOrInsert(
            $this->filterColumns($table, $criteria),
            $this->filterColumns($table, $payload)
        );
    }

    private function hasTable(string $table): bool
    {
        return Schema::hasTable($table);
    }

    private function filterColumns(string $table, array $payload): array
    {
        if (! isset($this->columns[$table])) {
            $this->columns[$table] = $this->hasTable($table)
                ? array_flip(Schema::getColumnListing($table))
                : [];
        }

        return array_filter(
            $payload,
            fn ($value, $column) => isset($this->columns[$table][$column]),
            ARRAY_FILTER_USE_BOTH
        );
    }

    private function servicePeriod($startDate, $endDate): string
    {
        $start = $startDate ? (string) $startDate : '';
        $end   = $endDate ? (string) $endDate : '';

        return trim($start . ($start && $end ? ' to ' : '') . $end);
    }
}
