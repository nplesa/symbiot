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

    public function test_lookalike_google_hosts_are_rejected(): void
    {
        Http::fake();
        $user = User::factory()->create();

        foreach ([
            'https://maps.google.evil.com/maps/dir/Brasov/Sinaia',
            'https://google.evil.com/maps/dir/Brasov/Sinaia',
            'https://google.com.evil.ro/maps/dir/Brasov/Sinaia',
            'javascript://google.com/maps',
        ] as $url) {
            $this->actingAs($user)
                ->getJson('/api/google-maps/resolve?url=' . urlencode($url))
                ->assertUnprocessable();
        }

        foreach ([
            'https://www.google.ro/maps/dir/Brasov/Sinaia',
            'https://maps.google.co.uk/maps?saddr=Brasov',
            'https://www.google.com.br/maps/dir/Brasov/Sinaia',
        ] as $url) {
            $this->actingAs($user)
                ->getJson('/api/google-maps/resolve?url=' . urlencode($url))
                ->assertOk()
                ->assertJsonPath('url', $url);
        }

        Http::assertNothingSent();
    }

    public function test_redirect_chain_stops_after_the_total_deadline(): void
    {
        // Each hop "takes" 8 seconds, so the 20 second budget is spent before the 6 hop limit.
        Http::fake(function ($request) {
            $this->travel(8)->seconds();
            $hop = (int) substr((string) parse_url($request->url(), PHP_URL_PATH), 5);

            return Http::response('', 302, ['Location' => 'https://maps.app.goo.gl/hop' . ($hop + 1)]);
        });

        $this->actingAs(User::factory()->create())
            ->getJson('/api/google-maps/resolve?url=' . urlencode('https://maps.app.goo.gl/hop0'))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Rezolvarea linkului Google Maps a durat prea mult. Încearcă din nou.');

        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->url() === 'https://maps.app.goo.gl/hop0');
    }

    public function test_oversized_html_response_is_rejected(): void
    {
        Http::fake([
            'https://maps.app.goo.gl/huge' => Http::response('<html></html>', 200, ['Content-Length' => (string) (3 * 1024 * 1024)]),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/google-maps/resolve?url=' . urlencode('https://maps.app.goo.gl/huge'))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Răspunsul Google Maps este prea mare.');
    }

    public function test_google_maps_endpoints_share_a_per_user_rate_limit(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $url = '/api/google-maps/resolve?url=' . urlencode('https://www.google.com/maps/dir/Brasov/Sinaia');

        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($user)->getJson($url)->assertOk();
        }

        $this->actingAs($user)->postJson('/api/trasee/google-maps-kml', ['url' => 'https://www.google.com/maps/dir/Brasov/Sinaia'])
            ->assertTooManyRequests();
    }
}
