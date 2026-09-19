class ApiError extends Error {
  constructor(public status: number, public body: { error?: string; fields?: Record<string, string> }) {
    super(body.error || 'API Error');
  }
}

function getCsrfToken(): string {
  const match = document.cookie.match(/csrf-token=([^;]+)/);
  return match ? match[1] : '';
}

async function fetchCsrfToken(): Promise<void> {
  const res = await fetch('/api/auth/csrf-token', { credentials: 'same-origin' });
  if (res.ok) {
    const data = await res.json();
    // Token je v cookie, stačí ho načíst
    void data;
  }
}

export const api = {
  async get<T>(path: string): Promise<T> {
    const res = await fetch(`/api${path}`, { credentials: 'same-origin' });
    if (!res.ok) throw new ApiError(res.status, await res.json());
    return res.json();
  },
  async post<T>(path: string, body?: unknown): Promise<T> {
    const res = await fetch(`/api${path}`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': getCsrfToken(),
      },
      credentials: 'same-origin',
      body: body ? JSON.stringify(body) : undefined,
    });
    if (!res.ok) throw new ApiError(res.status, await res.json());
    return res.json();
  },
  async put<T>(path: string, body?: unknown): Promise<T> {
    const res = await fetch(`/api${path}`, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': getCsrfToken(),
      },
      credentials: 'same-origin',
      body: body ? JSON.stringify(body) : undefined,
    });
    if (!res.ok) throw new ApiError(res.status, await res.json());
    return res.json();
  },
  async delete<T>(path: string, body?: unknown): Promise<T> {
    const headers: Record<string, string> = { 'X-CSRF-Token': getCsrfToken() };
    const init: RequestInit = {
      method: 'DELETE',
      headers,
      credentials: 'same-origin',
    };
    if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(body);
    }
    const res = await fetch(`/api${path}`, init);
    if (res.status === 204) return undefined as T;
    if (!res.ok) throw new ApiError(res.status, await res.json());
    return res.json();
  },
  async getBlob(path: string): Promise<Blob> {
    const res = await fetch(`/api${path}`, { credentials: 'same-origin' });
    if (!res.ok) throw new ApiError(res.status, await res.json());
    return res.blob();
  },
  async upload(path: string, formData: FormData): Promise<any> {
    const res = await fetch(`/api${path}`, {
      method: 'POST',
      headers: { 'X-CSRF-Token': getCsrfToken() },
      credentials: 'same-origin',
      body: formData,
    });
    if (!res.ok) throw new ApiError(res.status, await res.json());
    return res.json();
  },
};

export { ApiError, fetchCsrfToken };
