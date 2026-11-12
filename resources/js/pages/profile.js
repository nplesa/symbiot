import { notify } from '../notify.js';

document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('avatarInput');
    const choose = document.getElementById('chooseAvatar');
    const upload = document.getElementById('uploadAvatarButton');
    const fileName = document.getElementById('avatarFileName');
    const form = document.getElementById('avatarUploadForm');
    const preview = document.getElementById('profileAvatarPreview');

    if (!input || !choose || !upload || !fileName || !form) {
        return;
    }

    choose.addEventListener('click', function () {
        input.click();
    });

    input.addEventListener('change', function () {
        const file = input.files && input.files[0];

        if (!file) {
            fileName.textContent = 'No photo selected';
            upload.disabled = true;
            return;
        }

        const allowedTypes = [
            'image/jpeg',
            'image/png',
            'image/webp'
        ];

        if (!allowedTypes.includes(file.type)) {
            notify('Format invalid. Folosește JPG, PNG sau WEBP.');
            input.value = '';
            fileName.textContent = 'No photo selected';
            upload.disabled = true;
            return;
        }

        if (file.size > 4 * 1024 * 1024) {
            notify('Imaginea nu poate depăși 4 MB.');
            input.value = '';
            fileName.textContent = 'No photo selected';
            upload.disabled = true;
            return;
        }

        fileName.textContent = file.name;
        upload.disabled = false;

        if (preview) {
            const reader = new FileReader();

            reader.onload = function (event) {
                preview.innerHTML = '';

                const img = document.createElement('img');
                img.src = event.target.result;
                img.alt = 'Profile photo preview';

                preview.appendChild(img);
            };

            reader.readAsDataURL(file);
        }
    });

    form.addEventListener('submit', function (event) {
        if (!input.files || !input.files.length) {
            event.preventDefault();
            notify('Selectează mai întâi o fotografie.');
            return;
        }

        upload.disabled = true;
        upload.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Uploading...';
    });
});
