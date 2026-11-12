import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const jsRoot = fileURLToPath(new URL('../resources/js/', import.meta.url));

function jsFiles(dir) {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) return jsFiles(path);

        return path.endsWith('.js') ? [path] : [];
    });
}

test('no map loads tiles straight from openstreetmap.org', () => {
    for (const file of jsFiles(jsRoot)) {
        const source = readFileSync(file, 'utf8');
        assert.doesNotMatch(source, /tile\.openstreetmap\.org/, file);
        assert.doesNotMatch(source, /new OSM\(/, file);
    }
});

test('every map using the tile proxy credits OpenStreetMap and Geoapify', () => {
    const proxyUsers = jsFiles(jsRoot).filter((file) => readFileSync(file, 'utf8').includes('/map/tiles/'));

    assert.ok(proxyUsers.length >= 5, `expected the 5 maps, found ${proxyUsers.length}`);
    for (const file of proxyUsers) {
        assert.match(readFileSync(file, 'utf8'), /MAP_ATTRIBUTION/, file);
    }
});
