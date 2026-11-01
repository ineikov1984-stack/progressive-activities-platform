import type { AssignBody } from '../substitute/office-api';
import type { ScheduleLocation } from './api';
import type { ChangeBody, ChangePreview, ChangeResult, ChangeWarning } from './changes';

export class ChangeApiError extends Error {
  constructor(message: string, public status: number, public details: { errors?: Record<string, string[]>; warnings?: ChangeWarning[]; preview?: ChangePreview; changed?: string[] } = {}) { super(message); }
}
function token(): string | undefined {
  const value = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='));
  return value ? decodeURIComponent(value.slice('XSRF-TOKEN='.length)) : undefined;
}
async function send<T>(organizationId: string, path: string, body: ChangeBody | AssignBody, key?: string, signal?: AbortSignal): Promise<T> {
  if (!token()) await fetch('/backend/sanctum/csrf-cookie', { credentials: 'include', signal });
  const csrf = token();
  const response = await fetch(`/backend/api/v1/schedule/changes${path}`, { method: 'POST', credentials: 'include', signal,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Organization-Id': organizationId,
      ...(csrf ? { 'X-XSRF-TOKEN': csrf } : {}), ...(key ? { 'Idempotency-Key': key } : {}) }, body: JSON.stringify(body) });
  const result = await response.json().catch(() => ({}));
  if (!response.ok) {
    const first = result.errors ? Object.values(result.errors as Record<string, string[]>)[0]?.[0] : result.message;
    const fallback = response.status === 403 ? 'Нямате право да променяте графика.' : response.status === 409 ? 'Графикът е променен междувременно.' : 'Промяната не се запази. Опитайте пак.';
    throw new ChangeApiError(typeof first === 'string' && /[А-Яа-я]/.test(first) ? first : fallback, response.status, result);
  }
  return result as T;
}
export function previewChange(organizationId: string, body: ChangeBody | AssignBody, signal?: AbortSignal): Promise<ChangePreview> { return send(organizationId, '/preview', body, undefined, signal); }
export function applyChange(organizationId: string, body: ChangeBody | AssignBody, key: string): Promise<ChangeResult> { return send(organizationId, '', body, key); }
export async function changeLocations(organizationId: string, signal?: AbortSignal): Promise<ScheduleLocation[]> {
  const response = await fetch('/backend/api/v1/catalog/locations', { credentials: 'include', headers: { Accept: 'application/json', 'X-Organization-Id': organizationId }, signal });
  if (!response.ok) throw new Error('Местата не се заредиха. Опитайте пак.');
  const body = await response.json() as { locations?: ScheduleLocation[] };
  return body.locations ?? [];
}
