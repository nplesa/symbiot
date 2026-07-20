import {defineConfig} from 'vite';
import { fileURLToPath } from "node:url";
import * as path from "path";
import {glob} from "glob";
import * as fs from "node:fs";
import laravel from 'laravel-vite-plugin';


let sass = Object.fromEntries(
    glob.sync("resources/sass/pages/**/*.scss")
        .map((file) => [
            path.relative(
                "resources/sass/pages",
                file.slice(0, file.length - path.extname(file).length)
            ),
            fileURLToPath(new URL(file, import.meta.url)),
        ])
);
sass = Object.values(sass);

let js = Object.fromEntries(
    glob.sync("resources/js/pages/**/*.js")
        .map((file) => [
            path.relative(
                "resources/js/pages",
                file.slice(0, file.length - path.extname(file).length)
            ),
            fileURLToPath(new URL(file, import.meta.url)),
        ])
);
js = Object.values(js);

let n4m = Object.fromEntries(
    glob.sync("resources/js/vendor/n4m/**/*.js")
        .map((file) => [
            path.relative(
                "resources/js/vendor/n4m",
                file.slice(0, file.length - path.extname(file).length)
            ),
            fileURLToPath(new URL(file, import.meta.url)),
        ])
);
n4m = Object.values(n4m);

let input = [
    'resources/sass/app.scss',
    'resources/js/app.js',
    ...sass,
    ...js,
    ...n4m
];


export default defineConfig({
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
        origin: 'https://symbiot.npsoft.ro:5173',
        host: "0.0.0.0",
        port: 5173,
        https: {
            key: fs.readFileSync("/etc/letsencrypt/live/symbiot.npsoft.ro/privkey.pem"),
            cert: fs.readFileSync("/etc/letsencrypt/live/symbiot.npsoft.ro/fullchain.pem"),
        },
        cors: true,
    },
    plugins: [
/*
         obfuscatorPlugin({
            apply: "build",
            options: {
                compact: true,
                numbersToExpressions: true,


                simplify: true,
                deadCodeInjection: true,
                selfDefending: true,
                debugProtection: true,
            },
        }),
*/
        laravel({
            input,
            refresh: true,
        }),
    ],
});
