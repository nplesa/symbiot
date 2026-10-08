import './style.css';

import { config } from './config';

import {
  hasToken,
  login,
  logout as apiLogout,
  verifyToken,
  startTracking,
  sendLocation as apiSendLocation,
  stopTracking as apiStopTracking,
} from './api';

import { Geolocation } from '@capacitor/geolocation';

const app =
  document.querySelector<HTMLDivElement>('#app')!;

let tracking = false;

let sessionId: number | null = null;

let locationTimer: number | null = null;


/**
 * LOGIN
 */
function renderLogin(): void {
  app.innerHTML = `
    <div class="app">

      <header>
        <h1>${config.appName}</h1>
        <span class="status" id="status">
          Offline
        </span>
      </header>

      <main>

        <section class="card">

          <h2>Login</h2>

          <form id="login-form">

            <label class="setting">
              <span>Email</span>

              <input
                type="email"
                id="email"
                required
                autocomplete="username"
              />
            </label>

            <label class="setting">
              <span>Password</span>

              <input
                type="password"
                id="password"
                required
                autocomplete="current-password"
              />
            </label>

            <button
              type="submit"
              id="login-button"
            >
              LOGIN
            </button>

            <div id="login-status"></div>

          </form>

        </section>

      </main>

    </div>
  `;

  const form =
    document.querySelector<HTMLFormElement>(
      '#login-form'
    )!;

  const loginButton =
    document.querySelector<HTMLButtonElement>(
      '#login-button'
    )!;

  const loginStatus =
    document.querySelector<HTMLDivElement>(
      '#login-status'
    )!;

  form.addEventListener(
    'submit',
    async (event) => {
      event.preventDefault();

      loginButton.disabled = true;

      loginStatus.textContent =
        'Authenticating...';

      const email =
        document.querySelector<HTMLInputElement>(
          '#email'
        )!.value.trim();

      const password =
        document.querySelector<HTMLInputElement>(
          '#password'
        )!.value;

      try {

        await login(
          email,
          password
        );

        loginStatus.textContent =
          'Login successful.';

        await initializeApplication();

      } catch (error) {

        console.error(
          'Login error:',
          error
        );

        loginStatus.textContent =
          error instanceof Error
            ? error.message
            : 'Authentication failed.';

        loginButton.disabled = false;
      }
    }
  );
}


/**
 * MAIN APPLICATION
 */
function renderApplication(): void {

  app.innerHTML = `
    <div class="app">

      <header>

        <div>
          <h1>${config.appName}</h1>
        </div>

        <span
          class="status"
          id="status"
        >
          Online
        </span>

      </header>

      <main>

        <section class="card">

          <h2>Settings</h2>

          <label class="setting">

            <span>
              Run in background
            </span>

            <input
              type="checkbox"
              id="background"
              ${config.enableBackground
                ? 'checked'
                : ''}
            />

          </label>

          <label class="setting">

            <span>
              Camera
            </span>

            <input
              type="checkbox"
              id="camera"
              ${config.enableCamera
                ? 'checked'
                : ''}
            />

          </label>

          <label class="setting">

            <span>
              Microphone
            </span>

            <input
              type="checkbox"
              id="microphone"
              ${config.enableMicrophone
                ? 'checked'
                : ''}
            />

          </label>

        </section>


        <section class="card location">

          <h2>Location</h2>

          <button
            id="track"
          >
            TRACK ME
          </button>

          <div id="location-status">
            Tracking inactive
          </div>

        </section>


        <section class="card">

          <h2>Device</h2>

          <div>
            API:
            <strong>
              ${config.apiUrl}
            </strong>
          </div>

          <button
            id="logout"
          >
            LOGOUT
          </button>

        </section>

      </main>

    </div>
  `;


  const trackButton =
    document.querySelector<HTMLButtonElement>(
      '#track'
    )!;

  const logoutButton =
    document.querySelector<HTMLButtonElement>(
      '#logout'
    )!;


  trackButton.addEventListener(
    'click',
    () => {
      void handleTracking();
    }
  );


  logoutButton.addEventListener(
    'click',
    () => {
      void handleLogout();
    }
  );
}


/**
 * APPLICATION INITIALIZATION
 */
async function initializeApplication(): Promise<void> {

  if (!hasToken()) {
    renderLogin();
    return;
  }

  try {

    await verifyToken();

    renderApplication();

  } catch (error) {

    console.error(
      'Token verification failed:',
      error
    );

    renderLogin();
  }
}


/**
 * START / STOP TRACKING
 */
