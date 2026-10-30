/** Sets of payment terms (docs/product/payment-policies.md, т. 7.1; API from step 2b). */
export type Json = null | boolean | number | string | Json[] | { [key: string]: Json };
export type Rules = { [key: string]: Json };
export type SelfService = { absence: boolean; pause_weeks: number; end: boolean; cancel_visit: boolean };

export type BillingKind = 'monthly' | 'full_period' | 'visit' | 'package' | 'day_camp' | 'residential_camp' | 'kindergarten';
export type Preset = 'flexible' | 'standard' | 'strict' | 'custom';
export type ReadyPreset = Exclude<Preset, 'custom'>;

export type ReadyMadeSet = { label: string; rules: Rules; self_service: SelfService };
export type BillingKindInfo = { value: BillingKind; label: string; presets: Record<ReadyPreset, ReadyMadeSet> };

export type PaymentTermsSet = {
  id: string;
  billing_kind: BillingKind;
  billing_kind_label: string;
  name: string;
  preset: Preset;
  preset_label: string;
  is_default: boolean;
  status: 'active' | 'archived';
  version: number;
  self_service: SelfService;
  current_version: { id: string; number: number; schema_version: number; rules: Rules; created_at: string } | null;
  /** Messages by rule path; a set saved before the organization joined the public catalog may fall outside it. */
  marketplace_violations: Record<string, string>;
};

export type PaymentTermsWorkspace = {
  payment_terms: PaymentTermsSet[];
  billing_kinds: BillingKindInfo[];
  in_marketplace: boolean;
  /** Names of the organization's activities that use each way of paying. */
  usage?: Partial<Record<BillingKind, string[]>>;
  can_manage: boolean;
  schema_version: number;
};

export class PaymentTermsApiError extends Error {
  constructor(message: string, public status: number, public fieldErrors: Record<string, string[]> = {}) { super(message); }
}

function cookie(name: string): string | undefined {
  if (typeof document === 'undefined') return undefined;
  const value = document.cookie.split('; ').find((item) => item.startsWith(`${name}=`));
  return value ? decodeURIComponent(value.slice(name.length + 1)) : undefined;
}

async function request<T>(organizationId: string, path: string, init: RequestInit = {}): Promise<T> {
  const write = init.method !== undefined && init.method !== 'GET';
  if (write && !cookie('XSRF-TOKEN')) await fetch('/backend/sanctum/csrf-cookie', { credentials: 'include' });
  const token = write ? cookie('XSRF-TOKEN') : undefined;
  const response = await fetch(`/backend/api/v1/payment-terms${path}`, {
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
    throw new PaymentTermsApiError(errorMessage(response.status, body.message), response.status, body.errors ?? {});
  }
  return response.json() as Promise<T>;
}

/** Bulgarian text for a failed request; the server's own Bulgarian message is kept. */
export function errorMessage(status: number, message: unknown): string {
  if (status === 403) return 'Само собственикът и администраторът могат да променят условията.';
  if (status === 409) return 'Някой друг току-що промени тези условия. Презаредете страницата и опитайте отново.';
  if (status === 422) return 'Проверете отбелязаните полета.';
  if (typeof message === 'string' && /[А-Яа-я]/.test(message)) return message;
  return 'Заявката не беше изпълнена. Опитайте отново.';
}

export function loadPaymentTerms(organizationId: string): Promise<PaymentTermsWorkspace> {
  return request<PaymentTermsWorkspace>(organizationId, '');
}

export async function createPaymentTerms(organizationId: string, body: {
  billing_kind: BillingKind; name: string; is_default: boolean; rules: Rules; self_service: SelfService;
}): Promise<PaymentTermsSet> {
  const response = await request<{ payment_terms: PaymentTermsSet }>(organizationId, '', { method: 'POST', body: JSON.stringify(body) });
  return response.payment_terms;
}

export async function updatePaymentTerms(organizationId: string, id: string, body: {
  version: number; rules?: Rules; self_service?: SelfService; name?: string; is_default?: boolean;
}): Promise<PaymentTermsSet> {
  const response = await request<{ payment_terms: PaymentTermsSet }>(organizationId, `/${encodeURIComponent(id)}`, { method: 'PATCH', body: JSON.stringify(body) });
  return response.payment_terms;
}
