import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import { notify } from '../resources/js/notify.js';

const jsRoot = fileURLToPath(new URL('../resources/js/', import.meta.url));

function jsFiles(dir) {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) return jsFiles(path);

        return path.endsWith('.js') ? [path] : [];
    });
}

test('no blocking alert() is used anywhere in the front-end', () => {
    for (const file of jsFiles(jsRoot)) {
        assert.doesNotMatch(readFileSync(file, 'utf8'), /(?<![\w.])alert\(/, file);
    }
});

test('notify shows a self-closing toast through SweetAlert2', () => {
    let options;
    globalThis.Swal = { fire: (value) => { options = value; } };
    try {
        notify('Poziția nu este disponibilă.', 'warning');
    } finally {
        delete globalThis.Swal;
    }

    assert.equal(options.toast, true);
    assert.equal(options.title, 'Poziția nu este disponibilă.');
    assert.equal(options.icon, 'warning');
    assert.equal(options.showConfirmButton, false);
    assert.ok(options.timer > 0);
});

test('without SweetAlert2 notify logs instead of blocking with alert()', () => {
    const originalError = console.error;
    let logged;
    globalThis.alert = () => assert.fail('alert() must never be called');
    console.error = (message) => { logged = message; };
    try {
        notify('Eroare necunoscută.');
    } finally {
        console.error = originalError;
        delete globalThis.alert;
    }

    assert.equal(logged, 'Eroare necunoscută.');
});
