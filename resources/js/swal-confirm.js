import { t } from './i18n.js';

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-swal-confirm]');

    if (!button) {
        return;
    }

    event.preventDefault();

    window.Swal.fire({
        title: button.dataset.swalTitle || t('Are you sure?'),
        text: button.dataset.swalText || t('This action cannot be undone.'),
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: button.dataset.swalConfirmText || t('Delete'),
        cancelButtonText: t('Cancel'),
        confirmButtonColor: '#dc3545',
        reverseButtons: true,
    }).then((result) => {
        if (!result.isConfirmed) {
            return;
        }

        const root = button.closest('[wire\\:id]');

        if (root && window.Livewire) {
            window.Livewire.find(root.getAttribute('wire:id')).call(button.dataset.swalMethod, Number(button.dataset.swalId));
        }
    });
});