<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuthApiTest extends TestCase
{
    public function test_session_requires_authenticated_session(): void
    {
        $this->getJson('/auth/session')
            ->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'message' => 'Unauthorized. Please log in to continue.',
            ]);
    }

    public function test_logout_requires_authenticated_session(): void
    {
        $this->postJson('/auth/logout')
            ->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'message' => 'Unauthorized. Please log in to continue.',
            ]);
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->postJson('/auth/login')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }
}
