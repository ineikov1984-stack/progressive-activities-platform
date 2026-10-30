import type { BillingKind, Rules } from '../../payment-terms/api';

export type CatalogOrganization = { slug: string; public_name: string; intro?: string | null; accent_color?: string | null; logo_url?: string | null };
export type AvailabilityStatus = 'available' | 'limited' | 'last_place' | 'full' | 'closed';
export type Availability = { status: AvailabilityStatus; remaining_places: number | null };
export type PublicPrice = { enrollment_mode?: string; unit: 'session' | 'month' | 'period' | 'package'; amount_cents: number; currency: 'EUR'; label: string; from?: boolean; terms?: string | null; cancellation_policy?: string | null; payment_due?: string | null };
export type PublicSchedule = { timezone: string; frequency: 'weekly' | 'biweekly'; start_date?: string; end_date?: string; slots?: { weekday: number; starts_at: string; duration_minutes: number }[] };
export type PublicLocation = { name: string; type: 'physical' | 'online' | 'tbc'; address?: string | null; public_directions?: string | null; navigation_url?: string | null };
export type PublicInstructor = { public_name: string; role_title?: string | null; bio?: string | null; qualifications?: string[] | null; photo_url?: string | null };
export type ActivitySections = { about?: string | null; benefits?: string[] | null; methodology?: string | null; participation_terms?: string | null; faq?: { question: string; answer: string }[] | null; gallery?: { url: string; alt: string }[] | null; instructor_intro?: string | null };
export type SpecialOffer = 'trial' | 'single' | 'open_day';
export type PublicActivityCard = { slug: string; title: string; category: string; summary: string; age_label: string; audience?: string | null; hero_image_url?: string | null; price?: PublicPrice | null; availability: Availability; schedule?: PublicSchedule | null; special_offer?: SpecialOffer | null; special_offer_url?: string | null };
export type PublicSession = { id: string; starts_at: string; ends_at: string; status: string };
export type PublicGroup = { id?: string; name: string; age_label?: string | null; capacity_min: number; capacity_max?: number | null; schedule?: PublicSchedule | null; availability: Availability; location?: PublicLocation | null; prices: PublicPrice[]; sessions?: PublicSession[] };
export type PublicVisitOffer = {
  visit_offer_id: string; session_id: string; campaign_slug: string | null; type: SpecialOffer; title: string;
  description: string | null; audience: string | null; starts_at: string; ends_at: string; timezone: string;
  /** A trial or single visit carries its step-6 price; an open day its own fee (docs/product/visits.md, т. 8a). */
  fee_type: 'free' | 'paid' | 'from_price'; amount_cents: number; currency: 'EUR'; fee_reason: string | null;
  booking_deadline_hours: number;
  /** The visit terms the parent accepts ("Такси, отсъствия и отказ"); `version` is what an application sends. */
  payment_terms: PublicPaymentTerms;
  availability: { status: AvailabilityStatus; remaining_total_places: number | null; remaining_offer_places: number; waitlist_available: boolean };
  action: 'apply' | 'waitlist' | 'closed';
};
export type PublicPaymentTerms = { version: string; billing_kind: BillingKind; billing_kind_label: string; name: string; preset: string; preset_label: string; schema_version: number; rules: Rules };
export type PublicActivity = PublicActivityCard & { sections: ActivitySections; groups: PublicGroup[]; terms: { title?: string | null; terms?: string | null; cancellation_policy?: string | null }[]; instructors: PublicInstructor[]; visit_offers?: PublicVisitOffer[] };
export type PublicCatalog = { organization: CatalogOrganization; activities: PublicActivityCard[] };
export type PublicActivityResponse = { organization: CatalogOrganization; activity: PublicActivity };
export type PublicOpenDayResponse = { organization: CatalogOrganization; campaign: {
  slug: string; title: string; description: string | null; starts_on: string | null; ends_on: string | null; image_url: string | null;
  days: { date: string; activities: { activity: Pick<PublicActivityCard, 'slug' | 'title' | 'category' | 'summary' | 'hero_image_url'>; visit_offers: PublicVisitOffer[] }[] }[];
} };

async function publicRequest<T>(path: string): Promise<T | null> {
  const apiBase = process.env.API_BASE_URL ?? 'http://127.0.0.1:8080';
  const response = await fetch(`${apiBase}/api/v1/public${path}`, { cache: 'no-store', headers: { Accept: 'application/json' } });
  if (response.status === 404) return null;
  if (!response.ok) throw new Error('Публичният каталог временно не е достъпен.');
  return response.json() as Promise<T>;
}

export const loadPublicCatalog = (organizationSlug: string) => publicRequest<PublicCatalog>(`/organizations/${encodeURIComponent(organizationSlug)}/catalog`);
export const loadPublicActivity = (organizationSlug: string, activitySlug: string) => publicRequest<PublicActivityResponse>(`/organizations/${encodeURIComponent(organizationSlug)}/activities/${encodeURIComponent(activitySlug)}`);
export const loadPublicOpenDay = (organizationSlug: string, campaignSlug: string) => publicRequest<PublicOpenDayResponse>(`/organizations/${encodeURIComponent(organizationSlug)}/open-days/${encodeURIComponent(campaignSlug)}`);
export const publicMediaUrl = (url?: string | null) => url?.trim() ? `/backend${url.trim()}` : undefined;
