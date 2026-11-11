<?php

namespace Tests\Feature;

use App\Services\IpCountryResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IpCountryResolverTest extends TestCase
{
    private function request(array $headers = []): Request
    {
        $server = ['REMOTE_ADDR' => '86.120.10.5'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create('/register', 'POST', server: $server);
    }

    public function test_forged_cloudflare_header_is_ignored_when_not_behind_cloudflare(): void
    {
        config(['services.ip_country.trust_cloudflare_header' => false, 'services.ip_country.lookup' => true]);
        Http::fake(['ipwho.is/*' => Http::response(['success' => true, 'country_code' => 'RO'])]);

        $this->assertSame('RO', app(IpCountryResolver::class)->resolve($this->request(['CF-IPCountry' => 'US'])));
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://ipwho.is/86.120.10.5'));
    }

    public function test_cloudflare_header_is_used_when_trusted(): void
    {
        config(['services.ip_country.trust_cloudflare_header' => true, 'services.ip_country.lookup' => true]);
        Http::fake();

        $this->assertSame('US', app(IpCountryResolver::class)->resolve($this->request(['CF-IPCountry' => 'us'])));
        Http::assertNothingSent();
    }

    public function test_external_lookup_can_be_disabled(): void
    {
        config(['services.ip_country.trust_cloudflare_header' => false, 'services.ip_country.lookup' => false]);
        Http::fake();

        $this->assertNull(app(IpCountryResolver::class)->resolve($this->request()));
        Http::assertNothingSent();
    }
}
