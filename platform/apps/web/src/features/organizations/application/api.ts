export type OrganizationApplication = {
  id: string;
  name: string;
  slug?: string;
  status: 'applicant' | 'review' | 'approved' | 'active' | 'restricted' | 'suspended';
  review_version: number;
  organization_type?: string | null;
  registration_identifier?: string | null;
  public_contact_email?: string | null;
  public_review_reason?: string | null;
  next_action?: string | null;
  submitted_at?: string | null;
  reviewed_at?: string | null;
};

export type ReviewDecision = {
  reviewVersion: number;
  toStatus: OrganizationApplication['status'];
  publicReason?: string;
  nextAction?: string;
  internalNote?: string;
};

type JsonRecord = Record<string, unknown>;

export class StaleApplicationError extends Error {}

function readCookie(name: string): string | undefined {
  if (typeof document === 'undefined') return undefined;
  const prefix = `${name}=`;
  const cookie = document.cookie.split('; ').find((item) => item.startsWith(prefix));
  return cookie ? decodeURIComponent(cookie.slice(prefix.length)) : undefined;
}

async function csrfToken(): Promise<string | undefined> {
  const existing = readCookie('XSRF-TOKEN');
  if (existing) return existing;
  await fetch('/backend/sanctum/csrf-cookie', { credentials: 'include' });
  return readCookie('XSRF-TOKEN');
}

async function request<T>(url: string, options: RequestInit = {}): Promise<T> {
  const response = await fetch(url, {
    ...options,
    credentials: 'include',
    headers: { Accept: 'application/json', ...(options.headers ?? {}) },
  });
  const payload = await response.json().catch(() => ({})) as JsonRecord;

  if (response.status === 409) {
    throw new StaleApplicationError(typeof payload.message === 'string' ? payload.message : 'Данните са променени.');
  }
  if (!response.ok) {
    throw new Error(typeof payload.message === 'string' ? payload.message : 'Заявката не беше изпълнена.');
  }

  return payload as T;
}

async function mutationHeaders(): Promise<Record<string, string>> {
  const token = await csrfToken();
  return {
    'Content-Type': 'application/json',
    ...(token ? { 'X-XSRF-TOKEN': token } : {}),
  };
}

export async function loadOrganizationApplication(organizationId: string): Promise<OrganizationApplication> {
  const result = await request<{ application: OrganizationApplication }>('/backend/api/v1/organization-application', {
    headers: { 'X-Organization-Id': organizationId },
  });
  return result.application;
}

export async function loadOperatorApplications(): Promise<OrganizationApplication[]> {
  const result = await request<{ applications: OrganizationApplication[] }>('/backend/api/v1/operator/organization-applications');
  return result.applications;
}

export async function createOrganizationApplicant(name: string, slug: string, ownerEmail: string): Promise<{
  application: OrganizationApplication;
  ownerInvitationToken: string;
}> {
  const result = await request<{ application: OrganizationApplication; owner_invitation_token: string }>(
    '/backend/api/v1/operator/organization-applications',
    {
      method: 'POST',
      headers: await mutationHeaders(),
      body: JSON.stringify({ name, slug, owner_email: ownerEmail }),
    },
  );
  return { application: result.application, ownerInvitationToken: result.owner_invitation_token };
}

export async function reviewOrganizationApplication(id: string, decision: ReviewDecision): Promise<OrganizationApplication> {
  const result = await request<{ application: OrganizationApplication }>(
    `/backend/api/v1/operator/organization-applications/${id}/review`,
    {
      method: 'POST',
      headers: await mutationHeaders(),
      body: JSON.stringify({
        review_version: decision.reviewVersion,
        to_status: decision.toStatus,
        public_reason: decision.publicReason,
        next_action: decision.nextAction,
        internal_note: decision.internalNote,
      }),
    },
  );
  return result.application;
}
