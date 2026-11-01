export type Mark = 'present' | 'absent' | null;
export type Counts = { expected: number; present: number; absent: number; warned: number; unmarked: number; trial: number; single: number; paused: number; present_paused: number };
export type AttendanceChild = { enrollment_id: string; child_id?: string; name: string; class: string | null; scope: string; state: string; expected: boolean; status: Mark; warned: boolean; came_while_paused: boolean; version: number; marked_by: string | null; marked_at: string | null; paused_until: string | null; skip: { by: string; by_name?: string | null; at: string } | null; late_cancelled: boolean };
export type History = { id: string; at: string; by: string | null; enrollment_id?: string; from?: Mark; to?: Mark; kind: string; reason?: string | null };
export type Sheet = { session: { id: string; starts_at: string; ends_at: string; status: string; extra: boolean; moved_from: string | null; topic: string | null; not_held: { at: string; by?: string | null; reason?: string | null } | null; group: { id: string; name: string; age_label: string | null }; activity: { id: string; title: string }; location: { id: string; name: string } | null }; abilities: { mark: boolean; reason_required: boolean; opens_at: string; deadline: string; reasons: boolean; not_held: boolean; held: boolean }; counts: Counts; last_marked_at: string | null; children: AttendanceChild[]; history: History[] };
export type Applied = { enrollment_id: string; from: Mark; to: Mark; version: number };
export type Answer = Sheet & { applied: Applied[] };
export type Change = { action: 'mark'; marks: { enrollment_id: string; status: Mark; version: number }[]; reason?: string } | { action: 'all_present'; reason?: string } | { action: 'topic'; topic: string | null };
export type TodaySession = { id: string; starts_at: string; ends_at: string; status: string; state: string; activity: string; group: string; age_label: string | null; location: string | null; my_role: string; leads: string[]; replaces: string[]; counts: Counts; last_marked_at: string | null; can_mark: boolean; opens_at: string; deadline: string };
export type Today = { now: string; sessions: TodaySession[]; pending: TodaySession[] };
export type ChildAttendance = { month: string; present: number; absent: number; percent: number | null; sessions: { session_id: string; starts_at: string; state: string }[] };
export class AttendanceError extends Error {
  constructor(message: string, public status: number, public body: Partial<Answer> & { retry?: boolean; conflicts?: string[] } = {}) { super(message); }
}
function token(): string | undefined { return typeof document === 'undefined' ? undefined : document.cookie.split('; ').find((s) => s.startsWith('XSRF-TOKEN='))?.slice(11); }
async function request<T>(org: string, path: string, body?: Change, key?: string, signal?: AbortSignal): Promise<T> {
  if (body && !token()) await fetch('/backend/sanctum/csrf-cookie', { credentials: 'include', signal });
  const csrf = token();
  const response = await fetch(`/backend/api/v1/attendance${path}`, { method: body ? 'POST' : 'GET', credentials: 'include', cache: 'no-store', signal,
    headers: { Accept: 'application/json', 'X-Organization-Id': org, ...(body ? { 'Content-Type': 'application/json' } : {}), ...(csrf ? { 'X-XSRF-TOKEN': decodeURIComponent(csrf) } : {}), ...(key ? { 'Idempotency-Key': key } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) { const message = Object.values(data.errors ?? {}).flat()[0] ?? data.message; throw new AttendanceError(typeof message === 'string' && /[а-яА-Я]/.test(message) ? message : response.status === 401 ? 'Влезте отново, за да продължите.' : 'Присъствията не се заредиха. Опитайте пак.', response.status, data); }
  return data as T;
}
export const loadToday = (org: string, signal?: AbortSignal) => request<Today>(org, '/today', undefined, undefined, signal);
export const loadSheet = (org: string, session: string, signal?: AbortSignal) => request<Sheet>(org, `/sessions/${encodeURIComponent(session)}`, undefined, undefined, signal);
export const saveAttendance = (org: string, session: string, body: Change, key: string, signal?: AbortSignal) => request<Answer>(org, `/sessions/${encodeURIComponent(session)}/changes`, body, key, signal);

export type Month = { month: string; group: {id:string;label:string}; groups:{id:string;label:string}[]; sessions:{id:string;starts_at:string;topic:string|null;extra:boolean;present:number|null;expected:number|null}[]; rows:{child_id?:string;name:string;kind:string;cells:Record<string,{state:string;enrollment_id:string}>;present:number;absent:number;percent:number|null}[] };
export const loadMonth = (org: string, query: string, signal?: AbortSignal) => request<Month>(org, `/month${query ? `?${query}` : ''}`, undefined, undefined, signal);
