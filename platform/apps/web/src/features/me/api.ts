import type { DashboardUser } from '../dashboard/api';
import { teamErrorMessage, type ErrorBody } from '../team/presentation';

export class ProfileApiError extends Error {
  constructor(message: string, public status: number, public fieldErrors: Record<string, string[]> = {}) { super(message); }
}

function cookie(name: string): string | undefined {
  if (typeof document === 'undefined') return undefined;
  const value = document.cookie.split('; ').find((item) => item.startsWith(`${name}=`));
  return value ? decodeURIComponent(value.slice(name.length + 1)) : undefined;
}

export type OwnContact = { name: string; phone: string };

/**
 * "Моят профил": the person's own name and phone. The organization in the header is the active one: its record of the
 * team follows, so "Екип" shows the new phone; the other organizations keep theirs.
 */
export async function updateOwnContact(organizationId: string, contact: OwnContact): Promise<DashboardUser> {
  if (!cookie('XSRF-TOKEN')) await fetch('/backend/sanctum/csrf-cookie', { credentials: 'include' });
  const token = cookie('XSRF-TOKEN');
  const response = await fetch('/backend/api/v1/me/contact', {
    method: 'PATCH',
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Organization-Id': organizationId,
      ...(token ? { 'X-XSRF-TOKEN': token } : {}),
    },
    body: JSON.stringify(contact),
  });
  if (!response.ok) {
    const body = await response.json().catch(() => ({})) as ErrorBody;
    throw new ProfileApiError(teamErrorMessage(response.status, body), response.status, body.errors ?? {});
  }
  return (await response.json() as { user: DashboardUser }).user;
}
