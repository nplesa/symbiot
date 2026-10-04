<div class="col">
    <div class="border rounded p-2 h-100 poi-category-group" data-type="{{ $location }}">
        <div class="d-flex align-items-center justify-content-between gap-2 poi-category-heading">
            <div class="form-check mb-0">
                <input
                    class="form-check-input location-category"
                    type="checkbox"
                    id="poi-category-{{ $categoryIndex }}"
                    data-type="{{ $location }}"
                >
                <label class="form-check-label fw-semibold poi-category-label" for="poi-category-{{ $categoryIndex }}">
                    {{ $poiCategories[$location]['label'] }}
                </label>
            </div>
            <button
                class="btn btn-sm btn-outline-secondary poi-subcategory-toggle"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#poi-subcategories-{{ $categoryIndex }}"
                aria-expanded="{{ ($openSubcategories ?? false) ? 'true' : 'false' }}"
                aria-controls="poi-subcategories-{{ $categoryIndex }}"
            >
                Subcategorii
            </button>
        </div>
        <div class="collapse mt-2 {{ ($openSubcategories ?? false) ? 'show' : '' }}" id="poi-subcategories-{{ $categoryIndex }}">
            <div class="ps-2">
                @foreach ($poiSubcategories[$location] as $subcategory)
                    <div class="form-check">
                        <input
                            class="form-check-input location-subcategory"
                            type="checkbox"
                            id="poi-subcategory-{{ $categoryIndex }}-{{ $loop->index }}"
                            data-type="{{ $subcategory['type'] ?? $location }}"
                            data-filter="{{ $subcategory['id'] }}"
                        >
                        <label class="form-check-label" for="poi-subcategory-{{ $categoryIndex }}-{{ $loop->index }}">
                            {{ $subcategory['label'] }}
                        </label>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
