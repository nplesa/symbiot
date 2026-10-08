import { config } from './config';

const TOKEN_KEY = 'symbiot_sanctum_token';

export interface LoginResponse {
  success: boolean;
  token: string;
  user: {
    id: number;
    name: string;
    email: string;
  };
}

export interface TrackingStatusResponse {
  success: boolean;
  data: {
    active: boolean;
    session: {
      id: number;
      started_at: string;
      ended_at: string | null;
      status: string;
    } | null;
  };
}

export interface TrackingStartResponse {
  success: boolean;
  data: {
    session_id: number;
    started_at: string;
  };
}

export interface ApiError {
  message: string;
  errors?: Record<string, string[]>;
}

function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY);
}

export function hasToken(): boolean {
  return !!getToken();
}

export function saveToken(token: string): void {
  localStorage.setItem(TOKEN_KEY, token);
}

export function clearToken(): void {
  localStorage.removeItem(TOKEN_KEY);
}

async function request<T>(
  path: string,
  options: RequestInit = {},
  authenticated = true
): Promise<T> {

  const url = `${config.apiUrl}/v1${path}`;

  const headers = new Headers(options.headers);

  headers.set('Accept', 'application/json');

  if (options.body && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json');
  }

  var token = null; 
  if (authenticated) {
    token = getToken();

    if (!token) {
      throw new Error('Not authenticated.');
    }

    headers.set(
      'Authorization',
      `Bearer ${token}`
    );
  }

  console.log('==============================');
  console.log('API REQUEST');
  console.log('URL:', url);
  console.log('TOKEN:', token);
  console.log('METHOD:', options.method ?? 'GET');
  console.log('BODY:', options.body);

  let response: Response;

  try {

    response = await fetch(
      url,
      {
        ...options,
        headers,
      }
    );

  } catch (error) {

    console.error(
      'FETCH FAILED:',
      error
    );

    throw new Error(
      `Network error while calling ${url}: ${
        error instanceof Error
          ? error.message
          : String(error)
      }`
    );
  }

  console.log(
    'HTTP STATUS:',
    response.status
  );

  let body: unknown;

  try {

    body = await response.json();

  } catch {

    body = null;
  }

  console.log(
    'API RESPONSE:',
    body
  );

  if (!response.ok) {

    const error =
      body as ApiError | null;

    if (response.status === 401) {
      clearToken();
    }

    throw new Error(
      error?.message ||
      `API request failed (${response.status})`
    );
  }

  return body as T;
}


export async function login(
  email: string,
  password: string
): Promise<LoginResponse> {
  const response = await request<LoginResponse>(
    '/login',
    {
      method: 'POST',
      body: JSON.stringify({
        email,
        password,
      }),
    },
    false
  );

  saveToken(response.token);

  return response;
}

export async function logout(): Promise<void> {
  try {
    await request(
      '/logout',
      {
        method: 'POST',
      }
    );
  } finally {
    clearToken();
  }
}

export async function verifyToken(): Promise<TrackingStatusResponse> {
  return request<TrackingStatusResponse>(
    '/tracking/status'
  );
}

export async function startTracking(
  uuid: string
): Promise<TrackingStartResponse> {
  return request<TrackingStartResponse>(
    '/tracking/start',
    {
      method: 'POST',
      body: JSON.stringify({
        uuid,
      }),
    }
  );
}

export async function sendLocation(
  sessionId: number,
  data: {
    latitude: number;
    longitude: number;
    accuracy?: number;
    speed?: number;
    heading?: number;
    altitude?: number;
    battery?: number;
    provider?: string;
    tracked_at: string;
  }
) {
  return request(
    '/tracking/location',
    {
      method: 'POST',
      body: JSON.stringify({
        session_id: sessionId,
        ...data,
      }),
    }
  );
}

export async function stopTracking(
  sessionId: number
) {
  return request(
    '/tracking/stop',
    {
      method: 'POST',
      body: JSON.stringify({
        session_id: sessionId,
      }),
    }
  );
}
