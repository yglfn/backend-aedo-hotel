<?php

namespace Tests\Feature;

use App\Models\User;
use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed database for necessary settings
        $this->artisan('db:seed');
    }

    public function test_user_can_login_with_correct_credentials(): void
    {
        $response = $this->withHeaders(['Referer' => 'http://localhost'])
                         ->postJson('/api/v1/auth/login', [
            'email' => 'admin@aedohotel.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['message', 'user']);
    }

    public function test_user_cannot_login_with_invalid_credentials(): void
    {
        $response = $this->withHeaders(['Referer' => 'http://localhost'])
                         ->postJson('/api/v1/auth/login', [
            'email' => 'admin@aedohotel.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422);
    }
}
