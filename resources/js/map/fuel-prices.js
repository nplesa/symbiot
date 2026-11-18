/**
 * Fuel prices (Romania, Monitorul Prețurilor): the "BEST" modal of the fuel category (cheapest chain
 * around and in the country) and the price list of a station picked on the map.
 */
import { fetchJson } from '../http.js';
import { intlLocale, t } from '../i18n.js';

const deps = {
    getRadius: () => 5000,
    hidePoiTooltip: () => {},
};

/** The search radius and the tooltip of the map page. */
export function configureFuelPrices(options) {
    Object.assign(deps, options);
}

let fuelBestRequest = 0;

function fuelBestRefs() {
    const el = (id) => document.getElementById(id);
    const refs = {
        modal: el('fuelBestModal'), select: el('fuelBestSelect'), status: el('fuelBestStatus'),
        result: el('fuelBestResult'), chain: el('fuelBestChain'), price: el('fuelBestPrice'),
        meta: el('fuelBestMeta'), station: el('fuelBestStation'), ranking: el('fuelBestRanking'),
        navigate: el('fuelBestNavigate'), source: el('fuelBestSource'),
        countrySection: el('fuelBestCountrySection'),
        countryStatus: el('fuelBestCountryStatus'), countryResult: el('fuelBestCountryResult'),
        countryChain: el('fuelBestCountryChain'), countryPrice: el('fuelBestCountryPrice'),
        countryMeta: el('fuelBestCountryMeta'), countryStation: el('fuelBestCountryStation'),
        countryNavigate: el('fuelBestCountryNavigate'),
    };
    return Object.values(refs).every(Boolean) ? refs : null;
}

// Brands listed in the fuel subcategories: the checked ones, or all that are available around the location.
function fuelBrandIds() {
    const brands = Array.from(document.querySelectorAll('.location-subcategory[data-filter^="subcategory:fuel:"]'))
        .filter(input => !input.disabled)
        .map(input => ({ id: input.dataset.filter.replace('subcategory:fuel:', ''), checked: input.checked }))
        .filter(brand => brand.id !== 'all' && brand.id !== 'lpg');
    const checked = brands.filter(brand => brand.checked);

    return (checked.length > 0 ? checked : brands).map(brand => brand.id);
}

const formatMeters = (meters) => (meters < 1000 ? `${meters} m` : `${(meters / 1000).toFixed(1)} km`);
const mapsDirectionsUrl = (station) => `https://www.google.com/maps/dir/?api=1&destination=${station.lat},${station.lon}&travelmode=driving`;

async function requestBestFuel(params) {
    return fetchJson(`/api/fuel/best?${params}`);
}

