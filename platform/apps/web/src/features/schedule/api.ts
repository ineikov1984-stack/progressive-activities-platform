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
  /** Always false until "Премести" (PR 4). */
  moved: boolean;
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
/** Name and class only — never contacts, health or money. */
export type SessionChild = {
  enrollment_id: string;
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
};

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

async function request<T>(organizationId: string, path: string): Promise<T> {
  const response = await fetch(`/backend/api/v1/schedule${path}`, {
    credentials: 'include',
    headers: { Accept: 'application/json', 'X-Organization-Id': organizationId },
  });
  if (!response.ok) {
    const body = await response.json().catch(() => ({})) as { message?: unknown; errors?: Record<string, string[]> };
    throw new ScheduleApiError(scheduleErrorMessage(response.status, body), response.status);
  }
  return response.json() as Promise<T>;
}

/** `query` — from `scheduleQuery`: `from`, `to` (Sofia's days, both included) and the filters. */
export async function loadSchedule(organizationId: string, query: string): Promise<ScheduleBoard> {
  const board = await request<Partial<ScheduleBoard>>(organizationId, `?${query}`);
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
  return request<SessionPanel>(organizationId, `/sessions/${encodeURIComponent(sessionId)}`);
}
