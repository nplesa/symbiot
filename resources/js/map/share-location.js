/** "Share location" (#shareLocation): the system share sheet, else a WhatsApp message with a Google Maps link. */
import { t } from '../i18n.js';

/**
 * @param {{lat: number, lon: number}|null} location the user's current GPS position
 */
export async function shareLocation(location) {
    if (!location) {
        Swal.fire({
            title: t('GPS inactive'),
            text: t('Activate location first'),
            icon: 'warning',
        });

        return;
    }

    const googleMapsUrl = `https://www.google.com/maps?q=${location.lat},${location.lon}`;

    if (navigator.share) {
        try {
            await navigator.share({
                title: t('My Location'),
                text: `📍 ${t('My current location')}`,
                url: googleMapsUrl,
            });

            return;
        } catch (error) {
            console.warn(error);
        }
    }

    const text = encodeURIComponent(`📍 ${t('My current location')}:\n${googleMapsUrl}`);
    window.open(`https://wa.me/?text=${text}`, '_blank');
}

/** @param {() => ({lat: number, lon: number}|null)} getLocation */
export function bindShareLocation(getLocation) {
    document.getElementById('shareLocation')?.addEventListener('click', () => shareLocation(getLocation()));
}
