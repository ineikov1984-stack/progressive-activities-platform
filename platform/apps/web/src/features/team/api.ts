import { teamErrorMessage, type ErrorBody } from './presentation';

/** The page "Екип" (docs/product/team.md, т. 9, 10): people with or without a login, their roles and groups. */
export type MemberState = 'login' | 'profile_only' | 'archived';
/**
 * A group the person leads or assists; in the list only current and coming ones, in the profile the ended ones too.
 * `activity` and `group` are the activity's title and the group's name (`label` is both); `version` is the
 * assignment's: ending it asks for it.
 */
export type GroupAssignment = {
  group_id: string; label: string; activity: string; group: string; role: 'lead' | 'assistant' | string;
  starts_on: string | null; ends_on: string | null; version: number;
};
/** The latest invitation that is not accepted yet: its state, end and the role it gives — never the link. */
export type MemberInvitation = { status: 'pending' | 'expired' | string; expires_at: string; role?: string };
export type TeamMember = {
  id: string;
  full_name: string;
  state: MemberState | string;
  /** The role of a person who logs in; none for a profile without a login. */
  role: string | null;
  instructor_profile_id: string | null;
  groups: GroupAssignment[];
  archived_at: string | null;
  invitation?: MemberInvitation | null;
  version: number;
  /** Only for those who manage the team. */
  phone?: string | null;
  email?: string | null;
  /** When the person last signed in or selected the organization, and last used it — only for those who manage the team. */
  last_login_at?: string | null;
  last_seen_at?: string | null;
};
export type TeamList = { members: TeamMember[]; can_manage: boolean };
/**
 * For the rights also which were given or taken (the rights of one save together) and the role or scope before and
 * after — keys only (TeamController::history).
 */
export type HistoryEntry = {
  action: string; at: string; by: string | null;
  abilities?: string[]; reason?: string | null; from?: string | null; to?: string | null;
  /** For a group added to the rights or taken from them: a group or a whole activity, never which one. */
  target?: 'group' | 'activity' | string;
};
/** `history` comes only for those who manage the team. */
export type MemberDetails = { member: TeamMember; history?: HistoryEntry[] };
/** Rights are given only together with an invitation: `access` without `invitation` is refused and nobody is added. */
export type NewMember = {
  full_name: string; phone: string; email?: string | null; teaches: boolean;
  invitation?: { role: string; email: string };
  access?: AccessPayload;
};
/** The person; with an invitation, also what was sent and the inviter's link (shown once). */
export type AddedMember = { member: TeamMember; sent: SentInvitation | null };
/** Only the fields that changed: a person backfilled without a phone keeps saving a new name. */
export type MemberChanges = { version: number; full_name?: string; phone?: string; email?: string | null };
export type SentInvitation = {
  invitation: { id: string; staff_member_id: string | null; email: string; role: string; status: string; expires_at: string };
  /** The inviter's link for copying: it joins only an existing profile; shown once. */
  link: string;
};
/*
 * The card "Права" (docs/product/team-rights.md, т. 9, 10). The keys of the rights are for the screen to name, never
 * to show; `grantable`, `history` and `changes` come only for those who manage the team.
 */
export type AccessScope = 'own' | 'groups' | 'all';
/** Where a right comes from: the role, an added right (with its last day, or brought along by another) or leading a group. */
export type AccessRight = {
  key: string; area: string; source: 'role' | 'grant' | 'assignment' | string; grant_id: string | null;
  /** The last day the added right holds, "YYYY-MM-DD"; none — without an end. */
  expires_on: string | null;
  /** Brought along by another added right ("Публикува" brings "Създава и променя"). */
  implied: boolean;
};
/** Identifiers and keys only — never a parent's phone, e-mail or name. */
export type AccessHistoryEntry = {
  action: string; at: string; by: string | null; group_label: string | null;
  ability?: string; reason?: string; expires_at?: string | null; from?: string | null; to?: string | null; role?: string;
  starts_on?: string | null; ends_on?: string | null;
};
/** What a new role would take away, before it is confirmed. */
export type AccessChanges = {
  revoke: { ability: string; reason: string }[]; clear_scope: boolean; remove_groups: number; clear_template: boolean; loses_finance: boolean;
};
export type AccessCard = {
  member_id: string;
  state: 'archived' | 'no_access' | 'access' | string;
  /** Also the role of a waiting invitation. */
  role: string | null;
  scope: AccessScope | null;
  allowed_scopes: AccessScope[];
  groups: { group_id: string; label: string | null }[];
  activities: { activity_id: string; title: string | null }[];
  rights: AccessRight[];
  template: string | null;
  version: number;
  editable: boolean;
  /** Why the card cannot be changed by the signed-in person, in Bulgarian. */
  reason: string | null;
  grantable?: string[];
  history?: AccessHistoryEntry[];
  changes?: AccessChanges;
};
/** A group, or a whole activity with its future groups. */
export type AccessTarget = { group_id: string } | { activity_id: string };
export type AccessGrant = { ability: string; expires_on: string | null };
export type AccessPayload = {
  scope: AccessScope | null; groups: AccessTarget[]; grants: AccessGrant[]; template: string | null; confirm_finance?: boolean;
};

