<div class="poi-category-group" data-type="{{ $location }}">
    <div class="poi-filter-row">
        <label class="poi-filter-choice" for="poi-category-{{ $categoryIndex }}">
            <input
                class="form-check-input location-category"
                type="checkbox"
                id="poi-category-{{ $categoryIndex }}"
                data-type="{{ $location }}"
            >
            <span class="poi-filter-icon" aria-hidden="true">
                @switch($location)
                    @case('airport')
                        <i class="fas fa-plane text-primary" aria-hidden="true"></i>
                        @break
                    @case('train')
                        <i class="fas fa-train text-success" aria-hidden="true"></i>
                        @break
                    @case('subway')
                        <i class="fas fa-subway text-danger" aria-hidden="true"></i>
                        @break
                    @case('bus')
                        <i class="fas fa-bus text-warning" aria-hidden="true"></i>
                        @break
                    @default
                        @if (!empty($poiCategories[$location]['icon']))
                            {{ $poiCategories[$location]['icon'] }}
                        @else
                            <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                        @endif
                @endswitch
            </span>
            <span class="poi-filter-name">{{ $poiCategories[$location]['label'] }}</span>
        </label>
        <div class="poi-filter-actions">
            <span class="poi-filter-count" aria-label="{{ __(':count subcategories', ['count' => count($poiSubcategories[$location] ?? [])]) }}">{{ count($poiSubcategories[$location] ?? []) }}</span>
            @if ($location === 'fuel')
                <button class="fuel-best-button" type="button" aria-label="{{ __('Show the best prices') }}">BEST</button>
            @endif
            <button
                class="poi-subcategory-toggle"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#poi-subcategories-{{ $categoryIndex }}"
                aria-expanded="false"
                aria-controls="poi-subcategories-{{ $categoryIndex }}"
                aria-label="{{ __('Subcategories: :category', ['category' => $poiCategories[$location]['label']]) }}"
            >
                <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
            </button>
        </div>
    </div>
    <div class="collapse poi-subcategory-list" id="poi-subcategories-{{ $categoryIndex }}">
        @foreach ($poiSubcategories[$location] as $subcategory)
            <label class="poi-subcategory-choice" for="poi-subcategory-{{ $categoryIndex }}-{{ $loop->index }}">
                <input
                    class="form-check-input location-subcategory"
                    type="checkbox"
                    id="poi-subcategory-{{ $categoryIndex }}-{{ $loop->index }}"
                    data-type="{{ $subcategory['type'] ?? $location }}"
                    data-filter="{{ $subcategory['id'] }}"
                >
                <span>{{ $subcategory['label'] }}</span>
            </label>
        @endforeach
    </div>
</div>