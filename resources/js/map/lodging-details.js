/** The details modal of a lodging POI (Geoapify / OpenStreetMap data). */
import { t } from '../i18n.js';
import { safeExternalUrl } from '../safe-url.js';
import { getGoogleMapsPlaceUrl } from './poi-map-interactions.js';

const deps = { hidePoiTooltip: () => {} };

export function configureLodgingDetails(options) {
    Object.assign(deps, options);
}

export function showLodgingDetails(feature) {
    const modalElement = document.getElementById('lodgingDetailsModal');
    const modalTitle = document.getElementById('lodgingDetailsModalTitle');
    const detailsList = document.getElementById('lodgingDetailsList');
    const emptyMessage = document.getElementById('lodgingDetailsEmpty');
    const mapLink = document.getElementById('lodgingDetailsMapLink');
    const name = feature.get('name') || t('Lodging unit');
    const address = feature.get('address') ?? {};
    const details = feature.get('details') ?? {};
    const labels = {
        address: t('Address'),
        accommodation_type: t('Accommodation type'),
        phone: t('Phone'),
        email: t('Email'),
        website: t('Website'),
        opening_hours: t('Opening hours'),
        operator: t('Operator'),
        brand: t('Brand'),
        stars: t('Rating'),
        rooms: t('Rooms'),
        beds: t('Beds'),
        check_in: t('Check-in'),
        check_out: t('Check-out'),
        wheelchair: t('Accessibility'),
        internet_access: t('Internet'),
        description: t('Description'),
    };

    if (!modalElement || !modalTitle || !detailsList || !emptyMessage || !mapLink) {
        console.error('The lodging details modal is missing required elements.');
        return;
    }

    deps.hidePoiTooltip();
    modalTitle.textContent = name;
    detailsList.replaceChildren();

    const addressText = details.address
        || address.formatted
        || [address.city, address.county, address.country].filter(Boolean).join(', ');
    const values = {
        address: addressText,
        ...details,
    };

    Object.entries(labels).forEach(([key, label]) => {
        const value = values[key];
        if (typeof value !== 'string' || !value.trim()) return;

        const row = document.createElement('div');
        row.className = 'lodging-detail-row';
        const term = document.createElement('dt');
        term.textContent = label;
        const description = document.createElement('dd');
        if (key === 'website') {
            const link = createSafeExternalLink(value);
            if (link) {
                link.textContent = value;
                description.appendChild(link);
            } else {
                description.textContent = value;
            }
        } else if (key === 'phone' && /^[+()\d\s.-]+$/.test(value)) {
            const link = document.createElement('a');
            link.href = `tel:${value.replace(/[^\d+]/g, '')}`;
            link.textContent = value;
            description.appendChild(link);
        } else if (key === 'email' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
            const link = document.createElement('a');
            link.href = `mailto:${value}`;
            link.textContent = value;
            description.appendChild(link);
        } else {
            description.textContent = value;
        }
        row.append(term, description);
        detailsList.appendChild(row);
    });

    emptyMessage.hidden = detailsList.childElementCount > 0;
    emptyMessage.textContent = t('No other details are available for this property. The information depends on the data provided by Geoapify or OpenStreetMap.');
    const coordinates = feature.get('coordinates');
    const mapUrl = getGoogleMapsPlaceUrl(name, coordinates);
    if (mapUrl) {
        mapLink.href = mapUrl;
        mapLink.hidden = false;
    } else {
        mapLink.hidden = true;
    }

    bootstrap.Modal.getOrCreateInstance(modalElement).show();
}

function createSafeExternalLink(value) {
    const href = safeExternalUrl(value);
    if (!href) return null;

    const link = document.createElement('a');
    link.href = href;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    return link;
}
