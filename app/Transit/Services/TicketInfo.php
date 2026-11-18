<?php

namespace App\Transit\Services;

use Illuminate\Support\Facades\DB;

/**
 * Where riders can buy tickets for the operators of a GTFS stop, from the feed itself:
 * agency.txt (agency_fare_url, else agency_url) and fare_media.txt (GTFS-Fares v2).
 * Links only: the app never sells tickets.
 */
class TicketInfo
{
    /** fare_media_type values of GTFS-Fares v2. */
    private const CONTACTLESS_BANK_CARD = 3;

    private const MOBILE_APP = 4;

    /**
     * @param  int  $dataFeedId  feed row holding the served data (see FeedData)
     * @param  list<string|null>  $agencyIds  agencies of the routes serving the stop (null: not set in routes.txt)
     * @return array{operators: list<array{agency_id: ?string, name: string, url: string, fare_page: bool}>, contactless: bool, apps: list<string>}
     */
    public function forStop(int $dataFeedId, array $agencyIds): array
    {
        $agencies = DB::table('transit_agencies')->where('feed_id', $dataFeedId)->get(['agency_id', 'name', 'url', 'fare_url']);
        $known = array_values(array_filter($agencyIds, fn (?string $id): bool => $id !== null && $id !== ''));
        // routes.txt may omit agency_id when a feed has a single agency.
        if ($known !== []) {
            $agencies = $agencies->whereIn('agency_id', $known);
        }

        $operators = [];
        foreach ($agencies as $agency) {
            $fareUrl = $this->httpUrl($agency->fare_url);
            $url = $fareUrl ?? $this->httpUrl($agency->url);
            if ($url !== null) {
                $operators[$url] = ['agency_id' => $agency->agency_id !== null ? (string) $agency->agency_id : null, 'name' => (string) $agency->name, 'url' => $url, 'fare_page' => $fareUrl !== null];
            }
        }

        $media = DB::table('transit_fare_media')->where('feed_id', $dataFeedId)->get(['name', 'type']);

        return [
            'operators' => array_values($operators),
            'contactless' => $media->contains(fn ($m): bool => (int) $m->type === self::CONTACTLESS_BANK_CARD),
            'apps' => $media->filter(fn ($m): bool => (int) $m->type === self::MOBILE_APP && trim((string) $m->name) !== '')
                ->pluck('name')->map(fn ($name): string => trim((string) $name))->unique()->values()->all(),
        ];
    }

    /** Feed data is third-party: only plain http(s) links are passed on. */
    private function httpUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) ? $url : null;
    }
}