async function loadBestFuelPrice() {
    const refs = fuelBestRefs();
    const cl = document.getElementById('current_location');
    if (!refs) return;

    const lat = parseFloat(cl?.dataset.lat);
    const lon = parseFloat(cl?.dataset.lon);
    [refs.result, refs.countrySection, refs.countryResult, refs.source, refs.navigate, refs.countryNavigate]
        .forEach((node) => { node.hidden = true; });
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) {
        refs.status.textContent = t('Choose a location first.');
        refs.countryStatus.textContent = '';
        return;
    }

    const requestId = ++fuelBestRequest;
    const fuel = refs.select.value;
    const isInRomania = lat >= 43.5 && lat <= 48.4 && lon >= 20 && lon <= 30;
    if (!isInRomania) {
        refs.status.textContent = t('Fuel price data is only available for Romania.');
        refs.countryStatus.textContent = '';
        return;
    }

    refs.status.textContent = t('Searching for the best prices in your area...');
    refs.countryStatus.textContent = t('Searching for the best price in the country...');
    const base = { lat, lon, fuel };
    const brands = fuelBrandIds();
    const localParams = new URLSearchParams({
        ...base,
        radius: Math.min(Math.round(Number(deps.getRadius())) || 5000, 35000),
        ...(brands.length > 0 ? { brands: brands.join(',') } : {}),
    });

    const renderLocal = async () => {
        try {
            const data = await requestBestFuel(localParams);
            if (requestId !== fuelBestRequest) return null;
            if (!Array.isArray(data.chains) || data.chains.length === 0) {
                refs.status.textContent = t('No prices reported for :fuel at the subcategory chains in your area.', { fuel: data.fuel.toLowerCase() });
                return null;
            }

            const best = data.chains[0];
            const radiusKm = (data.radius / 1000).toLocaleString(intlLocale, { maximumFractionDigits: 1 });
            refs.status.textContent = t('Search radius: :radius km, :fuel.', { radius: radiusKm, fuel: data.fuel.toLowerCase() });
            refs.chain.textContent = best.name;
            refs.price.textContent = `${best.average_price.toFixed(2)} lei/l`;
            refs.meta.textContent = best.stations === 1
                ? t('average price, :count station', { count: best.stations })
                : t('average price, :count stations', { count: best.stations });
            const nearest = best.nearest;
            refs.station.textContent = t('Nearest: :station (:distance, :price lei/l)', {
                station: [nearest.name, nearest.address].filter(Boolean).join(', '),
                distance: formatMeters(nearest.meters),
                price: nearest.price.toFixed(2),
            });
            refs.ranking.replaceChildren();
            data.chains.forEach((chain) => {
                const item = document.createElement('li');
                item.textContent = `${chain.name} — ${chain.average_price.toFixed(2)} lei/l (${chain.stations})`;
                refs.ranking.appendChild(item);
            });
            refs.navigate.href = mapsDirectionsUrl(nearest);
            refs.navigate.hidden = false;
            refs.result.hidden = false;
            refs.source.textContent = t('Source: :source. Prices are indicative; the ranking uses the chain\'s average price.', { source: data.source });
            refs.source.hidden = false;
            return best;
        } catch (error) {
            if (requestId !== fuelBestRequest) return null;
            console.error('Best fuel price request failed.', error);
            refs.status.textContent = t('Prices could not be loaded right now. Try again.');
            return null;
        }
    };

    const localResultPromise = renderLocal();

    const renderCountry = async () => {
        try {
            const data = await requestBestFuel(new URLSearchParams({ ...base, scope: 'country' }));
            if (requestId !== fuelBestRequest) return;
            if (!Array.isArray(data.chains) || data.chains.length === 0) {
                refs.countryStatus.textContent = t('No data for the country.');
                return;
            }

            const best = data.chains[0];
            const localBest = await localResultPromise;
            if (requestId !== fuelBestRequest || !localBest || best.average_price >= localBest.average_price) return;

            refs.countrySection.hidden = false;
            refs.countryStatus.textContent = t('Calculated from :count county seat cities.', { count: data.cities });
            refs.countryChain.textContent = best.name;
            refs.countryPrice.textContent = `${best.average_price.toFixed(2)} lei/l`;
            refs.countryMeta.textContent = t('average price, :count stations', { count: best.stations });
            const cheapest = best.nearest;
            refs.countryStation.textContent = t('Cheapest station: :station (:price lei/l)', {
                station: [cheapest.name, cheapest.address, cheapest.city].filter(Boolean).join(', '),
                price: cheapest.price.toFixed(2),
            });
            refs.countryNavigate.href = mapsDirectionsUrl(cheapest);
            refs.countryNavigate.hidden = false;
            refs.countryResult.hidden = false;
        } catch (error) {
            if (requestId !== fuelBestRequest) return;
            console.error('National best fuel price request failed.', error);
            refs.countryStatus.textContent = t('Country prices could not be loaded right now.');
        }
    };

    await Promise.all([localResultPromise, renderCountry()]);
}
document.addEventListener('click', (event) => {
    if (!event.target.closest('.fuel-best-button')) return;
    const refs = fuelBestRefs();
    if (!refs) return;
    bootstrap.Modal.getOrCreateInstance(refs.modal).show();
    loadBestFuelPrice();
});
document.addEventListener('hide.bs.modal', (event) => {
    if (event.target instanceof HTMLElement && event.target.contains(document.activeElement)) {
        document.activeElement.blur();
    }
});
document.addEventListener('change', (event) => {
    if (event.target.id === 'fuelBestSelect') loadBestFuelPrice();
});

