<?php

namespace Tests\Support;

final class CommercialCyclePayloads
{
    public static function quote(string $service, array $overrides = []): array
    {
        $base = match ($service) {
            'training' => self::trainingQuote(),
            'equipment' => self::equipmentQuote(),
            'manpower' => self::manpowerQuote(),
            'special' => self::specialQuote(),
        };

        return array_replace_recursive($base, $overrides);
    }

    public static function award(string $service): array
    {
        return [
            'remarks' => "Client awarded the {$service} work.",
            'award_date' => '2026-07-27',
            'description' => ucfirst($service).' full-cycle test scope.',
            'client_award_ref_no' => 'CLIENT-LOA-'.strtoupper(substr($service, 0, 3)),
        ];
    }

    public static function invoice(int $projectId, int $quoteId, string $projectType, float $total): array
    {
        $isTraining = $projectType === 'Training';

        return [
            'project_id' => $projectId,
            'quote_id' => $quoteId,
            'service_type' => $projectType,
            'invoice_purpose' => $projectType.' full-cycle invoice',
            'invoice_client_name' => 'Client A',
            'invoice_client_ssm' => 'SSM-1',
            'invoice_client_tin' => 'TIN-1',
            'invoice_client_address' => '1 Test Road',
            'invoice_client_city' => 'Test City',
            'invoice_client_state' => 'Test State',
            'invoice_client_zip' => '12345',
            'invoice_pic_name' => 'Client PIC',
            'invoice_pic_phone' => '60123456789',
            'invoice_pic_email' => 'pic@example.test',
            'invoice_pic_position' => 'Manager',
            'invoice_date' => '2026-07-27',
            'payment_method' => $isTraining ? 'HRD Grant' : 'Bank Transfer',
            'grant_approval_no' => $isTraining ? 'HRD-CYCLE-001' : null,
            'amount' => $total,
            'sst_amount' => 0,
            'grand_total' => $isTraining ? $total * 1.1 : $total,
            'breakdown' => array_values(array_filter([
                [
                    'item_description' => $projectType.' services',
                    'description' => 'Awarded quotation scope.',
                    'unit' => 'Lot',
                    'quantity' => 1,
                    'unit_price' => $total,
                    'subtotal' => $total,
                ],
                $isTraining ? [
                    'item_description' => '10% HRD Charge',
                    'description' => 'HRD processing charge.',
                    'unit' => 'Lot',
                    'quantity' => 1,
                    'unit_price' => $total * 0.1,
                    'subtotal' => $total * 0.1,
                ] : null,
            ])),
        ];
    }

    public static function deliveryOrder(int $projectId, string $projectType): array
    {
        return [
            'details' => [
                'client_name' => 'Client A',
                'client_address' => '1 Test Road',
                'client_contact_name' => 'Client PIC',
                'client_contact_position' => 'Manager',
                'client_contact_email' => 'pic@example.test',
                'client_contact_phone' => '60123456789',
                'company_contact_name' => 'System Admin',
                'company_contact_email' => 'sysadmin@example.test',
                'company_contact_phone' => '601100000000',
                'project_id' => $projectId,
                'project_name' => $projectType.' Project',
                'project_code' => strtoupper(substr($projectType, 0, 3))."-{$projectId}",
                'project_award_date' => '2026-07-27',
                'project_type' => $projectType,
                'project_description' => $projectType.' full-cycle test scope.',
                'project_service_period' => 'July 2026',
            ],
            'breakdown' => [[
                'item_name' => $projectType.' deliverable',
                'description' => 'Final contracted deliverable.',
                'quantity' => 1,
                'unit' => 'Lot',
            ]],
        ];
    }

    public static function vendorLoa(): array
    {
        return [
            'vendor_id' => 7,
            'award_value' => 350,
            'award_date' => '2026-07-27',
            'position' => 'Service provider',
            'remarks' => 'Provide contracted support.',
            'services_description' => 'Subcontracted project services.',
            'venue_details' => 'Client site.',
            'fee_breakdown' => 'Service fee: RM350.',
            'payment_terms' => '30 days',
        ];
    }

