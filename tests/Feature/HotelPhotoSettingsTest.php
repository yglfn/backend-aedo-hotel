<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class HotelPhotoSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $pegawai;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');
        Storage::fake('local');

        $this->admin = User::where('role', UserRole::Admin)->first();
        $this->pegawai = User::where('role', UserRole::Pegawai)->first();
    }

    public function test_admin_can_upload_hotel_photo_and_public_endpoint_serves_it(): void
    {
        $response = $this->uploadPhoto(UploadedFile::fake()->image('hotel.jpg', 800, 600))->assertOk();

        $setting = Setting::instance();
        $this->assertNotNull($setting->hotel_photo_path);
        Storage::disk('local')->assertExists($setting->hotel_photo_path);

        $expectedUrl = url('/api/v1/settings/photo') . '?v=' . $setting->updated_at->timestamp;
        $response->assertJsonPath('data.hotel_photo_url', $expectedUrl);

        $this->getJson('/api/v1/settings')
            ->assertOk()
            ->assertJsonPath('data.hotel_photo_url', $expectedUrl)
            ->assertJsonMissingPath('data.hotel_photo_path');

        $this->getJson('/api/v1/settings/photo')
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');
    }

    public function test_replacing_hotel_photo_deletes_the_previous_file(): void
    {
        $this->uploadPhoto(UploadedFile::fake()->image('first.png', 400, 400))->assertOk();
        $firstPath = Setting::instance()->hotel_photo_path;

        $this->uploadPhoto(UploadedFile::fake()->image('second.webp', 500, 500))->assertOk();
        $secondPath = Setting::instance()->hotel_photo_path;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($secondPath);
    }

    public function test_admin_can_remove_hotel_photo(): void
    {
        $this->uploadPhoto(UploadedFile::fake()->image('hotel.png', 400, 400))->assertOk();
        $path = Setting::instance()->hotel_photo_path;

        $this->actingAs($this->admin)
            ->post('/api/v1/settings', array_merge($this->settingsPayload(), [
                '_method' => 'PUT',
                'remove_hotel_photo' => '1',
            ]), ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.hotel_photo_url', null);

        $this->assertNull(Setting::instance()->hotel_photo_path);
        Storage::disk('local')->assertMissing($path);
        $this->getJson('/api/v1/settings/photo')->assertNotFound();
    }

    public function test_invalid_hotel_photo_is_rejected(): void
    {
        $this->uploadPhoto(UploadedFile::fake()->create('document.pdf', 50, 'application/pdf'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('hotel_photo');

        $this->assertNull(Setting::instance()->hotel_photo_path);
    }

    public function test_pegawai_cannot_upload_hotel_photo(): void
    {
        $this->actingAs($this->pegawai)
            ->post('/api/v1/settings', array_merge($this->settingsPayload(), [
                '_method' => 'PUT',
                'hotel_photo' => UploadedFile::fake()->image('hotel.jpg', 400, 400),
            ]), ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->assertNull(Setting::instance()->hotel_photo_path);
    }

    private function uploadPhoto(UploadedFile $photo): TestResponse
    {
        return $this->actingAs($this->admin)
            ->post('/api/v1/settings', array_merge($this->settingsPayload(), [
                '_method' => 'PUT',
                'hotel_photo' => $photo,
            ]), ['Accept' => 'application/json']);
    }

    private function settingsPayload(): array
    {
        $setting = Setting::instance();

        return [
            'hotel_name' => $setting->hotel_name,
            'hotel_address' => $setting->hotel_address,
            'hotel_phone' => $setting->hotel_phone,
            'hotel_email' => $setting->hotel_email,
            'default_check_in' => $setting->default_check_in,
            'default_check_out' => $setting->default_check_out,
            'tax_percentage' => $setting->tax_percentage,
            'min_dp_percentage' => $setting->min_dp_percentage,
            'payment_verification_enabled' => $setting->payment_verification_enabled ? '1' : '0',
        ];
    }
}