export type NewAssignment = { instructor_profile_id: string; role: 'lead' | 'assistant'; starts_on?: string | null; ends_on?: string | null };

export class TeamApiError extends Error {
  constructor(message: string, public status: number, public fieldErrors: Record<string, string[]> = {}) { super(message); }
}

function cookie(name: string): string | undefined {
  if (typeof document === 'undefined') return undefined;
  const value = document.cookie.split('; ').find((item) => item.startsWith(`${name}=`));
  return value ? decodeURIComponent(value.slice(name.length + 1)) : undefined;
}

type RequestOptions = {
  method?: 'GET' | 'POST' | 'PATCH' | 'PUT';
  body?: unknown;
  organizationId?: string;
  headers?: Record<string, string>;
  /** Off for the one public route outside the session (the invitation lookup): no cookie is needed or made. */
  csrf?: boolean;
};

async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const write = options.method !== undefined && options.method !== 'GET' && options.csrf !== false;
  if (write && !cookie('XSRF-TOKEN')) await fetch('/backend/sanctum/csrf-cookie', { credentials: 'include' });
  const token = write ? cookie('XSRF-TOKEN') : undefined;
  const response = await fetch(`/backend/api/v1${path}`, {
    method: options.method ?? 'GET',
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      ...(options.organizationId ? { 'X-Organization-Id': options.organizationId } : {}),
      ...(options.body !== undefined ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { 'X-XSRF-TOKEN': token } : {}),
      ...(options.headers ?? {}),
    },
    body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
  });
  if (!response.ok) {
    const body = await response.json().catch(() => ({})) as ErrorBody;
    throw new TeamApiError(teamErrorMessage(response.status, body), response.status, body.errors ?? {});
  }
  if (response.status === 204) return undefined as T;
  return response.json() as Promise<T>;
}

const staff = (organizationId: string) => ({ organizationId });

export function loadTeam(organizationId: string, archived = false): Promise<TeamList> {
  return request<TeamList>(archived ? '/team?archived=1' : '/team', staff(organizationId));
}

export function loadMember(organizationId: string, memberId: string): Promise<MemberDetails> {
  return request<MemberDetails>(`/team/members/${encodeURIComponent(memberId)}`, staff(organizationId));
}

/**
 * The same key for a retried or double-tapped request adds the person once. With `invitation` the person is added and
 * invited in one step, and `access` gives the rights with it; a refusal of any part adds nobody.
 */
export function addMember(organizationId: string, member: NewMember, idempotencyKey: string): Promise<AddedMember> {
  return request<{ member: TeamMember } & Partial<SentInvitation>>('/team/members', {
    ...staff(organizationId), method: 'POST', body: member, headers: { 'Idempotency-Key': idempotencyKey },
  }).then((result) => ({
    member: result.member,
    sent: result.invitation && typeof result.link === 'string' ? { invitation: result.invitation, link: result.link } : null,
  }));
}

/** The card "Права"; with `role`, also what that role would take away (`changes`). */
export function loadAccess(organizationId: string, memberId: string, role?: string): Promise<AccessCard> {
  const query = role ? `?${new URLSearchParams({ role }).toString()}` : '';
  return request<{ access: AccessCard }>(`/team/members/${encodeURIComponent(memberId)}/access${query}`, staff(organizationId))
    .then((result) => result.access);
}

/** The whole card at once, under its version: 409 — someone changed it meanwhile. */
export function saveAccess(organizationId: string, memberId: string, payload: AccessPayload, version: number): Promise<AccessCard> {
  return request<{ access: AccessCard }>(`/team/members/${encodeURIComponent(memberId)}/access`, {
    ...staff(organizationId), method: 'PUT', body: { ...payload, version },
  }).then((result) => result.access);
}