let fuelPricesRequest = 0;

export async function showFuelPrices(feature) {
    const el = (id) => document.getElementById(id);
    const modalElement = el('fuelPricesModal');
    const refs = {
        title: el('fuelPricesModalTitle'),
        brand: el('fuelPricesBrand'),
        station: el('fuelPricesStation'),
        status: el('fuelPricesStatus'),
        fuelsHeading: el('fuelPricesFuelsHeading'),
        table: el('fuelPricesTable'),
        body: el('fuelPricesBody'),
        servicesHeading: el('fuelPricesServicesHeading'),
        services: el('fuelPricesServices'),
        servicesNote: el('fuelPricesServicesNote'),
        source: el('fuelPricesSource'),
        navigate: el('fuelPricesNavigate'),
    };
    const coordinates = feature.get('coordinates');
    if (!modalElement || Object.values(refs).some((ref) => !ref) || !coordinates) {
        console.error('The fuel prices modal is missing required elements.');
        return;
    }

    deps.hidePoiTooltip();
    // The untranslated fallback is still sent to the API as the brand, as before.
    const name = feature.get('name') || 'Benzinărie';
    const displayName = feature.get('name') || t('Fuel station');
    const osmAddress = feature.get('address')?.formatted ?? '';
    refs.title.textContent = displayName;
    refs.brand.textContent = displayName;
    refs.station.textContent = osmAddress || t('Address not available');
    refs.status.textContent = t('Loading prices...');
    [refs.fuelsHeading, refs.table, refs.servicesHeading, refs.services, refs.servicesNote, refs.source]
        .forEach((node) => { node.hidden = true; });
    refs.body.replaceChildren();
    refs.services.replaceChildren();
    refs.navigate.href = `https://www.google.com/maps/dir/?api=1&destination=${coordinates.lat},${coordinates.lon}&travelmode=driving`;
    refs.navigate.hidden = false;
    bootstrap.Modal.getOrCreateInstance(modalElement).show();

    const requestId = ++fuelPricesRequest;
    try {
        const params = new URLSearchParams({ lat: coordinates.lat, lon: coordinates.lon, brand: name });
        const data = await fetchJson(`/api/fuel/prices?${params}`);
        if (requestId !== fuelPricesRequest) return;

        if (!data.station) {
            refs.status.textContent = t('The station has no price or service data in Monitorul Prețurilor.');
            return;
        }

        refs.brand.textContent = data.station.network || displayName;
        refs.station.textContent = data.station.address || osmAddress || t('Address not available');
        refs.status.textContent = '';

        if (Array.isArray(data.prices) && data.prices.length > 0) {
            data.prices.forEach((item) => {
                const row = document.createElement('tr');
                const fuel = document.createElement('td');
                fuel.textContent = item.label;
                if (item.product) {
                    const product = document.createElement('div');
                    product.className = 'small text-muted';
                    product.textContent = item.product;
                    fuel.appendChild(product);
                }
                const price = document.createElement('td');
                price.className = 'text-end fw-semibold';
                price.textContent = `${Number(item.price).toFixed(2)} lei`;
                row.append(fuel, price);
                refs.body.appendChild(row);
            });
            refs.fuelsHeading.hidden = false;
            refs.table.hidden = false;
        } else {
            refs.status.textContent = t('No prices reported for this station.');
        }

        if (Array.isArray(data.station.services) && data.station.services.length > 0) {
            data.station.services.forEach((service) => {
                const badge = document.createElement('li');
                badge.className = 'list-inline-item badge text-bg-secondary';
                badge.textContent = service;
                refs.services.appendChild(badge);
            });
            refs.servicesHeading.hidden = false;
            refs.services.hidden = false;
            refs.servicesNote.hidden = false;
        }

        refs.source.textContent = t('Source: :source. Updated: :date. Prices are indicative.', {
            source: data.source,
            date: data.station.updated_at || t('unknown'),
        });
        refs.source.hidden = false;
    } catch (error) {
        if (requestId !== fuelPricesRequest) return;
        console.error('Fuel prices request failed.', error);
        refs.status.textContent = t('Prices could not be loaded right now. Try again.');
    }
}
