/** The 3-day weather forecast of the right panel (#dashboard-weather, WeatherAPI.com through the app). */
import { formatNumber, intlLocale, t } from '../i18n.js';

const dashboardWeather = document.getElementById('dashboard-weather');
const dashboardWeatherCurrent = document.getElementById('dashboard-weather-current');
const dashboardWeatherStatus = document.getElementById('dashboard-weather-status');
const dashboardWeatherDays = document.getElementById('dashboard-weather-days');
const dashboardWeatherToggle = document.getElementById('dashboard-weather-toggle');
const dashboardWeatherBody = document.getElementById('dashboard-weather-body');
const dashboardWeatherSummary = document.getElementById('dashboard-weather-summary');
// Collapsed unless the user opened it (remembered in this browser).
const WEATHER_OPEN_KEY = 'symbiot-weather-open';
let weatherRequestId = 0;
let weatherRequestController = null;

function appendWeatherIcon(container, iconUrl, description) {
    if (!iconUrl) return;
    const image = document.createElement('img');
    image.src = iconUrl.startsWith('//') ? `https:${iconUrl}` : iconUrl;
    image.alt = description || '';
    image.loading = 'lazy';
    container.appendChild(image);
}

function setWeatherOpen(open, remember = false) {
    if (!dashboardWeather || !dashboardWeatherToggle || !dashboardWeatherBody) return;
    dashboardWeather.classList.toggle('is-open', open);
    dashboardWeatherToggle.setAttribute('aria-expanded', String(open));
    dashboardWeatherBody.hidden = !open;
    if (remember) {
        try { localStorage.setItem(WEATHER_OPEN_KEY, open ? '1' : '0'); } catch { /* the choice is just not kept */ }
    }
}

let weatherOpen = false;
try { weatherOpen = localStorage.getItem(WEATHER_OPEN_KEY) === '1'; } catch { /* collapsed */ }
setWeatherOpen(weatherOpen);
dashboardWeatherToggle?.addEventListener('click', () => {
    setWeatherOpen(dashboardWeatherToggle.getAttribute('aria-expanded') !== 'true', true);
});

function renderWeatherForecast(data) {
    if (!dashboardWeatherCurrent || !dashboardWeatherDays) return;
    dashboardWeatherCurrent.replaceChildren();
    dashboardWeatherDays.replaceChildren();

    const current = data.current ?? {};
    if (dashboardWeatherSummary) {
        const hasTemperature = current.temperature_c !== null && current.temperature_c !== undefined && Number.isFinite(Number(current.temperature_c));
        dashboardWeatherSummary.textContent = [
            hasTemperature ? `${formatNumber(Math.round(Number(current.temperature_c)))}°` : null,
            current.condition || null,
        ].filter(Boolean).join(' ');
    }
    appendWeatherIcon(dashboardWeatherCurrent, current.icon, current.condition);
    if (current.temperature_c !== null && current.temperature_c !== undefined && Number.isFinite(Number(current.temperature_c))) {
        const temperature = document.createElement('strong');
        temperature.textContent = `${formatNumber(Math.round(Number(current.temperature_c)))}°`;
        dashboardWeatherCurrent.appendChild(temperature);
    }
    if (current.condition) {
        const description = document.createElement('span');
        description.textContent = current.condition;
        dashboardWeatherCurrent.appendChild(description);
    }

    (Array.isArray(data.days) ? data.days : []).slice(0, 3).forEach(day => {
        const card = document.createElement('article');
        card.className = 'dashboard-weather-day';

        const date = document.createElement('strong');
        const parsedDate = new Date(`${day.date}T12:00:00`);
        date.textContent = Number.isNaN(parsedDate.getTime())
            ? String(day.date ?? '')
            : new Intl.DateTimeFormat(intlLocale, { weekday: 'short', day: 'numeric', month: 'short' }).format(parsedDate);

        const condition = document.createElement('div');
        condition.className = 'dashboard-weather-condition';
        appendWeatherIcon(condition, day.icon, day.condition);
        const conditionText = document.createElement('span');
        conditionText.textContent = day.condition || '';
        condition.appendChild(conditionText);

        const temperatures = document.createElement('div');
        temperatures.className = 'dashboard-weather-temperatures';
        const high = document.createElement('strong');
        high.textContent = day.max_c === null || day.max_c === undefined ? '—' : `${formatNumber(Math.round(Number(day.max_c)))}°`;
        const low = document.createElement('span');
        low.textContent = day.min_c === null || day.min_c === undefined ? '—' : `${formatNumber(Math.round(Number(day.min_c)))}°`;
        temperatures.append(high, low);

        card.append(date, condition, temperatures);
        if (day.rain_chance !== null && day.rain_chance !== undefined && Number.isFinite(Number(day.rain_chance))) {
            const rain = document.createElement('small');
            rain.textContent = `${t('Rain')} ${formatNumber(Number(day.rain_chance))}%`;
            card.appendChild(rain);
        }
        dashboardWeatherDays.appendChild(card);
    });
}

export async function loadWeatherForecast(lat, lon) {
    const latitude = Number(lat);
    const longitude = Number(lon);
    if (!dashboardWeather || !Number.isFinite(latitude) || !Number.isFinite(longitude)) return;

    const requestId = ++weatherRequestId;
    weatherRequestController?.abort();
    weatherRequestController = new AbortController();
    dashboardWeather.classList.remove('d-none');
    dashboardWeatherCurrent?.replaceChildren();
    dashboardWeatherDays?.replaceChildren();
    if (dashboardWeatherSummary) dashboardWeatherSummary.textContent = '…';
    if (dashboardWeatherStatus) dashboardWeatherStatus.textContent = t('Loading weather forecast…');

    try {
        const url = new URL(dashboardWeather.dataset.url, window.location.origin);
        url.searchParams.set('lat', String(latitude));
        url.searchParams.set('lon', String(longitude));
        const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: weatherRequestController.signal });
        if (!response.ok) throw new Error('Weather forecast unavailable');
        const forecast = await response.json();
        if (requestId !== weatherRequestId) return;
        renderWeatherForecast(forecast);
        if (dashboardWeatherStatus) dashboardWeatherStatus.textContent = forecast.days?.length ? '' : t('Weather forecast unavailable.');
    } catch (error) {
        if (error.name === 'AbortError' || requestId !== weatherRequestId) return;
        if (dashboardWeatherSummary) dashboardWeatherSummary.textContent = '';
        if (dashboardWeatherStatus) dashboardWeatherStatus.textContent = t('Weather forecast unavailable.');
    }
}

/** A new location is being chosen: drop the running request and hide the old forecast. */
export function resetWeather() {
    weatherRequestController?.abort();
    weatherRequestId++;
    dashboardWeather?.classList.add('d-none');
}
