<?php

/*
 * Routes published to the browser by @routes (layouts/app.blade.php), which also renders
 * on the guest pages (login, register). Allow-list only: a new route stays private until
 * it is added here. Admin, impersonation (ghost), Livewire, storage, Sanctum and password
 * reset routes are deliberately left out.
 */
return [
    'only' => [
        // Pages
        'app.home',
        'app.position',
        'app.trackings.index',
        'app.tracking.show',
        'app.trasee.index',
        'app.navigation.show',
        'app.profile.edit',
        'lang.switch',
        'login',
        'logout',
        'register',
        'pending',

        // JSON endpoints called by the page scripts
        'app.api.*',
        'app.navigation.pois',
        'app.navigation.traffic',
        'app.tracking.*',
        'app.user.location.toggle',
        'app.user.tracking.toggle',
    ],
];
