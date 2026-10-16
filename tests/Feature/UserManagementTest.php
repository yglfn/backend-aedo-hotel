<?php

namespace Tests\Feature;

use App\Enums\ShiftStatus;
use App\Enums\UserRole;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $pegawai;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');

        $this->admin   = User::where('role', UserRole::Admin)->first();
        $this->pegawai = User::where('role', UserRole::Pegawai)->first();
    }

    public function test_admin_can_create_pegawai_and_password_is_hashed_once(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/users', [
            'name'     => 'Citra Pegawai',
            'email'    => 'citra@aedohotel.com',
            'password' => 'rahasia123',
            'role'     => 'pegawai',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.is_active', true);

        $created = User::where('email', 'citra@aedohotel.com')->first();

        // Kolom password memakai cast 'hashed'. Bila controller ikut meng-hash,
        // login akun baru pasti gagal — jadi cek hash tunggal.
        $this->assertTrue(Hash::check('rahasia123', $created->password));
        $this->assertNotSame('rahasia123', $created->password);
    }

    public function test_admin_can_create_inactive_account_via_api(): void
    {
        // is_active dulunya diabaikan controller walau terdokumentasi di FRONTEND_API.md
        $this->actingAs($this->admin)->postJson('/api/v1/users', [
            'name'      => 'Belum Aktif',
            'email'     => 'belumaktif@aedohotel.com',
            'password'  => 'rahasia123',
            'role'      => 'pegawai',
            'is_active' => false,
        ])->assertStatus(201)->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('users', [
            'email'     => 'belumaktif@aedohotel.com',
            'is_active' => false,
        ]);
    }

    public function test_inactive_account_cannot_login(): void
    {
        // Dibuat lewat model (bukan endpoint) agar guard default masih 'web' saat uji login
        User::create([
            'name'      => 'Calon Pegawai',
            'email'     => 'calon@aedohotel.com',
            'password'  => 'rahasia123',
            'role'      => UserRole::Pegawai,
            'is_active' => false,
        ]);

        $this->withHeaders(['Referer' => 'http://localhost'])
            ->postJson('/api/v1/auth/login', [
                'email'    => 'calon@aedohotel.com',
                'password' => 'rahasia123',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Akun Anda tidak aktif. Hubungi administrator.');
    }

    public function test_password_minimum_length_and_unique_email_use_indonesian_messages(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/users', [
            'name'     => 'Lemah',
            'email'    => 'lemah@aedohotel.com',
            'password' => '123',
            'role'     => 'pegawai',
        ])->assertStatus(422)->assertJsonPath('errors.password.0', 'Password minimal 8 karakter.');

        $this->actingAs($this->admin)->postJson('/api/v1/users', [
            'name'     => 'Duplikat',
            'email'    => $this->pegawai->email,
            'password' => 'rahasia123',
            'role'     => 'pegawai',
        ])->assertStatus(422)->assertJsonPath('errors.email.0', 'Email ini sudah digunakan.');
    }

    public function test_pegawai_cannot_manage_accounts(): void
    {
        $this->actingAs($this->pegawai)->getJson('/api/v1/users')->assertStatus(403);
        $this->actingAs($this->pegawai)->postJson('/api/v1/users', [
            'name'     => 'Ilegal',
            'email'    => 'ilegal@aedohotel.com',
            'password' => 'rahasia123',
            'role'     => 'pegawai',
        ])->assertStatus(403);
    }

    public function test_index_supports_search_role_filter_and_pagination(): void
    {
        foreach (['Budi', 'Sari', 'Joko'] as $i => $name) {
            User::create([
                'name'     => $name,
                'email'    => 'staff' . $i . '@aedohotel.com',
                'password' => 'rahasia123',
                'role'     => UserRole::Pegawai,
                'is_active' => true,
            ]);
        }

        $this->actingAs($this->admin)->getJson('/api/v1/users?per_page=2')
            ->assertStatus(200)
            ->assertJsonPath('total', 5)
            ->assertJsonCount(2, 'data');

        $this->actingAs($this->admin)->getJson('/api/v1/users?search=Joko')
            ->assertStatus(200)
            ->assertJsonPath('data.0.name', 'Joko')
            ->assertJsonPath('total', 1);

        $this->actingAs($this->admin)->getJson('/api/v1/users?role=admin')
            ->assertStatus(200)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.email', 'admin@aedohotel.com');
    }

    public function test_list_exposes_open_shift_count_per_account(): void
    {
        Shift::create([
            'user_id'    => $this->pegawai->id,
            'started_at' => now(),
            'status'     => ShiftStatus::Open,
        ]);

        $this->actingAs($this->admin)->getJson('/api/v1/users?search=Pegawai Satu')
            ->assertStatus(200)
            ->assertJsonPath('data.0.open_shifts_count', 1);
    }

    public function test_admin_cannot_deactivate_or_delete_own_account(): void
    {
        $this->actingAs($this->admin)->patchJson("/api/v1/users/{$this->admin->id}/deactivate")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Anda tidak dapat menonaktifkan akun Anda sendiri.');

        $this->actingAs($this->admin)->putJson("/api/v1/users/{$this->admin->id}", ['is_active' => false])
            ->assertStatus(422);

        $this->actingAs($this->admin)->deleteJson("/api/v1/users/{$this->admin->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Anda tidak dapat menghapus akun Anda sendiri.');
    }

    public function test_last_active_admin_cannot_be_deactivated_or_demoted(): void
    {
        // Admin saat ini adalah satu-satunya admin aktif
        $this->actingAs($this->admin)->patchJson("/api/v1/users/{$this->admin->id}/deactivate")
            ->assertStatus(422);

        // Admin lain memproses: tetap ditolak karena ini admin aktif terakhir
        $otherAdmin = User::create([
            'name'      => 'Admin Dua',
            'email'     => 'admin2@aedohotel.com',
            'password'  => 'rahasia123',
            'role'      => UserRole::Admin,
            'is_active' => true,
        ]);

        $this->actingAs($otherAdmin)->patchJson("/api/v1/users/{$this->admin->id}/deactivate")->assertStatus(200);

        // Sekarang admin2 satu-satunya admin aktif, tidak bisa menurunkan dirinya sendiri
        $this->actingAs($otherAdmin)->putJson("/api/v1/users/{$otherAdmin->id}", ['role' => 'pegawai'])
            ->assertStatus(422);
    }

    public function test_pegawai_with_open_shift_cannot_be_deactivated(): void
    {
        $shift = Shift::create([
            'user_id'    => $this->pegawai->id,
            'started_at' => now(),
            'status'     => ShiftStatus::Open,
        ]);

        $this->actingAs($this->admin)->patchJson("/api/v1/users/{$this->pegawai->id}/deactivate")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Akun masih memiliki shift berjalan. Minta pegawai tersebut menutup shift dulu sebelum dinonaktifkan.');

        $shift->update(['ended_at' => now(), 'status' => ShiftStatus::Closed]);

        $this->actingAs($this->admin)->patchJson("/api/v1/users/{$this->pegawai->id}/deactivate")
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', false);
    }

    public function test_deactivated_account_loses_api_access_immediately(): void
    {
        $this->actingAs($this->admin)->patchJson("/api/v1/users/{$this->pegawai->id}/deactivate")
            ->assertStatus(200);

        // Pakai instance terbaru; objek di setUp masih menyimpan is_active lama
        $this->actingAs($this->pegawai->fresh())->getJson('/api/v1/dashboard')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Akun Anda tidak aktif. Hubungi administrator.');

        $this->actingAs($this->admin)->patchJson("/api/v1/users/{$this->pegawai->id}/activate")
            ->assertStatus(200);

        $this->actingAs($this->pegawai->fresh())->getJson('/api/v1/dashboard')->assertStatus(200);
    }

    public function test_account_with_operational_history_cannot_be_deleted(): void
    {
        Shift::create([
            'user_id'    => $this->pegawai->id,
            'started_at' => now(),
            'ended_at'   => now(),
            'status'     => ShiftStatus::Closed,
        ]);

        $this->actingAs($this->admin)->deleteJson("/api/v1/users/{$this->pegawai->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Akun tidak dapat dihapus karena sudah tercatat pada shift. Nonaktifkan saja akun tersebut.');
    }

    public function test_clean_account_can_be_deleted(): void
    {
        $fresh = User::create([
            'name'      => 'Belum Pernah Dipakai',
            'email'     => 'fresh@aedohotel.com',
            'password'  => 'rahasia123',
            'role'      => UserRole::Pegawai,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)->deleteJson("/api/v1/users/{$fresh->id}")->assertStatus(200);

        $this->assertDatabaseMissing('users', ['id' => $fresh->id]);
    }

    public function test_update_can_change_status_and_password_reset_is_audited(): void
    {
        $this->actingAs($this->admin)
            ->putJson("/api/v1/users/{$this->pegawai->id}", ['name' => 'Pegawai Baru', 'is_active' => false])
            ->assertStatus(200);

        $this->pegawai->refresh();
        $this->assertSame('Pegawai Baru', $this->pegawai->name);
        $this->assertFalse($this->pegawai->is_active);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/users/{$this->pegawai->id}/reset-password", ['password' => 'passwordbaru123'])
            ->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action'         => 'user.password_reset',
            'auditable_type' => User::class,
            'auditable_id'   => $this->pegawai->id,
        ]);
    }
}
