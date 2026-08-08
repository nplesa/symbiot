<?php

return [
    'point_max_age_hours' => (int) env('TRACKING_POINT_MAX_AGE_HOURS', 48),
    'point_max_future_minutes' => (int) env('TRACKING_POINT_MAX_FUTURE_MINUTES', 5),
];
