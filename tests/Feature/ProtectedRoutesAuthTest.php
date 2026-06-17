<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProtectedRoutesAuthTest extends TestCase
{
    #[DataProvider('protectedRouteProvider')]
    public function test_protected_routes_require_authenticated_session(string $method, string $uri): void
    {
        $this->json($method, $uri)
            ->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'message' => 'Unauthorized. Please log in to continue.',
            ]);
    }

    public static function protectedRouteProvider(): array
    {
        return [
            'feedback' => ['GET', '/feedback'],
            'feedback metrics' => ['GET', '/feedback/metrics/monthly'],
            'tool requests' => ['GET', '/tool-requests'],
            'signature' => ['GET', '/signature'],
            'sport events' => ['GET', '/sport-events'],
            'delivery orders' => ['GET', '/delivery-orders'],
            'staff' => ['GET', '/staff/list'],
            'clients' => ['GET', '/client-companies'],
            'client roi' => ['GET', '/client-companies/roi'],
            'client commercial history' => ['GET', '/client-companies/1/commercial-history'],
            'client vendor registrations' => ['GET', '/client-vendor-registrations'],
            'catalog' => ['GET', '/catalog/items'],
            'vendors' => ['GET', '/vendors'],
            'vendor loas' => ['GET', '/vendor-loas'],
            'tasks' => ['GET', '/tasks'],
            'procedures' => ['GET', '/procedures'],
            'meetings' => ['GET', '/meetings'],
            'proposal templates' => ['GET', '/proposal-templates/training'],
            'quotes' => ['GET', '/quotes/training/1'],
            'projects' => ['GET', '/projects'],
            'quote records' => ['GET', '/quote-records/training'],
            'invoices' => ['GET', '/invoices'],
            'stats' => ['GET', '/stats/debtors'],
            'hr appraisals' => ['GET', '/hr/appraisals'],
            'google contacts' => ['GET', '/google/contacts'],
            'admin migration status' => ['GET', '/admin/migration-status'],
        ];
    }
}
