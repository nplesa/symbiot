<?php

namespace App\Services;

class PoiCatalog
{
    /** Display names for the stable `group` ids used in config/poi.php (and in view logic). */
    private const GROUP_LABELS = [
        'services' => 'Services and stops',
        'police' => 'Police',
    ];

    /**
     * Labels in config/poi.php are English translation keys; they are translated here once,
     * for the current locale, so every view, API response and JS consumer gets the same text.
     *
     * @return array<string, array{label: string, group_label: string, icon: ?string, default: bool, group: string, distance: int, geoapify: list<string>, osm: list<array<string, ?string>>}>
     */
    public function categories(): array
    {
        return array_map(fn (array $category): array => [
            ...$category,
            'label' => __($category['label']),
            'group_label' => __(self::GROUP_LABELS[$category['group']] ?? $category['group']),
        ], config('poi.categories', []));
    }

    /** @return array<string, array{type: string, label: string, geoapify: list<string>, osm: list<array<string, ?string>>}> */
    public function filters(): array
    {
        $filters = [];

        foreach ($this->categories() as $type => $category) {
            $filters[$type] = [
                'type' => $type,
                'label' => $category['label'],
                'geoapify' => $category['geoapify'],
                'osm' => $category['osm'],
            ];

            foreach ($this->subcategories($type) as $subcategory) {
                $filters[$subcategory['id']] = [
                    'type' => $subcategory['type'] ?? $type,
                    'label' => $subcategory['label'],
                    'geoapify' => $subcategory['geoapify'],
                    'osm' => $subcategory['osm'],
                ];
            }
        }

        return $filters;
    }

    /** @return list<array{id: string, type?: string, label: string, geoapify: list<string>, osm: list<array<string, ?string>>}> */
    public function subcategories(string $type): array
    {
        $category = $this->categories()[$type] ?? null;
        if ($category === null) {
            return [];
        }

        $children = [];
        $geoapifyTypes = config('poi-subcategories.' . $type, []);
        foreach ($geoapifyTypes as $geoapifyType) {
            $leaf = last(explode('.', $geoapifyType));
            $children[] = [
                'id' => 'subcategory:' . $type . ':' . $geoapifyType,
                'label' => str($leaf)->replace('_', ' ')->title()->toString(),
                'geoapify' => [$geoapifyType],
                'osm' => [],
            ];
        }

        foreach (config('poi.osm_subcategories.' . $type, []) as $subcategory) {
            $children[] = [
                'id' => 'subcategory:' . $type . ':' . $subcategory['id'],
                'type' => $subcategory['type'] ?? $type,
                // Brand names (Petrom, OMV...) have no translation and are returned unchanged.
                'label' => __($subcategory['label']),
                'geoapify' => [],
                'osm' => $subcategory['osm'],
            ];
        }

        if ($children === []) {
            $children[] = [
                'id' => 'subcategory:' . $type . ':all',
                'label' => __('All'),
                'geoapify' => $category['geoapify'],
                'osm' => $category['osm'],
            ];
        }

        return $children;
    }

    /** @return list<string> */
    public function enabledTypes(): array
    {
        $raw = config('services.geoapify.locations');
        $types = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($types) || ! array_is_list($types)) {
            $types = config('poi.default_types', []);
        }

        return array_values(array_unique(array_filter($types,
            fn ($type) => is_string($type) && isset($this->categories()[$type])
        )));
    }

    /** @return array<string, array{label: string, icon: ?string, default: bool, group: string, distance: int, geoapify: list<string>, osm: list<array<string, ?string>>}> */
    public function navigation(): array
    {
        $types = array_merge(config('poi.navigation_types', []), $this->enabledTypes());

        return array_intersect_key($this->categories(), array_flip($types));
    }

    /** @param array<string, string> $tags */
    public function osmType(array $tags): ?string
    {
        foreach ($this->navigation() as $type => $category) {
            foreach ($category['osm'] as $selector) {
                foreach ($selector as $key => $values) {
                    if (! isset($tags[$key]) || ($values !== null && ! in_array($tags[$key], explode('|', $values), true))) {
                        continue 2;
                    }
                }

                return $type;
            }
        }

        return null;
    }

    /** @param list<string> $categories */
    public function geoapifyType(array $categories): string
    {
        foreach ($this->categories() as $type => $definition) {
            foreach ($definition['geoapify'] as $prefix) {
                foreach ($categories as $category) {
                    if ($category === $prefix || str_starts_with($category, $prefix . '.')) {
                        return $type;
                    }
                }
            }
        }

        return 'transport';
    }
}
