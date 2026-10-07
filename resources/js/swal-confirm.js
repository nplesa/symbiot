document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-swal-confirm]');

    if (!button) {
        return;
    }

    event.preventDefault();

    window.Swal.fire({
        title: button.dataset.swalTitle || 'Ești sigur?',
        text: button.dataset.swalText || 'Această acțiune nu poate fi anulată.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: button.dataset.swalConfirmText || 'Șterge',
        cancelButtonText: 'Anulează',
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