/**
 * Shared mutable state of the map page (pages/map.js and the modules split from it, point 71).
 * One object instead of ~30 module-level variables, so a module can read or change the radius,
 * the location or the POI request in progress without the page passing each value around.
 */
export const mapState = {
    // GPS tracking ("Track me").
    tracking: false,
    watchId: null,
    lastPosition: null,
    // Direction of travel in radians, clockwise from north; null until the user actually moves.
    heading: null,
    lastSent: 0,

    // Features drawn on the map: the user, the travelled track, the search circle.
    userFeature: null,
    trackFeature: null,
    radiusFeature: null,

    // Location and search area (metres). app_radius is set from #radius when the page starts.
    currentUserLocation: null,
    app_radius: 1000,
    cityRadiusMeters: 5000,
    fixedCityLocation: null,
    cityBoundaryFeature: null,
    cityBoundaryRadiusMeters: null,
    citywideSearch: false,
    pendingCityResults: [],

    // POI loading.
    poiRequestId: 0,
    // Aborts the HTTP requests of a superseded loadNearby() instead of only ignoring their results.
    poiLoadController: null,
    // Rapid filter toggling waits for a short pause and then loads all changes at once.
    poiLoadDebounce: null,
    poiResultCache: { key: null, tokens: new Set(), data: [] },

    // Requests of the POI tooltip and the transit lines; a newer one makes older answers obsolete.
    busStopLookupRun: 0,
    subwayStopRoutesRun: 0,
    metroScheduleRun: 0,
    activeMetroStationFeature: null,
    transitRouteGeometryRun: 0,

    // Subcategory availability: the area last answered and the one being asked.
    subcategoriesKey: null,
    subcategoriesPendingKey: null,
};

// Transit lines drawn on the map, shared by the page and the POI tooltip (keys: route group keys).
export const visibleTransitRoutes = new Set();
export const activeTransitRoutes = new Map();
export const transitRouteColors = new Map();
export const manuallySelectedTransitRouteColors = new Set();

// The POI categories ticked in the right panel (category types), shared by the layers and the loading.
export const activePoiFilters = new Set();
