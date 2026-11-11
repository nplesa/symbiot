import assert from 'node:assert/strict';
import test from 'node:test';

import { safeExternalUrl } from '../resources/js/safe-url.js';

test('keeps http and https links', () => {
    assert.equal(safeExternalUrl('https://bilete.cfrcalatori.ro/ro-RO/'), 'https://bilete.cfrcalatori.ro/ro-RO/');
    assert.equal(safeExternalUrl('HTTP://Example.com/path?q=1'), 'http://example.com/path?q=1');
    assert.equal(safeExternalUrl('  https://example.com  '), 'https://example.com/');
});

test('treats a bare domain as https', () => {
    assert.equal(safeExternalUrl('www.example.ro'), 'https://www.example.ro/');
    assert.equal(safeExternalUrl('example.ro:8080/bilete'), 'https://example.ro:8080/bilete');
});

test('rejects script and other non-web protocols', () => {
    for (const value of [
        'javascript:alert(1)',
        'JavaScript:alert(document.cookie)',
        ' javascript:alert(1)',
        'java\tscript:alert(1)',
        'data:text/html,<script>alert(1)</script>',
        'vbscript:msgbox(1)',
        'file:///etc/passwd',
        'mailto:someone@example.com',
    ]) {
        assert.equal(safeExternalUrl(value), null, value);
    }
});

test('rejects empty and non-string values', () => {
    for (const value of [undefined, null, '', '   ', 42, {}]) {
        assert.equal(safeExternalUrl(value), null, String(value));
    }
});
