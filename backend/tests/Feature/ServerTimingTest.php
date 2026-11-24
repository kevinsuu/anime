<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ServerTimingTest extends TestCase
{
    use RefreshDatabase;

    public function test_responses_report_application_time(): void
    {
        $header = (string) $this->getJson('/anime/tags')->assertOk()->headers->get('Server-Timing');

        $this->assertMatchesRegularExpression('/^app;dur=\d+\.\d$/', $header);
    }

    public function test_error_responses_also_report_application_time(): void
    {
        $header = (string) $this->getJson('/anime/999999')->assertNotFound()->headers->get('Server-Timing');

        $this->assertStringStartsWith('app;dur=', $header);
    }
}
