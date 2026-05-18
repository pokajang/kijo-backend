<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class BatchTwoSmokeSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        DB::beginTransaction();

        try {
            $staffId = $this->seedStaff($now);
            $clientId = $this->seedClient($staffId, $now);
            $vendorId = $this->seedVendor($staffId, $now);
            $catalogItemId = $this->seedCatalogItem($staffId, $now);
            $projectId = $this->seedProject($clientId, $staffId, $now);

            $this->seedProjectVendor($projectId, $vendorId, $staffId, $now);
            $this->seedVendorPayment($projectId, $vendorId, $staffId, $now);
            $poId = $this->seedSupplierPoMain($projectId, $vendorId, $staffId, $now);
            $this->seedSupplierPoItem($poId, $catalogItemId, $now);
            $this->seedUserActivity($staffId, $now);

            DB::commit();

            if ($this->command) {
                $this->command->info('BatchTwoSmokeSeeder completed.');
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function seedStaff($now): int
    {
        $email = 'seed.batch2.staff@kijo.local';
        $nameCode = 'B2SEED';

        $staff = DB::table('staff_general')->where('email', $email)->first();

        if (! $staff) {
            $staffId = DB::table('staff_general')->insertGetId([
                'full_name' => 'Batch2 Seed Staff',
                'name_code' => $nameCode,
                'email' => $email,
                'mobile_number' => '60123456789',
                'position' => 'Engineer',
                'crm_position' => 'Engineer',
                'staff_type' => 'Permanent',
                'department' => 'Operations',
                'start_date' => $now->toDateString(),
                'status' => 'Active',
                'grant_access' => 1,
                'role' => 'Admin',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $staffId = (int) $staff->staff_id;
        }

        DB::table('system_users')->updateOrInsert(
            ['email' => $email],
            [
                'password_hash' => Hash::make('Password@123'),
                'role' => 'Admin',
                'staff_id' => $staffId,
                'is_active' => 1,
                'failed_attempts' => 0,
                'lockout_count' => 0,
                'total_lock' => 0,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        return $staffId;
    }

    private function seedClient(int $staffId, $now): int
    {
        $companyName = 'Batch2 Seed Client Sdn Bhd';
        $company = DB::table('client_company')->where('company_name', $companyName)->first();

        if (! $company) {
            $companyId = DB::table('client_company')->insertGetId([
                'company_name' => $companyName,
                'ssm_number' => 'B2-SSM-0001',
                'tax_id_no_tin' => 'TIN-B2-0001',
                'client_status' => 'Active',
                'address' => 'No. 1 Seed Street',
                'city' => 'Kajang',
                'state' => 'Selangor',
                'zip' => '43000',
                'created_at' => $now,
                'status' => 'active',
            ]);
        } else {
            $companyId = (int) $company->company_id;
        }

        DB::table('client_company_branch')->updateOrInsert(
            ['company_id' => $companyId, 'branch_name' => 'HQ'],
            [
                'address' => 'No. 1 Seed Street',
                'city' => 'Kajang',
                'state' => 'Selangor',
                'zip' => '43000',
                'country' => 'Malaysia',
                'status' => 'active',
                'created_at' => $now,
                'deleted_at' => null,
                'deleted_by' => null,
            ]
        );

        DB::table('client_pic')->updateOrInsert(
            ['email' => 'seed.batch2.pic@client.local'],
            [
                'full_name' => 'Batch2 PIC',
                'mobile_number' => '60112223344',
                'position' => 'Manager',
                'company_id' => $companyId,
                'status' => 'assigned',
                'created_at' => $now,
                'deleted_at' => null,
            ]
        );

        return $companyId;
    }

    private function seedVendor(int $staffId, $now): int
    {
        $vendorName = 'Batch2 Seed Vendor Sdn Bhd';
        $vendor = DB::table('vendor_main_details')->where('vendor_name', $vendorName)->first();

        if (! $vendor) {
            $vendorId = DB::table('vendor_main_details')->insertGetId([
                'vendor_name' => $vendorName,
                'ssm_number' => 'B2-V-SSM-0001',
                'sst_number' => 'B2-V-SST-0001',
                'address' => 'No. 2 Vendor Avenue',
                'city' => 'Kajang',
                'state' => 'Selangor',
                'zip' => '43000',
                'contact_person_name' => 'Seed Vendor PIC',
                'mobile_number' => '60115556677',
                'email' => 'seed.batch2.vendor@kijo.local',
                'website' => 'https://example.com',
                'emergency_name' => 'Emergency Contact',
                'emergency_relation' => 'Manager',
                'emergency_mobile' => '60118889900',
                'bank_name' => 'Maybank',
                'bank_account' => '1234567890',
                'bank_holder_name' => 'Batch2 Seed Vendor Sdn Bhd',
                'status' => 'Active',
                'created_by' => $staffId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $vendorId = (int) $vendor->vendor_id;
        }

        DB::table('vendor_categories')->updateOrInsert(
            ['vendor_id' => $vendorId, 'category' => 'Training'],
            ['vendor_id' => $vendorId, 'category' => 'Training']
        );

        DB::table('vendor_supplies_details')->updateOrInsert(
            ['vendor_id' => $vendorId, 'product_name' => 'N95 Mask'],
            ['vendor_id' => $vendorId, 'product_name' => 'N95 Mask']
        );

        DB::table('vendor_training_details')->updateOrInsert(
            ['vendor_id' => $vendorId, 'topic' => 'Workplace Safety'],
            ['vendor_id' => $vendorId, 'topic' => 'Workplace Safety']
        );

        DB::table('vendor_consultancy_details')->updateOrInsert(
            ['vendor_id' => $vendorId, 'consulting_area' => 'HSE Advisory'],
            ['vendor_id' => $vendorId, 'consulting_area' => 'HSE Advisory']
        );

        DB::table('vendor_competency_details')->updateOrInsert(
            ['vendor_id' => $vendorId, 'competency' => 'ISO 45001'],
            ['vendor_id' => $vendorId, 'competency' => 'ISO 45001']
        );

        DB::table('vendor_other_services_details')->updateOrInsert(
            ['vendor_id' => $vendorId, 'service_name' => 'Equipment Calibration'],
            ['vendor_id' => $vendorId, 'service_name' => 'Equipment Calibration']
        );

        return $vendorId;
    }

    private function seedCatalogItem(int $staffId, $now): int
    {
        $itemName = 'Batch2 Seed N95 Mask';
        $existing = DB::table('catalog_items')->where('item_name', $itemName)->first();

        if ($existing) {
            return (int) $existing->id;
        }

        return (int) DB::table('catalog_items')->insertGetId([
            'item_name' => $itemName,
            'category_id' => 'PPE',
            'description' => 'Seeded item for Batch2 smoke tests',
            'unit' => 'box',
            'supplier_name' => 'Batch2 Seed Vendor Sdn Bhd',
            'supplier_price' => 100.00,
            'price_date' => $now->toDateString(),
            'remarks' => 'Seeded by BatchTwoSmokeSeeder',
            'created_by_id' => $staffId,
            'created_by_code' => 'B2SEED',
            'created_at' => $now,
            'updated_at' => $now,
            'updated_by_id' => $staffId,
            'updated_by_code' => 'B2SEED',
        ]);
    }

    private function seedProject(int $clientId, int $staffId, $now): int
    {
        $projectName = 'Batch2 Seed Project';
        $existing = DB::table('projects_main')->where('project_name', $projectName)->first();

        if ($existing) {
            return (int) $existing->id;
        }

        return (int) DB::table('projects_main')->insertGetId([
            'client_id' => $clientId,
            'project_name' => $projectName,
            'project_type' => 'Training',
            'po_loa_number' => 'PO-LOA-B2-SEED',
            'description' => 'Seeded project for Batch2 smoke tests',
            'status' => 'Active',
            'quote_value' => 5000.00,
            'award_date' => $now->toDateString(),
            'service_start_date' => $now->toDateString(),
            'service_end_date' => $now->copy()->addMonth()->toDateString(),
            'created_at' => $now,
            'created_by' => $staffId,
            'updated_by' => $staffId,
            'updated_at' => $now,
            'quote_type' => 'training',
        ]);
    }

    private function seedProjectVendor(int $projectId, int $vendorId, int $staffId, $now): void
    {
        DB::table('project_vendors')->updateOrInsert(
            ['project_id' => $projectId, 'vendor_id' => $vendorId],
            [
                'award_value' => 2500.00,
                'award_date' => $now->toDateString(),
                'awarded_by' => $staffId,
                'position' => 'Vendor',
                'remarks' => 'Seeded for LOA tests',
                'services_description' => 'Safety support services',
                'venue_details' => 'Kajang',
                'fee_breakdown' => 'RM2500 flat',
                'payment_terms' => '30 days',
                'loa_running_no' => 0,
                'loa_ref_no' => 'LOA-B2-SEED-0001',
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    private function seedVendorPayment(int $projectId, int $vendorId, int $staffId, $now): void
    {
        DB::table('vendor_payments')->updateOrInsert(
            [
                'vendor_id' => $vendorId,
                'project_id' => $projectId,
                'payment_context' => 'Project',
                'payment_type' => 'Advance',
            ],
            [
                'amount' => 500.00,
                'method' => 'Bank Transfer',
                'remarks' => 'Seeded payment request',
                'status' => 'Pending',
                'created_by' => $staffId,
                'created_by_full_name' => 'Batch2 Seed Staff',
                'created_by_name_code' => 'B2SEED',
                'created_at' => $now,
                'deleted_at' => null,
                'deleted_by' => null,
            ]
        );
    }

    private function seedSupplierPoMain(int $projectId, int $vendorId, int $staffId, $now): int
    {
        $poRefNo = 'PO-B2-SEED-0001';
        $existing = DB::table('supplier_po_main')->where('po_ref_no', $poRefNo)->first();

        if ($existing) {
            return (int) $existing->po_id;
        }

        return (int) DB::table('supplier_po_main')->insertGetId([
            'supplier_id' => $vendorId,
            'supplier_name' => 'Batch2 Seed Vendor Sdn Bhd',
            'supplier_address' => 'No. 2 Vendor Avenue, Kajang, Selangor 43000',
            'supplier_contact_name' => 'Seed Vendor PIC',
            'supplier_contact_number' => '60115556677',
            'project_id' => $projectId,
            'po_running_no' => 0,
            'po_ref_no' => $poRefNo,
            'discount' => 10.00,
            'delivery_charge' => 20.00,
            'sst_percent' => 8.00,
            'sst_amount' => 8.80,
            'grand_total' => 118.80,
            'created_by' => $staffId,
            'created_at' => $now,
            'status' => 'Pending',
            'updated_by' => $staffId,
            'updated_at' => $now,
            'status_remarks' => 'Seeded by BatchTwoSmokeSeeder',
        ]);
    }

    private function seedSupplierPoItem(int $poId, int $catalogItemId, $now): void
    {
        DB::table('supplier_po_items')->updateOrInsert(
            ['po_id' => $poId, 'item_id' => $catalogItemId],
            [
                'item_name' => 'Batch2 Seed N95 Mask',
                'description' => 'Seeded PO item',
                'unit' => 'box',
                'quantity' => 1,
                'unit_price' => 100.00,
                'line_total' => 100.00,
                'created_at' => $now,
            ]
        );
    }

    private function seedUserActivity(int $staffId, $now): void
    {
        DB::table('user_activities')->updateOrInsert(
            [
                'staff_id' => $staffId,
                'name_code' => 'B2SEED',
                'action' => 'Batch2 seed activity log',
            ],
            [
                'created_at' => $now,
                'ip_address' => '127.0.0.1',
            ]
        );
    }
}
