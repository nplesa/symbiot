import assert from 'node:assert/strict';
import test from 'node:test';

// The module reads window.SymbiotI18n once, when it is first imported.
globalThis.SymbiotI18n = {
    locale: 'ro',
    messages: {
        'Fuel stations': 'Benzinării',
        ':count subcategories': ':count subcategorii',
        'Hello :name, you have :count messages from :countries countries': 'Salut :name, ai :count mesaje din :countries țări',
    },
};
const { formatNumber, intlLocale, locale, t } = await import('../resources/js/i18n.js');

test('translates known keys and falls back to the English key', () => {
    assert.equal(t('Fuel stations'), 'Benzinării');
    assert.equal(t('Not translated yet'), 'Not translated yet');
});

test('replaces :placeholders like Laravel, longest names first', () => {
    assert.equal(t(':count subcategories', { count: 3 }), '3 subcategorii');
    assert.equal(
        t('Hello :name, you have :count messages from :countries countries', { name: 'Ana', count: 2, countries: 4 }),
        'Salut Ana, ai 2 mesaje din 4 țări'
    );
    assert.equal(t('Fallback :item', { item: 'x' }), 'Fallback x');
});

test('exposes the locale for Intl formatting', () => {
    assert.equal(locale, 'ro');
    assert.equal(intlLocale, 'ro-RO');
    assert.equal(formatNumber(1234.5), new Intl.NumberFormat('ro-RO').format(1234.5));
});