    public static function supplierPo(int $projectId): array
    {
        return [
            'project_id' => $projectId,
            'supplier' => [
                'id' => 7,
                'company_name' => 'Laboratory Supplier',
                'full_address' => '7 Lab Road',
                'contact_name' => 'Vendor PIC',
                'contact_number' => '60112223333',
            ],
            'items' => [[
                'item_id' => 701,
                'item_name' => 'Project consumable',
                'description' => 'Consumable for awarded work.',
                'unit' => 'box',
                'quantity' => 2,
                'unit_price' => 50,
                'line_total' => 100,
            ]],
            'discount' => 0,
            'delivery_charge' => 0,
            'sst_percent' => 0,
            'sst_amount' => 0,
            'grand_total' => 100,
        ];
    }

    public static function jd14(int $projectId): array
    {
        return [
            'project_id' => $projectId,
            'employer_name' => 'Client A',
            'employer_address' => '1 Test Road',
            'approval_no' => 'HRD-CYCLE-001',
            'course_title' => 'Safety Training',
            'training_venue' => 'Client Site',
            'commenced_date' => '2026-07-27',
            'end_date' => '2026-07-28',
        ];
    }

    private static function trainingQuote(): array
    {
        return [
            'client_id' => 1,
            'client_snapshot' => [
                'company_name' => 'Client A', 'ssm_number' => 'SSM-1', 'address' => '1 Test Road',
                'city' => 'Test City', 'state' => 'Test State', 'zip' => '12345',
            ],
            'pic_snapshot' => [
                'full_name' => 'Client PIC', 'email' => 'pic@example.test',
                'mobile_number' => '60123456789', 'position' => 'Manager',
            ],
            'training_id' => 301,
            'training_title' => 'Safety Training',
            'training_type' => 'In-house',
            'training_rate_type' => 'client_site_special_trainer',
            'payment_method' => 'HRD Grant',
            'proposed_date' => '2026-08-03',
            'proposed_end_date' => '2026-08-04',
            'venue' => 'Client Site',
            'pax' => 10,
            'session_count' => 1,
            'duration_per_session' => 8,
            'duration_unit' => 'hours',
            'pricing_basis' => 'per_session',
            'unit_price' => 5000,
            'travel_charge' => 0,
            'meals_provided' => false,
            'discount_value' => 0,
            'sst_rate' => 0,
            'hrd_charge' => 10,
            'estimated_total_cost' => 3000,
            'attach_proposal' => false,
            'proposal_language' => 'en',
        ];
    }

    private static function equipmentQuote(): array
    {
        return self::flatClient() + [
            'items' => [[
                'catalog_item_id' => 701,
                'item_name' => 'Gas detector',
                'unit_price' => 700,
                'marked_up_price' => 1000,
                'quantity' => 1,
                'total_price' => 1000,
            ]],
            'delivery_charge' => 0,
            'misc_charge' => 0,
            'discount' => 0,
            'sst_percent' => 0,
            'estimated_total_cost' => 700,
        ];
    }

    private static function manpowerQuote(): array
    {
        return self::flatClient() + [
            'mp_id' => 401,
            'service_title' => 'Safety Supervisor',
            'service_code' => 'SS',
            'manpower_rate_type' => 'other_manpower',
            'billing_unit' => 'month',
            'duration_months' => 1,
            'no_of_pax' => 1,
            'unit_cost' => 4000,
            'discount' => 0,
            'sst_percent' => 0,
            'estimated_total_cost' => 2500,
            'nature_of_work' => 'Site safety supervision.',
            'site_location' => 'Client Site',
        ];
    }

    private static function specialQuote(): array
    {
        return self::flatClient() + [
            'sp_id' => 501,
            'service_title' => 'Special Compliance Review',
            'service_code' => 'SCR',
            'general_remarks' => 'Custom compliance scope.',
            'discount' => 0,
            'sst_percent' => 0,
            'attach_proposal' => false,
            'proposal_language' => 'en',
            'line_items' => [[
                'item_name' => 'Compliance review',
                'description' => 'Custom site compliance review.',
                'unit' => 'Lot',
                'unit_price' => 2000,
                'quantity' => 1,
                'total_price' => 2000,
            ]],
        ];
    }

    private static function flatClient(): array
    {
        return [
            'client_id' => 1,
            'client_name' => 'Client A',
            'client_ssm' => 'SSM-1',
            'client_address' => '1 Test Road',
            'client_city' => 'Test City',
            'client_state' => 'Test State',
            'client_zip' => '12345',
            'pic_name' => 'Client PIC',
            'pic_email' => 'pic@example.test',
            'pic_phone' => '60123456789',
            'pic_position' => 'Manager',
        ];
    }
}
