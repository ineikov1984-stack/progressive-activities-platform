import { catalogErrorMessage } from '../catalog/admin/api';

export type StaffChild = { id: string | null; name: string | null; class_label: string | null };
export type StaffApplication = {
  id: string;
  status: 'submitted' | 'waitlisted' | 'approved' | 'rejected' | string;
  version: number;
  scope: 'trial' | 'single' | 'full_period' | string;
  source: string;
  child: StaffChild;
  group: { id: string; name: string | null };
  activity: { id: string | null; title: string | null };
  session_starts_at: string | null;
  visit_offer_title: string | null;
  price: { amount_cents: number; currency: string; unit: string };
  public_reason: string | null;
  submitted_at: string | null;
};
export type PauseReason = 'health' | 'holiday' | 'family' | 'other';
export type RosterPause = { id: string; starts_on: string; ends_on: string; reason_category: PauseReason | string };
export type RosterEnrollment = {
  id: string;
  child: StaffChild;
  scope: string;
  status: 'active' | 'paused' | 'ended' | string;
  starts_on: string;
  ends_on: string | null;
  version: number;
  transferred_from_id?: string | null;
  moves_to?: { group_id: string; group_name: string | null; starts_on: string } | null;
  paused_today?: boolean;
  pauses?: RosterPause[];
  skipped_sessions?: { session_id: string; starts_at: string | null }[];
};
export type GroupRoster = {
  group: { id: string; name: string; activity_id: string | null; activity_title: string | null; capacity_max: number | null; enrolled_for_period: number; remaining: number | null; waitlist_enabled: boolean };
  enrollments: RosterEnrollment[];
  waitlist: StaffApplication[];
  upcoming_sessions?: { id: string; starts_at: string }[];
};
export type StaffGroup = {
  id: string;
  name: string;
  status: string;
  capacity_max: number | null;
  activity_id: string | null;
  activity_title: string | null;
  enrolled_for_period: number;
  waitlist_count: number;
};
export type TransferOption = {
  id: string;
  name: string;
  capacity_max: number | null;
  remaining: number | null;
  prices: { id: string; enrollment_mode: string; unit: string; amount_cents: number; currency: string }[];
};
export type ReviewDecision = 'approve' | 'waitlist' | 'reject';

export class EnrollmentApiError extends Error {
  constructor(message: string, public status: number, public fieldErrors: Record<string, string[]> = {}) { super(message); }
}

function cookie(name: string): string | undefined {
  if (typeof document === 'undefined') return undefined;
  const value = document.cookie.split('; ').find((item) => item.startsWith(`${name}=`));
  return value ? decodeURIComponent(value.slice(name.length + 1)) : undefined;
}

async function request<T>(organizationId: string, path: string, init: RequestInit = {}): Promise<T> {
  const write = init.method && init.method !== 'GET';
  if (write && !cookie('XSRF-TOKEN')) await fetch('/backend/sanctum/csrf-cookie', { credentials: 'include' });
  const token = write ? cookie('XSRF-TOKEN') : undefined;
  const response = await fetch(`/backend/api/v1/enrollment${path}`, {
    ...init,
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      'X-Organization-Id': organizationId,
      ...(init.body ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { 'X-XSRF-TOKEN': token } : {}),
    },
  });
  if (!response.ok) {
    const body = await response.json().catch(() => ({})) as { message?: unknown; errors?: Record<string, string[]> };
    const firstError = body.errors ? Object.values(body.errors)[0]?.[0] : undefined;
    const message = response.status === 403
      ? 'Нямате права за това действие. Обърнете се към собственика на организацията.'
      : catalogErrorMessage(firstError ?? body.message);
    throw new EnrollmentApiError(message, response.status, body.errors ?? {});
  }
  return response.json() as Promise<T>;
}

export type ApplicationPage = { applications: StaffApplication[]; total: number; has_more: boolean };

export const loadApplications = (organizationId: string, status: 'submitted' | 'waitlisted' = 'submitted') =>
  request<ApplicationPage>(organizationId, `/applications?status=${status}`);

export const reviewApplication = (organizationId: string, application: StaffApplication, decision: ReviewDecision, publicReason?: string) =>
  request<{ application: StaffApplication }>(organizationId, `/applications/${application.id}/review`, {
    method: 'POST',
    body: JSON.stringify({ decision, version: application.version, public_reason: publicReason?.trim() || null }),
  }).then((x) => x.application);

export const loadRoster = (organizationId: string, groupId: string) =>
  request<GroupRoster>(organizationId, `/groups/${groupId}/roster`);

export const loadStaffGroups = (organizationId: string) =>
  request<{ groups: StaffGroup[] }>(organizationId, '/groups').then((x) => x.groups);

export const loadTransferOptions = (organizationId: string, enrollmentId: string, date: string) =>
  request<{ groups: TransferOption[] }>(organizationId, `/enrollments/${enrollmentId}/transfer-options?date=${encodeURIComponent(date)}`).then((x) => x.groups);

function change<T>(organizationId: string, enrollment: RosterEnrollment, path: string, body: Record<string, unknown>, method = 'POST'): Promise<T> {
  const payload = { ...body, version: enrollment.version };
  if (method === 'DELETE') {
    return request<T>(organizationId, `/enrollments/${enrollment.id}${path}?version=${enrollment.version}`, { method });
  }
  return request<T>(organizationId, `/enrollments/${enrollment.id}${path}`, { method, body: JSON.stringify(payload) });
}

export const transferEnrollment = (organizationId: string, enrollment: RosterEnrollment, input: { targetGroupId: string; priceOptionId: string; effectiveDate: string; reason: string }) =>
  change(organizationId, enrollment, '/transfer', {
    target_group_id: input.targetGroupId, price_option_id: input.priceOptionId, effective_date: input.effectiveDate, reason: input.reason.trim(),
  });

export const pauseEnrollment = (organizationId: string, enrollment: RosterEnrollment, input: { startsOn: string; endsOn: string; reasonCategory: PauseReason; note: string }) =>
  change(organizationId, enrollment, '/pauses', {
    starts_on: input.startsOn, ends_on: input.endsOn, reason_category: input.reasonCategory, note: input.note.trim() || null,
  });

export const endPause = (organizationId: string, enrollment: RosterEnrollment, pauseId: string, resumeOn: string) =>
  change(organizationId, enrollment, `/pauses/${pauseId}/end`, { resume_on: resumeOn });

export const endEnrollment = (organizationId: string, enrollment: RosterEnrollment, input: { lastDay: string; reason: string }) =>
  change(organizationId, enrollment, '/end', { last_day: input.lastDay, reason: input.reason.trim() });

export const reopenEnrollment = (organizationId: string, enrollment: RosterEnrollment) =>
  change(organizationId, enrollment, '/reopen', {});

export const skipSession = (organizationId: string, enrollment: RosterEnrollment, input: { sessionId: string; reason: string }) =>
  change(organizationId, enrollment, '/skipped-sessions', { session_id: input.sessionId, reason: input.reason.trim() });

export const restoreSession = (organizationId: string, enrollment: RosterEnrollment, sessionId: string) =>
  change(organizationId, enrollment, `/skipped-sessions/${sessionId}`, {}, 'DELETE');
