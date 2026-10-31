export type DashboardUser = {
  id: string;
  name: string;
  email: string;
};

export type DashboardOrganization = {
  id: string;
  name: string;
  slug: string;
  status: string;
  role: string;
  permissions: string[];
};

export type DashboardInvitation = {
  id: string;
  organization_id: string;
  email: string;
  role: string;
  status: string;
  expires_at: string;
};

/**
 * "Последни действия": every role that sees the team gets what happened and when; who did it, on which record,
 * the details and the request id come only for those who manage the team.
 */
export type DashboardAuditEvent = {
  id: string;
  action: string;
  subject_type: string;
  created_at: string;
  actor_id?: string | null;
  subject_id?: string;
  metadata?: Record<string, unknown>;
  correlation_id?: string;
};

export type DashboardData = {
  user: DashboardUser;
  organization: DashboardOrganization;
  organizations: DashboardOrganization[];
  invitations: DashboardInvitation[];
  auditEvents: DashboardAuditEvent[];
};

export class AuthenticationRequiredError extends Error {}

type JsonRecord = Record<string, unknown>;

async function requestJson<T>(url: string, options?: RequestInit): Promise<T> {
  const response = await fetch(url, {
    ...options,
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      ...(options?.headers ?? {}),
    },
  });

  if (response.status === 401) {
    throw new AuthenticationRequiredError('Необходим е вход.');
  }

  if (!response.ok) {
    const payload = await response.json().catch(() => ({})) as JsonRecord;
    throw new Error(typeof payload.message === 'string' ? payload.message : 'Заявката не беше изпълнена.');
  }

  return response.json() as Promise<T>;
}

/**
 * The organization to open: the one a link names (`?organization=` in an email to the team), if the user
 * belongs to it; otherwise the one they own, otherwise the first.
 */
export async function loadOrganizationContext(preferredId?: string | null): Promise<Pick<DashboardData, 'user' | 'organization' | 'organizations'>> {
  const [{ user }, { organizations }] = await Promise.all([
    requestJson<{ user: DashboardUser }>('/backend/api/v1/me'),
    requestJson<{ organizations: DashboardOrganization[] }>('/backend/api/v1/organizations'),
  ]);
  const organization = organizations.find((item) => preferredId && item.id === preferredId)
    ?? organizations.find((item) => item.role === 'organization_owner') ?? organizations[0];
  if (!organization) throw new Error('Профилът няма активна организация.');
  return { user, organization, organizations };
}

export async function loadDashboard(): Promise<DashboardData> {
  try {
    const { user, organization, organizations } = await loadOrganizationContext();
    const { invitations, auditEvents } = await loadWorkspaceOverview(
      organization.id,
      true,
      organization.permissions.includes('team.view'),
    );

    return { user, organization, organizations, invitations, auditEvents };
  } catch (reason) {
    if (reason instanceof AuthenticationRequiredError) {
      throw reason;
    }

    throw new Error('Не успяхме да заредим административното табло.');
  }
}

/**
 * The parts of the home screen that the frame does not already hold.
 * User and organization come from DashboardContext, so they are not fetched twice.
 */
export async function loadWorkspaceOverview(
  organizationId: string,
  withInvitations = true,
  withAuditEvents = true,
): Promise<Pick<DashboardData, 'invitations' | 'auditEvents'>> {
  const headers = { 'X-Organization-Id': organizationId };
  const [{ invitations }, { events: auditEvents }] = await Promise.all([
    // Only those who invite see the invitations; for the other roles the list is empty, not an error.
    withInvitations
      ? requestJson<{ invitations: DashboardInvitation[] }>('/backend/api/v1/invitations', { headers })
      : Promise.resolve({ invitations: [] as DashboardInvitation[] }),
    // The last actions only for the roles that see the team ("team.view"); a teacher gets no list and no error.
    withAuditEvents
      ? requestJson<{ events: DashboardAuditEvent[] }>('/backend/api/v1/audit-events', { headers })
      : Promise.resolve({ events: [] as DashboardAuditEvent[] }),
  ]);
  return { invitations, auditEvents };
}
