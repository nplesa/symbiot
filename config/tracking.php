<?php

return [
    'point_max_age_hours' => (int) env('TRACKING_POINT_MAX_AGE_HOURS', 48),
    'point_max_future_minutes' => (int) env('TRACKING_POINT_MAX_FUTURE_MINUTES', 5),
    // Imported or drawn routes keep every point: a longer one is refused, never thinned out.
    'planned_route_max_coordinates' => (int) env('TRACKING_PLANNED_ROUTE_MAX_COORDINATES', 50000),
    'planned_routes_max_per_user' => (int) env('TRACKING_PLANNED_ROUTES_MAX_PER_USER', 200),
    'web_session_list_max' => (int) env('TRACKING_WEB_SESSION_LIST_MAX', 500),
    'web_points_max' => (int) env('TRACKING_WEB_POINTS_MAX', 10000),
];
