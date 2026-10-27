import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  createOrganizationApplicant,
  loadOrganizationApplication,
  loadOperatorApplications,
  reviewOrganizationApplication,
  StaleApplicationError,
} from '../src/features/organizations/application/api';

const jsonResponse = (body: unknown, status = 200) => new Response(JSON.stringify(body), {
  status,
  headers: { 'Content-Type': 'application/json' },
});

describe('organization onboarding API', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('loads the safe application for the selected organization', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({
      application: { id: 'org-1', name: 'Училище', status: 'review', review_version: 3 },
    }));
    vi.stubGlobal('fetch', fetchMock);

    const application = await loadOrganizationApplication('org-1');

    expect(application.status).toBe('review');
    expect(fetchMock).toHaveBeenCalledWith('/backend/api/v1/organization-application', expect.objectContaining({
      headers: expect.objectContaining({ 'X-Organization-Id': 'org-1' }),
    }));
  });

  it('loads the operator queue without tenant headers', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ applications: [
      { id: 'org-2', name: 'Клуб', status: 'applicant', review_version: 1 },
    ] }));
    vi.stubGlobal('fetch', fetchMock);

    const applications = await loadOperatorApplications();

    expect(applications).toHaveLength(1);
    expect(fetchMock).toHaveBeenCalledWith('/backend/api/v1/operator/organization-applications', expect.any(Object));
  });

  it('creates an applicant and returns its one-time owner token', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(jsonResponse({
        application: { id: 'org-3', name: 'Ателие', status: 'applicant', review_version: 1 },
        owner_invitation_token: 'one-time-owner-token',
      }, 201));
    vi.stubGlobal('fetch', fetchMock);

    const result = await createOrganizationApplicant('Ателие', 'atelie', 'owner@example.test');

    expect(result.ownerInvitationToken).toBe('one-time-owner-token');
    expect(fetchMock).toHaveBeenLastCalledWith('/backend/api/v1/operator/organization-applications', expect.objectContaining({
      method: 'POST',
      body: JSON.stringify({ name: 'Ателие', slug: 'atelie', owner_email: 'owner@example.test' }),
    }));
  });

  it('sends an expected version and identifies stale review decisions', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(jsonResponse({ message: 'Заявлението вече е променено.' }, 409));
    vi.stubGlobal('fetch', fetchMock);

    await expect(reviewOrganizationApplication('org-2', {
      reviewVersion: 4,
      toStatus: 'approved',
      internalNote: 'Документите са проверени.',
    })).rejects.toBeInstanceOf(StaleApplicationError);
  });
});
