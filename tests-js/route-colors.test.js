import test from 'node:test';
import assert from 'node:assert/strict';
import { contrastRatio, isVeryLight, lineBadgeColors, normalizeHex } from '../resources/js/route-colors.js';

test('feed colours are normalised; invalid ones are dropped', () => {
    assert.equal(normalizeHex('FFD400'), '#ffd400');
    assert.equal(normalizeHex('#023C9F'), '#023c9f');
    assert.equal(normalizeHex(''), null);
    assert.equal(normalizeHex(null), null);
    assert.equal(normalizeHex('red'), null);
});

test('a white line without a text colour gets black text and the light outline', () => {
    assert.deepEqual(lineBadgeColors('FFFFFF', null), { background: '#ffffff', text: '#000000', light: true });
});

test('a white line whose feed text colour is also white gets black text', () => {
    assert.equal(lineBadgeColors('FFFFFF', 'FFFFFF').text, '#000000');
});

test('the feed text colour is kept when it reads well', () => {
    assert.deepEqual(lineBadgeColors('023C9F', 'FFFFFF'), { background: '#023c9f', text: '#ffffff', light: false });
    assert.equal(lineBadgeColors('FFD400', '000000').text, '#000000');
});

test('without a text colour, dark lines get white text and light lines black text', () => {
    assert.equal(lineBadgeColors('764212', null).text, '#ffffff');
    assert.equal(lineBadgeColors('FFD400', null).text, '#000000');
});

test('a line without a colour takes the fallback', () => {
    assert.deepEqual(lineBadgeColors(null, null, '#1769e0'), { background: '#1769e0', text: '#ffffff', light: false });
});

test('very light colours are recognised; the contrast ratio follows WCAG', () => {
    assert.equal(isVeryLight('#ffffff'), true);
    assert.equal(isVeryLight('#fffbe6'), true);
    assert.equal(isVeryLight('#ffd400'), false);
    assert.equal(isVeryLight('#023c9f'), false);
    assert.equal(Math.round(contrastRatio('#000000', '#ffffff')), 21);
    assert.equal(contrastRatio('#777777', '#777777'), 1);
});
