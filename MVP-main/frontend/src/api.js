const API_URL = (import.meta.env.VITE_API_URL || 'http://localhost:8000/api').replace(/\/$/, '');

export class ApiError extends Error {
  constructor(message, status, errors = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors;
  }
}

export async function api(path, options = {}) {
  const token = localStorage.getItem('encontrei_token');
  const headers = { Accept: 'application/json', ...(options.headers || {}) };
  const isForm = options.body instanceof FormData;
  if (options.body && !isForm && typeof options.body !== 'string') {
    headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(options.body);
  }
  if (token) headers.Authorization = `Bearer ${token}`;

  let response;
  try {
    response = await fetch(`${API_URL}${path}`, { ...options, headers });
  } catch {
    throw new ApiError('Não foi possível conectar à API. Confira se o backend está em execução.', 0);
  }
  let payload = {};
  const type = response.headers.get('content-type') || '';
  if (type.includes('application/json')) payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    const firstValidation = payload.errors ? Object.values(payload.errors).flat()[0] : null;
    throw new ApiError(firstValidation || payload.message || 'Ocorreu um erro ao processar a solicitação.', response.status, payload.errors || {});
  }
  return payload;
}

export function setSession(token, user) {
  if (token) localStorage.setItem('encontrei_token', token);
  if (user) localStorage.setItem('encontrei_user', JSON.stringify(user));
}

export function clearSession() {
  localStorage.removeItem('encontrei_token');
  localStorage.removeItem('encontrei_user');
}

export function imageUrl(path) {
  if (!path) return '';
  if (/^https?:\/\//i.test(path)) return path;
  const origin = API_URL.replace(/\/api$/, '');
  return `${origin}${path.startsWith('/storage/') ? path : `/storage/${path.replace(/^\//, '')}`}`;
}

export function friendlyError(error) {
  return error?.message || 'Ocorreu um erro inesperado.';
}
