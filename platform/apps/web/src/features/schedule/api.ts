/*
 * "График" (docs/product/schedule.md, т. 6): the sessions a person of the team sees and the panel of one. Read only;
 * the organization goes in `X-Organization-Id`, as in "Екип" and "Записвания". The shape is the API's
 * (`SchedulePresenter`): times in UTC, the names in the dictionaries, the children only where they may be seen.
 */

/** `lead` and `assistant` from the assignment of the day; `substitute` stands in for `replaces`. */
export type ScheduleInstructor = {
  profile_id: string;
  name: string;
  role: 'lead' | 'assistant' | 'substitute' | string;
  replaces?: { profile_id: string; name: string }[];
};
export type ScheduleSession = {
  id: string;
  /** ISO, UTC. */
  starts_at: string;
  ends_at: string;
  status: 'scheduled' | 'cancelled' | string;
  /** Whether the session has moved from its original start. */
  moved: boolean;
  version?: number;
  moved_from?: string | null;
  extra?: boolean;
  group_id: string;
  activity_id: string | null;
  location_id: string | null;
  instructors: ScheduleInstructor[];
  capacity: number | null;
  taken: number;
  /** Waiting for a place in the group — the same for each of its sessions. */
  waiting: number;
};
/** `color` — 0–4, by the order of the activities, again from the sixth (т. 3.6). */
export type ScheduleActivity = { id: string; title: string; color: number };
export type ScheduleGroup = { id: string; name: string; activity_id: string; age_label: string | null };
export type ScheduleLocation = { id: string; name: string };
export type SchedulePerson = { id: string; name: string };
/** For each group: whether its children may be seen, and whether the schedule may be changed (PR 4). */
export type GroupAbilities = { children: boolean; manage: boolean };
export type ScheduleBoard = {
  sessions: ScheduleSession[];
  activities: ScheduleActivity[];
  groups: ScheduleGroup[];
  locations: ScheduleLocation[];
  instructors: SchedulePerson[];
  abilities: Record<string, GroupAbilities>;
};
/** Name and class only — never contacts, health or money. `child_id` opens the card of the child (т. 4.1). */
export type SessionChild = {
  enrollment_id: string;
  child_id?: string;
  name: string;
  class: string | null;
  state: 'active' | 'paused' | 'skipped' | string;
  scope: 'full_period' | 'trial' | 'single' | string;
};
export type SessionPanel = {
  /** `change_reason` only for who changes the schedule; `children` only where they may be seen. */
  session: ScheduleSession & { change_reason?: string | null; children?: SessionChild[] };
  group: { id: string; name: string; age_label: string | null };
  activity: { id: string; title: string };
  location: ScheduleLocation | null;
  abilities: GroupAbilities;
  actions?: ('move' | 'cancel' | 'restore')[];
  last_change?: { action: string; at: string; by?: string; reason?: string | null } | null;
};

/** A child found by the filter "Дете": name and class only — never contacts, health, money or groups. */
export type FoundChild = { id: string; name: string; class: string | null };

/** What the person may do in a group of the child: show the parent's phone, skip a session, pause (т. 4.1). */
export type ChildCardAbilities = { contacts: boolean; skip: boolean; pause: boolean };
export type ChildCardGroup = {
  attendance?: import('../attendance/api').ChildAttendance | null;
  enrollment_id: string;
  group_id: string;
  activity: string | null;
  group: string | null;
  /** For "Пропусни": a change is refused on an older version. */
  version: number;
  state: 'active' | 'paused' | 'trial' | 'single' | 'ended' | string;
  paused_until: string | null;
  ended_on: string | null;
  abilities: ChildCardAbilities;
};
/**
 * The card of a child (`GET /enrollment/children/{child}`, т. 4.1): name, class, the age in full years and the groups
 * the person sees — never the birth date, contacts, money or health.
 */
export type ChildCard = { id: string; name: string; class: string | null; age: number | null; groups: ChildCardGroup[] };

export class ScheduleApiError extends Error {
  constructor(message: string, public status: number) { super(message); }
}

const cyrillic = /[А-Яа-я]/;

