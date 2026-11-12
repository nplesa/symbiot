/**
 * Non-blocking user message (SweetAlert2 toast), used instead of window.alert(),
 * which freezes the whole tab - map, GPS watch and timers - until it is dismissed.
 *
 * @param {string} message
 * @param {'error'|'warning'|'info'|'success'} icon
 */
export function notify(message, icon = 'error') {
    const swal = globalThis.Swal;
    if (!swal?.fire) {
        // Never fall back to a blocking browser dialog: a missing toast must not freeze the page.
        (icon === 'error' ? console.error : console.warn)(message);
        return;
    }

    swal.fire({
        toast: true,
        position: 'top-end',
        icon,
        title: message,
        showConfirmButton: false,
        timer: 4000,
        timerProgressBar: true,
    });
}