export function updateMember(organizationId: string, memberId: string, changes: MemberChanges): Promise<TeamMember> {
  return request<{ member: TeamMember }>(`/team/members/${encodeURIComponent(memberId)}`, { ...staff(organizationId), method: 'PATCH', body: changes })
    .then((result) => result.member);
}

export function changeRole(organizationId: string, memberId: string, role: string, version: number): Promise<TeamMember> {
  return request<{ member: TeamMember }>(`/team/members/${encodeURIComponent(memberId)}/role`, { ...staff(organizationId), method: 'POST', body: { role, version } })
    .then((result) => result.member);
}

export function archiveMember(organizationId: string, memberId: string, version: number): Promise<TeamMember> {
  return request<{ member: TeamMember }>(`/team/members/${encodeURIComponent(memberId)}/archive`, { ...staff(organizationId), method: 'POST', body: { version } })
    .then((result) => result.member);
}

/** Gives the person a teacher profile the parents see, so they can lead groups. */
export function createTeacherProfile(organizationId: string, memberId: string): Promise<TeamMember> {
  return request<{ member: TeamMember }>(`/team/members/${encodeURIComponent(memberId)}/instructor-profile`, { ...staff(organizationId), method: 'POST', body: {} })
    .then((result) => result.member);
}

/** Invites the person to log in or sends the invitation again; the e-mail goes out at once. */
export function inviteMember(organizationId: string, memberId: string, role: string, email: string | null): Promise<SentInvitation> {
  return request<SentInvitation>(`/team/members/${encodeURIComponent(memberId)}/invitation`, {
    ...staff(organizationId), method: 'POST', body: email ? { role, email } : { role },
  });
}

export function assignGroup(organizationId: string, groupId: string, assignment: NewAssignment): Promise<void> {
  return request<unknown>(`/catalog/groups/${encodeURIComponent(groupId)}/assignments`, { ...staff(organizationId), method: 'POST', body: assignment })
    .then(() => undefined);
}

export function endAssignment(organizationId: string, groupId: string, profileId: string, endsOn: string, version: number): Promise<void> {
  return request<unknown>(`/catalog/groups/${encodeURIComponent(groupId)}/assignments/${encodeURIComponent(profileId)}/end`, {
    ...staff(organizationId), method: 'POST', body: { ends_on: endsOn, version },
  }).then(() => undefined);
}

/* The page "/invite" (т. 7.4): the token always travels in the request body, never in a path or a query string. */

/**
 * `link`: which link was opened — `email` may make a new profile, `copy` (the inviter's) only joins an existing one.
 * `email` comes only for the e-mail's link: its holder owns the address.
 */
export type InvitationSummary = {
  organization_name: string; role: string; role_label: string; expires_at: string; status: 'pending' | 'expired' | 'accepted' | string;
  link: 'email' | 'copy' | string; email?: string;
};
export type AcceptedInvitation = { organization: { id: string; name: string; slug: string; status: string }; membership: { id: string; role: string; status: string } };
export type SignedInUser = { id: string; name: string; email: string };

export function lookupInvitation(token: string): Promise<InvitationSummary> {
  return request<{ invitation: InvitationSummary }>('/invitations/lookup', { method: 'POST', body: { token }, csrf: false }).then((result) => result.invitation);
}

/** A new profile from the e-mail's link, then the invitation accepted; 403 — the link is not the e-mail's, 409 — log in instead. */
export function registerByInvitation(token: string, name: string, password: string, passwordConfirmation: string): Promise<AcceptedInvitation> {
  return request<AcceptedInvitation>('/invitations/register', {
    method: 'POST', body: { token, name, password, password_confirmation: passwordConfirmation },
  });
}

export function joinByInvitation(token: string): Promise<AcceptedInvitation> {
  return request<AcceptedInvitation>('/invitations/join', { method: 'POST', body: { token } });
}

/** The signed-in person, or null for a guest. */
export async function signedInUser(): Promise<SignedInUser | null> {
  try {
    return (await request<{ user: SignedInUser }>('/me')).user;
  } catch (reason) {
    if (reason instanceof TeamApiError && reason.status === 401) return null;
    throw reason;
  }
}

export function signOut(): Promise<void> {
  return request<unknown>('/auth/logout', { method: 'POST' }).then(() => undefined);
}
