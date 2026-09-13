<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleMapsResolveTest extends TestCase
{
    use RefreshDatabase;

    public function test_short_link_is_resolved_to_a_google_maps_url(): void
    {
        Http::fake([
            'https://maps.app.goo.gl/first' => Http::response('', 302, [
                'Location' => 'https://maps.app.goo.gl/second',
            ]),
            'https://maps.app.goo.gl/second' => Http::response('', 302, [
                'Location' => 'https://www.google.com/maps/dir/?api=1&origin=Brasov&destination=Sinaia',
            ]),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/google-maps/resolve?url=' . urlencode('https://maps.app.goo.gl/first'))
            ->assertOk()
            ->assertJsonPath('url', 'https://www.google.com/maps/dir/?api=1&origin=Brasov&destination=Sinaia');

        Http::assertSentCount(2);
    }

    public function test_short_link_cannot_redirect_to_an_external_host(): void
    {
        Http::fake([
            'https://maps.app.goo.gl/unsafe' => Http::response('', 302, [
                'Location' => 'https://example.test/redirect',
            ]),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/google-maps/resolve?url=' . urlencode('https://maps.app.goo.gl/unsafe'))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Redirect extern blocat în timpul rezolvării linkului Google Maps.');

        Http::assertSentCount(1);
    }

    public function test_short_link_can_be_resolved_from_the_canonical_html_url(): void
    {
        Http::fake([
            'https://maps.app.goo.gl/canonical' => Http::response(
                '<html><head><link rel="canonical" href="https://www.google.com/maps/dir/?api=1&amp;origin=Brasov&amp;destination=Sinaia"></head></html>',
                200
            ),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/google-maps/resolve?url=' . urlencode('https://maps.app.goo.gl/canonical'))
            ->assertOk()
            ->assertJsonPath('url', 'https://www.google.com/maps/dir/?api=1&origin=Brasov&destination=Sinaia');
    }

    public function test_non_google_url_is_rejected_without_an_outbound_request(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/google-maps/resolve?url=' . urlencode('https://example.test/maps/dir'))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Linkul nu este un link Google Maps valid.');

        Http::assertNothingSent();
    }
}
