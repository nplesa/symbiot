import {defineConfig} from 'vite';
import {glob} from "glob";
import * as fs from "node:fs";
import laravel from 'laravel-vite-plugin';

// One entry per page script/stylesheet. Modules shared by pages live outside pages/
// (e.g. resources/js/map/) so they are bundled into the page instead of becoming entries.
const pageEntries = (pattern) => glob.sync(pattern, { posix: true }).sort();

const input = [
    'resources/sass/app.scss',
    'resources/js/app.js',
    ...pageEntries('resources/sass/pages/**/*.scss'),
    ...pageEntries('resources/js/pages/**/*.js'),
];

// Local HTTPS certificate for the dev server only; `vite build` (and CI) never needs it.
const devCertificate = {
    key: 'C:/Developer/PhpWebStudy-Data/server/CA/1786422883336/CA-1786422883336.key',
    cert: 'C:/Developer/PhpWebStudy-Data/server/CA/1786422883336/CA-1786422883336.crt',
};

function devHttps(command) {
    if (command !== 'serve' || !fs.existsSync(devCertificate.key) || !fs.existsSync(devCertificate.cert)) {
        return undefined;
    }

    return {
        key: fs.readFileSync(devCertificate.key),
        cert: fs.readFileSync(devCertificate.cert),
    };
}

export default defineConfig(({ command }) => ({
    css: {
        preprocessorOptions: {
            scss: {
                api: 'modern-compiler',
                quietDeps: true,
            }
        }
    },
    build: {
        chunkSizeWarningLimit: 700,
        rollupOptions: {
            output: {
                assetFileNames: (assetInfo) => {
                    if (/\.woff2?$/.test(assetInfo.name)) {
                        return 'fonts/[name][extname]';
                    }
                    return 'assets/[name]-[hash][extname]';
                },
            },
        },
    },
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,

        origin: 'https://symbiot.local:5173',

        https: devHttps(command),

        ws: {
            protocol: 'wss',
            host: 'symbiot.local',
            clientPort: 5173,
        },

        cors: {
            origin: ['https://symbiot.local'],
        },
    },
    plugins: [
        laravel({
            input,
            refresh: true,
        }),
    ],
}));
