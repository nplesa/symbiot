import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

test('every page gets the shell, but not the GPS tracking / map dashboard bundle', () => {
    const app = read('resources/js/app.js');

    assert.match(app, /import '\.\/shell\.js';/);
    assert.doesNotMatch(app, /^\s*import\s.*pages\/dashboard\.js/m);
});

test('sidebar and theme live in shell.js only', () => {
    assert.match(read('resources/js/shell.js'), /themeToggle/);
    assert.doesNotMatch(read('resources/js/pages/dashboard.js'), /themeToggle/);
});

test('the home page still loads the dashboard bundle itself', () => {
    assert.match(read('resources/views/home.blade.php'), /resources\/js\/pages\/dashboard\.js/);
});