/** A message for the person, in Bulgarian; the API's own text when it has one (e.g. "Изберете период до 42 дни."). */
export function scheduleErrorMessage(status: number, body: { message?: unknown; errors?: Record<string, string[]> }): string {
  const first = body.errors ? Object.values(body.errors)[0]?.[0] : undefined;
  if (status === 422 && typeof first === 'string' && cyrillic.test(first)) return first;
  if (status === 401) return 'Влезте отново, за да продължите.';
  if (status === 403) return 'Нямате достъп до това в графика.';
  if (status === 404) return 'Заниманието не е намерено. Може да е преместено или да не е във вашия график.';
  if (status === 429) return 'Твърде много заявки. Изчакайте минута и опитайте пак.';
  return 'Графикът не се зареди. Проверете връзката и опитайте пак.';
}

/** The search of the filter "Дете" (т. 6.5): the API's own words for a wrong text ("Напишете поне 2 букви от името."). */
export function childSearchErrorMessage(status: number, body: { message?: unknown; errors?: Record<string, string[]> }): string {
  const first = body.errors ? Object.values(body.errors)[0]?.[0] : undefined;
  if (status === 422 && typeof first === 'string' && cyrillic.test(first)) return first;
  if (status === 401) return 'Влезте отново, за да продължите.';
  if (status === 403) return 'Нямате достъп до децата в графика.';
  if (status === 429) return 'Търсихте много пъти за кратко. Изчакайте минута и опитайте пак.';
  return 'Търсенето не стана. Проверете връзката и опитайте пак.';
}

type ErrorBody = { message?: unknown; errors?: Record<string, string[]> };

/** `path` — under `/api/v1`; the message for a failure from `messageOf`. */
async function request<T>(organizationId: string, path: string, messageOf: (status: number, body: ErrorBody) => string = scheduleErrorMessage, signal?: AbortSignal): Promise<T> {
  const response = await fetch(`/backend/api/v1${path}`, {
    credentials: 'include',
    headers: { Accept: 'application/json', 'X-Organization-Id': organizationId },
    ...(signal ? { signal } : {}),
  });
  if (!response.ok) {
    const body = await response.json().catch(() => ({})) as ErrorBody;
    throw new ScheduleApiError(messageOf(response.status, body), response.status);
  }
  return response.json() as Promise<T>;
}

/** `query` — from `scheduleQuery`: `from`, `to` (Sofia's days, both included) and the filters. */
export async function loadSchedule(organizationId: string, query: string): Promise<ScheduleBoard> {
  const board = await request<Partial<ScheduleBoard>>(organizationId, `/schedule?${query}`);
  return {
    sessions: board.sessions ?? [],
    activities: board.activities ?? [],
    groups: board.groups ?? [],
    locations: board.locations ?? [],
    instructors: board.instructors ?? [],
    // An object by group, also when there is none.
    abilities: board.abilities && !Array.isArray(board.abilities) ? board.abilities : {},
  };
}

export function loadSessionPanel(organizationId: string, sessionId: string): Promise<SessionPanel> {
  return request<SessionPanel>(organizationId, `/schedule/sessions/${encodeURIComponent(sessionId)}`);
}

/** A message for the card of a child: 404 also when the person does not see the child (т. 4.1). */
export function childCardErrorMessage(status: number): string {
  if (status === 401) return 'Влезте отново, за да продължите.';
  if (status === 404 || status === 403) return 'Картата на детето не е достъпна — детето не е в групите, в които виждате децата.';
  if (status === 429) return 'Твърде много заявки. Изчакайте минута и опитайте пак.';
  return 'Картата не се зареди. Проверете връзката и опитайте пак.';
}

export async function loadChildCard(organizationId: string, childId: string, month?: string): Promise<ChildCard> {
  const card = await request<Partial<ChildCard>>(organizationId, `/enrollment/children/${encodeURIComponent(childId)}${month ? `?month=${encodeURIComponent(month)}` : ''}`, (status) => childCardErrorMessage(status));
  return {
    id: card.id ?? childId,
    name: card.name ?? '',
    class: card.class ?? null,
    age: typeof card.age === 'number' ? card.age : null,
    groups: Array.isArray(card.groups) ? card.groups : [],
  };
}

/**
 * The enrolled children by name, for the filter "Дете" (т. 3.5, 6.5): up to 20, only in the groups where the person
 * sees the enrollments. `id` is what `child=` takes. `text` — from `childSearchText`.
 */
export async function searchChildren(organizationId: string, text: string, signal?: AbortSignal): Promise<FoundChild[]> {
  const body = await request<{ children?: FoundChild[] }>(organizationId, `/enrollment/children?${new URLSearchParams({ q: text }).toString()}`, childSearchErrorMessage, signal);
  return Array.isArray(body.children) ? body.children : [];
}
