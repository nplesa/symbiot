/** Drag and resize of the transit lines modal (.transit-modal-drag-handle / .transit-modal-resize-handle). */

export function enableTransitModalDragging(modalElement) {
    if (modalElement.dataset.interactionsEnabled === 'true') return;

    const dragHandle = modalElement.querySelector('.transit-modal-drag-handle');
    const resizeHandle = modalElement.querySelector('.transit-modal-resize-handle');
    const dialog = modalElement.querySelector('.modal-dialog');
    const content = modalElement.querySelector('.modal-content');
    if (!dragHandle || !resizeHandle || !dialog || !content) {
        console.error('The transit modal is missing its drag or resize controls.');
        return;
    }

    modalElement.dataset.interactionsEnabled = 'true';
    let dragState = null;
    let resizeState = null;

    dragHandle.addEventListener('pointerdown', event => {
        if (!event.isPrimary || event.button !== 0 || event.target.closest('button, a, input, select, textarea')) {
            return;
        }

        const rect = dialog.getBoundingClientRect();
        dialog.style.position = 'fixed';
        dialog.style.left = `${rect.left}px`;
        dialog.style.top = `${rect.top}px`;
        dialog.style.width = `${rect.width}px`;
        dialog.style.margin = '0';
        dialog.style.transform = 'none';
        dragState = {
            pointerId: event.pointerId,
            startX: event.clientX,
            startY: event.clientY,
            startLeft: rect.left,
            startTop: rect.top,
        };
        dragHandle.setPointerCapture(event.pointerId);
        event.preventDefault();
    });

    const moveDialog = event => {
        if (!dragState || event.pointerId !== dragState.pointerId) return;

        const left = dragState.startLeft + event.clientX - dragState.startX;
        const top = dragState.startTop + event.clientY - dragState.startY;
        dialog.style.left = `${left}px`;
        dialog.style.top = `${top}px`;
        event.preventDefault();
    };

    const stopDragging = event => {
        if (dragState?.pointerId === event.pointerId) dragState = null;
        if (resizeState?.pointerId === event.pointerId) resizeState = null;
    };
    document.addEventListener('pointermove', moveDialog, { passive: false });
    document.addEventListener('pointerup', stopDragging);
    document.addEventListener('pointercancel', stopDragging);

    const minHeight = 180;
    const getMaxHeight = () => Math.max(minHeight, window.innerHeight - 16);
    const setHeight = height => {
        const nextHeight = Math.min(Math.max(height, minHeight), getMaxHeight());
        content.style.height = `${nextHeight}px`;
        resizeHandle.setAttribute('aria-valuenow', String(Math.round(nextHeight)));
        resizeHandle.setAttribute('aria-valuemin', String(minHeight));
        resizeHandle.setAttribute('aria-valuemax', String(Math.round(getMaxHeight())));
    };

    resizeHandle.addEventListener('pointerdown', event => {
        if (!event.isPrimary || event.button !== 0) return;
        resizeState = {
            pointerId: event.pointerId,
            startY: event.clientY,
            startHeight: content.getBoundingClientRect().height,
        };
        resizeHandle.setPointerCapture(event.pointerId);
        event.preventDefault();
    });

    document.addEventListener('pointermove', event => {
        if (!resizeState || event.pointerId !== resizeState.pointerId) return;
        setHeight(resizeState.startHeight + event.clientY - resizeState.startY);
        event.preventDefault();
    }, { passive: false });

    resizeHandle.addEventListener('keydown', event => {
        if (!['ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        const currentHeight = content.getBoundingClientRect().height;
        if (event.key === 'Home') {
            setHeight(minHeight);
        } else if (event.key === 'End') {
            setHeight(getMaxHeight());
        } else {
            setHeight(currentHeight + (event.key === 'ArrowDown' ? 24 : -24));
        }
    });
}
