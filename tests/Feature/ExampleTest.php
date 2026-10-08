<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_api_is_reachable(): void
    {
        // 404 pada route tidak terdaftar = API berjalan normal
        $this->getJson('/api/v1/nonexistent-route')->assertStatus(404);
    }
}
