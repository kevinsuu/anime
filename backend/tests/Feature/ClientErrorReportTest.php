<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

final class ClientErrorReportTest extends TestCase
{
    /** @return array<string, mixed> */
    private function report(array $overrides = []): array
    {
        return array_merge([
            'kind' => 'error',
            'message' => "TypeError: Cannot read properties of undefined (reading 'id')",
            'page' => '/seasonal',
            'source' => 'https://anime.example/_nuxt/entry.js',
            'line' => 12,
            'column' => 34,
            'stack' => 'at entry.js:12:34',
            'release' => 'v1.0.47',
        ], $overrides);
    }

    public function test_valid_report_is_logged_to_the_client_channel(): void
    {
        $channel = Mockery::mock();
        $channel->shouldReceive('warning')->once()->with('client error', Mockery::on(
            fn (array $context): bool => $context['kind'] === 'error'
                && $context['page'] === '/seasonal'
                && $context['line'] === 12
                && $context['release'] === 'v1.0.47'
                && array_key_exists('user_agent', $context)
        ));
        Log::shouldReceive('channel')->once()->with('client')->andReturn($channel);

        $this->postJson('/client-errors', $this->report(), ['User-Agent' => 'TestBrowser/1.0'])
            ->assertNoContent();
    }

    public function test_invalid_report_is_rejected_without_logging(): void
    {
        Log::shouldReceive('channel')->never();

        $this->postJson('/client-errors', $this->report(['kind' => 'other']))->assertStatus(422);
        $this->postJson('/client-errors', $this->report(['message' => str_repeat('x', 501)]))->assertStatus(422);
        $this->postJson('/client-errors', $this->report(['page' => '']))->assertStatus(422);
    }

    public function test_reports_are_rate_limited_per_client(): void
    {
        $channel = Mockery::mock();
        $channel->shouldReceive('warning')->times(10);
        Log::shouldReceive('channel')->with('client')->andReturn($channel);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/client-errors', $this->report())->assertNoContent();
        }

        $this->postJson('/client-errors', $this->report())->assertStatus(429);
    }
}
