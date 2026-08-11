<?php

namespace Tests\Unit;

use App\Services\Clients\FirstTouch\ClientFirstTouchAuthorizationService;
use App\Services\Clients\FirstTouch\ClientFirstTouchRecipientService;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Mockery;
use Tests\TestCase;

class ClientFirstTouchAuthorizationServiceTest extends TestCase
{
    public function test_original_submitter_and_privileged_staff_can_edit_but_unrelated_staff_cannot(): void
    {
        $recipients = Mockery::mock(ClientFirstTouchRecipientService::class);
        $service = new ClientFirstTouchAuthorizationService($recipients);

        $this->assertTrue($service->canEditClaim($this->request(20, ['Staff']), 20));
        $this->assertFalse($service->canEditClaim($this->request(30, ['Staff']), 20));
        $this->assertTrue($service->canEditClaim($this->request(30, ['Manager']), 20));
        $this->assertTrue($service->canEditClaim($this->request(30, ['System Admin']), 20));
    }

    public function test_record_permissions_use_independent_reviewer_assignment(): void
    {
        $recipients = Mockery::mock(ClientFirstTouchRecipientService::class);
        $recipients->shouldReceive('canReview')->once()->withArgs(
            fn (Request $request, int $conflictId): bool => $conflictId === 91,
        )->andReturnTrue();
        $service = new ClientFirstTouchAuthorizationService($recipients);

        $permissions = $service->permissions($this->request(10, ['Manager']), 20, 91, 'open');

        $this->assertTrue($permissions['canReviewConflict']);
        $this->assertFalse($permissions['canEditEvidence']);
        $this->assertFalse($permissions['canRespondToClarification']);
    }

    public function test_only_assignee_or_system_admin_can_respond_to_clarification(): void
    {
        $recipients = Mockery::mock(ClientFirstTouchRecipientService::class);
        $recipients->shouldReceive('canReview')->andReturnFalse();
        $service = new ClientFirstTouchAuthorizationService($recipients);

        $assignee = $service->permissions($this->request(20, ['Staff']), 30, 91, 'clarification_requested', 20);
        $other = $service->permissions($this->request(30, ['Staff']), 20, 91, 'clarification_requested', 20);
        $admin = $service->permissions($this->request(30, ['System Admin']), 20, 91, 'clarification_requested', 20);

        $this->assertTrue($assignee['canRespondToClarification']);
        $this->assertFalse($other['canRespondToClarification']);
        $this->assertTrue($admin['canRespondToClarification']);
    }

    private function request(int $staffId, array $roles): Request
    {
        $request = Request::create('/client-first-touches/399', 'GET');
        $session = new Store('first-touch-test-session', app('session')->driver()->getHandler());
        $session->start();
        $session->put('staff_id', $staffId);
        $session->put('roles', $roles);
        $request->setLaravelSession($session);

        return $request;
    }
}
