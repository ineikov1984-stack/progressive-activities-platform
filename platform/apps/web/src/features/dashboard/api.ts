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

export type DashboardAuditEvent = {
  id: string;
  actor_id?: string;
  action: string;
  subject_type: string;
  subject_id: string;
  metadata?: Record<string, unknown>;
  correlation_id: string;
  created_at: string;
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

function readCookie(name: string): string | undefined {
  if (typeof document === 'undefined') {
    return undefined;
  }

  const prefix = `${name}=`;
  const cookie = document.cookie.split('; ').find((item) => item.startsWith(prefix));

  return cookie ? decodeURIComponent(cookie.slice(prefix.length)) : undefined;
}

async function ensureCsrfToken(): Promise<string | undefined> {
  const existingToken = readCookie('XSRF-TOKEN');
  if (existingToken) {
    return existingToken;
  }

  await fetch('/backend/sanctum/csrf-cookie', { credentials: 'include' });

  return readCookie('XSRF-TOKEN');
}

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

export async function loadOrganizationContext(): Promise<Pick<DashboardData, 'user' | 'organization' | 'organizations'>> {
  const [{ user }, { organizations }] = await Promise.all([
    requestJson<{ user: DashboardUser }>('/backend/api/v1/me'),
    requestJson<{ organizations: DashboardOrganization[] }>('/backend/api/v1/organizations'),
  ]);
  const organization = organizations.find((item) => item.role === 'organization_owner') ?? organizations[0];
  if (!organization) throw new Error('Профилът няма активна организация.');
  return { user, organization, organizations };
}

export async function loadDashboard(): Promise<DashboardData> {
  try {
    const { user, organization, organizations } = await loadOrganizationContext();
    const [{ invitations }, { events: auditEvents }] = await Promise.all([
      requestJson<{ invitations: DashboardInvitation[] }>(
        '/backend/api/v1/invitations',
        { headers: { 'X-Organization-Id': organization.id } },
      ),
      requestJson<{ events: DashboardAuditEvent[] }>(
        '/backend/api/v1/audit-events',
        { headers: { 'X-Organization-Id': organization.id } },
      ),
    ]);

    return { user, organization, organizations, invitations, auditEvents };
  } catch (reason) {
    if (reason instanceof AuthenticationRequiredError) {
      throw reason;
    }

    throw new Error('Не успяхме да заредим административното табло.');
  }
}

export async function createInvitation(organizationId: string, email: string, role: string): Promise<{
  invitation: DashboardInvitation;
  token: string;
}> {
  const csrfToken = await ensureCsrfToken();

  return requestJson('/backend/api/v1/invitations', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Organization-Id': organizationId,
      ...(csrfToken ? { 'X-XSRF-TOKEN': csrfToken } : {}),
    },
    body: JSON.stringify({ email, role }),
  });
}