async function handleTracking(): Promise<void> {

  if (tracking) {
    await stopTracking();
    return;
  }


  const trackButton =
    document.querySelector<HTMLButtonElement>(
      '#track'
    )!;

  const locationStatus =
    document.querySelector<HTMLDivElement>(
      '#location-status'
    )!;

  const statusElement =
    document.querySelector<HTMLSpanElement>(
      '#status'
    )!;


  try {

    trackButton.disabled = true;

    locationStatus.textContent =
      'Requesting location permission...';


    const permissions =
      await Geolocation.requestPermissions();


    if (
      permissions.location !== 'granted' &&
      permissions.coarseLocation !== 'granted'
    ) {

      locationStatus.textContent =
        'Location permission denied.';

      trackButton.disabled = false;

      return;
    }


    locationStatus.textContent =
      'Starting tracking...';


    const uuid =
      await getDeviceUuid();


    const response =
      await startTracking(uuid);


    sessionId =
      response.data.session_id;


    if (!sessionId) {
      throw new Error(
        'Server did not return a session ID.'
      );
    }


    tracking = true;


    trackButton.textContent =
      'STOP TRACKING';

    trackButton.disabled = false;


    statusElement.textContent =
      'Tracking';


    locationStatus.textContent =
      'Getting location...';


    await sendLocation();


    startLocationTimer();

  } catch (error) {

    console.error(
      'Start tracking error:',
      error
    );


    tracking = false;

    sessionId = null;


    trackButton.textContent =
      'TRACK ME';

    trackButton.disabled = false;


    locationStatus.textContent =
      error instanceof Error
        ? error.message
        : 'Unable to start tracking.';
  }
}


/**
 * GET GPS + SEND TO LARAVEL
 */
async function sendLocation(): Promise<void> {

  if (
    !tracking ||
    sessionId === null
  ) {
    return;
  }


  try {

    const position =
      await Geolocation.getCurrentPosition({
        enableHighAccuracy: true,
        timeout: 10000,
      });


    const {
      latitude,
      longitude,
      accuracy,
      altitude,
      speed,
      heading,
    } = position.coords;


    await apiSendLocation(
      sessionId,
      {
        latitude,
        longitude,

        accuracy:
          accuracy ?? undefined,

        altitude:
          altitude ?? undefined,

        speed:
          speed ?? undefined,

        heading:
          heading ?? undefined,

        provider:
          'gps',

        tracked_at:
          new Date(
            position.timestamp
          ).toISOString(),
      }
    );


    const locationStatus =
      document.querySelector<HTMLDivElement>(
        '#location-status'
      );


    if (!locationStatus) {
      return;
    }


    locationStatus.innerHTML = `
      <strong>
        Tracking active
      </strong>

      <br>

      Latitude:
      ${latitude}

      <br>

      Longitude:
      ${longitude}

      <br>

      Accuracy:
      ${accuracy ?? '-'}m

      <br>

      Updated:
      ${new Date().toLocaleTimeString()}
    `;

  } catch (error) {

    console.error(
      'Location error:',
      error
    );


    const locationStatus =
      document.querySelector<HTMLDivElement>(
        '#location-status'
      );


    if (locationStatus) {

      locationStatus.textContent =
        error instanceof Error
          ? error.message
          : 'Unable to get/send location.';
    }
  }
}


/**
 * LOCATION TIMER
 */
function startLocationTimer(): void {

  stopLocationTimer();


  locationTimer =
    window.setInterval(
      () => {
        void sendLocation();
      },
      config.locationInterval
    );
}


/**
 * STOP LOCATION TIMER
 */
function stopLocationTimer(): void {

  if (
    locationTimer !== null
  ) {

    window.clearInterval(
      locationTimer
    );

    locationTimer = null;
  }
}


/**
 * STOP TRACKING
 */
async function stopTracking(): Promise<void> {

  const trackButton =
    document.querySelector<HTMLButtonElement>(
      '#track'
    )!;

  const locationStatus =
    document.querySelector<HTMLDivElement>(
      '#location-status'
    )!;

  const statusElement =
    document.querySelector<HTMLSpanElement>(
      '#status'
    )!;


  stopLocationTimer();


  if (sessionId !== null) {

    try {

      await apiStopTracking(
        sessionId
      );

    } catch (error) {

      console.error(
        'Stop tracking error:',
        error
      );
    }
  }


  tracking = false;

  sessionId = null;


  trackButton.textContent =
    'TRACK ME';


  statusElement.textContent =
    'Online';


  locationStatus.textContent =
    'Tracking inactive';
}


/**
 * LOGOUT
 */
async function handleLogout(): Promise<void> {

  stopLocationTimer();


  tracking = false;

  sessionId = null;


  try {

    await apiLogout();

  } catch (error) {

    console.error(
      'Logout error:',
      error
    );
  }


  renderLogin();
}


/**
 * DEVICE UUID
 *
 * Temporar folosim un UUID persistent
 * salvat local.
 *
 * Ulterior îl putem lega de identificatorul
 * persistent al dispozitivului Android.
 */
async function getDeviceUuid(): Promise<string> {

  const key =
    'symbiot_device_uuid';


  let uuid =
    localStorage.getItem(key);


  if (!uuid) {

    uuid =
      crypto.randomUUID();


    localStorage.setItem(
      key,
      uuid
    );
  }


  return uuid;
}


/**
 * START APPLICATION
 */
void initializeApplication();
