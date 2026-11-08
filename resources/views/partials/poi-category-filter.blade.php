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
                @if (!empty($poiCategories[$location]['icon']))
                    {{ $poiCategories[$location]['icon'] }}
                @else
                    <i class="fa-solid fa-location-dot"></i>
                @endif
            </span>
            <span class="poi-filter-name">{{ $poiCategories[$location]['label'] }}</span>
        </label>
        <div class="poi-filter-actions">
            <span class="poi-filter-count" aria-label="{{ count($poiSubcategories[$location] ?? []) }} subcategorii">{{ count($poiSubcategories[$location] ?? []) }}</span>
            @if ($location === 'fuel')
                <button class="fuel-best-button" type="button" aria-label="Arată cele mai bune prețuri">BEST</button>
            @endif
            <button
                class="poi-subcategory-toggle"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#poi-subcategories-{{ $categoryIndex }}"
                aria-expanded="false"
                aria-controls="poi-subcategories-{{ $categoryIndex }}"
                aria-label="Subcategorii: {{ $poiCategories[$location]['label'] }}"
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
