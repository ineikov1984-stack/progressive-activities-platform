export type Person = { profile_id: string; name: string };
export type Session = { session_id: string; starts_at: string; ends_at: string; activity: string; group: string; age_label: string | null; location: string | null };
export type RequestSession = Session & { state: 'open' | 'assigned' | 'declined' | 'withdrawn' | 'expired'; closed_reason: string | null; substitute: Person | null; decided_by: { id: string; name: string } | null; decided_at: string | null; now: { teaching: boolean; substitute: Person | null } };
export type SubstituteRequest = { id: string; version: number; created_at: string; reason: Reason; note: string | null; suggested: Person | null; sessions: RequestSession[] };
export type StandingIn = Session & { replaces: { name: string }; until: string; children_now: boolean };
export type Mine = { requests: SubstituteRequest[]; standing_in: StandingIn[]; email_confirmed: boolean; now: string };
export type OptionSession = Omit<Session, 'session_id' | 'age_label'> & { id: string; version: number; activity_id: string; role: string; requested: boolean };
export type Options = { sessions: OptionSession[]; colleagues: (Person & { groups: string[] })[]; now: string };
export type Reason = 'vacation' | 'personal' | 'other';
export type SubmitBody = { sessions: { id: string; version: number }[]; reason: Reason; note: string | null; suggested_profile_id: string | null };
export class SubstituteError extends Error {
  constructor(message: string, public status: number, public body: { request?: SubstituteRequest; errors?: Record<string, string[]> } = {}) { super(message); }
}
function token() { return typeof document === 'undefined' ? undefined : document.cookie.split('; ').find(s => s.startsWith('XSRF-TOKEN='))?.slice(11); }
async function request<T>(org: string, path: string, body?: SubmitBody | { version: number }, key?: string, signal?: AbortSignal): Promise<T> {
  if (body && !token()) await fetch('/backend/sanctum/csrf-cookie', { credentials: 'include', signal });
  const csrf = token();
  const response = await fetch(`/backend/api/v1/me/substitute-requests${path}`, { method: body ? 'POST' : 'GET', credentials: 'include', cache: 'no-store', signal,
    headers: { Accept: 'application/json', 'X-Organization-Id': org, ...(body ? { 'Content-Type': 'application/json' } : {}), ...(csrf ? { 'X-XSRF-TOKEN': decodeURIComponent(csrf) } : {}), ...(key ? { 'Idempotency-Key': key } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    const message = Object.values(data.errors ?? {}).flat()[0] ?? data.message;
    throw new SubstituteError(typeof message === 'string' && /[а-яА-Я]/.test(message) ? message : response.status === 401 ? 'Влезте отново, за да продължите.' : response.status === 429 ? 'Изчакайте минута и опитайте пак.' : 'Заявката не се изпълни. Проверете връзката и опитайте пак.', response.status, data);
  }
  return data as T;
}
export async function loadMine(org: string, signal?: AbortSignal): Promise<Mine> {
  const data = await request<Mine>(org, '', undefined, undefined, signal);
  return { ...data, requests: Array.isArray(data.requests) ? data.requests : [], standing_in: Array.isArray(data.standing_in) ? data.standing_in : [] };
}
export const loadOptions = (org: string, from: string, to: string, signal?: AbortSignal) => request<Options>(org, `/options?${new URLSearchParams({ from, to })}`, undefined, undefined, signal);
export const submitRequest = (org: string, body: SubmitBody, key: string) => request<SubstituteRequest>(org, '', body, key);
export const withdrawRequest = (org: string, id: string, version: number) => request<SubstituteRequest>(org, `/${encodeURIComponent(id)}/withdraw`, { version });
