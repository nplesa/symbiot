import { notify } from '../notify.js';
import { t } from '../i18n.js';

/** "Current password" is asked only to change the e-mail: shown while the e-mail differs from the account's. */
function bindEmailPassword() {
    const email = document.getElementById('email');
    const field = document.getElementById('profile-current-password-field');
    if (!email || !field) return;

    const current = (email.dataset.currentEmail ?? '').trim().toLowerCase();
    const sync = () => {
        // After a failed attempt the field still holds the new e-mail, so the password stays shown.
        const changed = email.value.trim().toLowerCase() !== current;
        field.hidden = !changed;
        if (!changed) {
            const input = field.querySelector('input');
            if (input) input.value = '';
        }
    };
    email.addEventListener('input', sync);
    sync();
}

document.addEventListener('DOMContentLoaded', bindEmailPassword);

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
            fileName.textContent = t('No photo selected');
            upload.disabled = true;
            return;
        }

        const allowedTypes = [
            'image/jpeg',
            'image/png',
            'image/webp'
        ];

        if (!allowedTypes.includes(file.type)) {
            notify(t('Invalid format. Use JPG, PNG or WEBP.'));
            input.value = '';
            fileName.textContent = t('No photo selected');
            upload.disabled = true;
            return;
        }

        if (file.size > 4 * 1024 * 1024) {
            notify(t('The image cannot exceed 4 MB.'));
            input.value = '';
            fileName.textContent = t('No photo selected');
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
                img.alt = t('Profile photo preview');

                preview.appendChild(img);
            };

            reader.readAsDataURL(file);
        }
    });

    form.addEventListener('submit', function (event) {
        if (!input.files || !input.files.length) {
            event.preventDefault();
            notify(t('Select a photo first.'));
            return;
        }

        upload.disabled = true;
        upload.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2" aria-hidden="true"></i>' + t('Uploading...');
    });
});
