export type CatalogOrganization = { slug: string; public_name: string; intro?: string | null; accent_color?: string | null; logo_url?: string | null };
export type Availability = { status: 'available' | 'limited'; remaining_places: number | null };
export type PublicPrice = { enrollment_mode: string; unit: 'session' | 'month' | 'period' | 'package'; amount_cents: number; currency: 'EUR'; label: string; terms?: string | null; cancellation_policy?: string | null; payment_due?: string | null };
export type PublicSchedule = { timezone: string; frequency: 'weekly' | 'biweekly'; start_date?: string; end_date?: string; slots?: { weekday: number; starts_at: string; duration_minutes: number }[] };
export type PublicLocation = { name: string; type: 'physical' | 'online' | 'tbc'; address?: string | null; public_directions?: string | null; navigation_url?: string | null };
export type PublicInstructor = { public_name: string; role_title?: string | null; bio?: string | null; qualifications?: string[] | null; photo_url?: string | null };
export type ActivitySections = { about?: string | null; benefits?: string[] | null; methodology?: string | null; participation_terms?: string | null; faq?: { question: string; answer: string }[] | null; gallery?: { url: string; alt: string }[] | null; instructor_intro?: string | null };
export type PublicActivityCard = { slug: string; title: string; category: string; summary: string; age_label: string; hero_image_url?: string | null; price?: PublicPrice | null; availability: Availability; schedule?: PublicSchedule | null };
export type PublicGroup = { name: string; age_label?: string | null; capacity_min: number; capacity_max?: number | null; schedule?: PublicSchedule | null; availability: Availability; location?: PublicLocation | null; prices: PublicPrice[] };
export type PublicActivity = PublicActivityCard & { sections: ActivitySections; groups: PublicGroup[]; terms: { title?: string | null; terms?: string | null; cancellation_policy?: string | null }[]; instructors: PublicInstructor[] };
export type PublicCatalog = { organization: CatalogOrganization; activities: PublicActivityCard[] };
export type PublicActivityResponse = { organization: CatalogOrganization; activity: PublicActivity };

async function publicRequest<T>(path: string): Promise<T | null> {
  const apiBase = process.env.API_BASE_URL ?? 'http://127.0.0.1:8080';
  const response = await fetch(`${apiBase}/api/v1/public${path}`, { cache: 'no-store', headers: { Accept: 'application/json' } });
  if (response.status === 404) return null;
  if (!response.ok) throw new Error('Публичният каталог временно не е достъпен.');
  return response.json() as Promise<T>;
}

export const loadPublicCatalog = (organizationSlug: string) => publicRequest<PublicCatalog>(`/organizations/${encodeURIComponent(organizationSlug)}/catalog`);
export const loadPublicActivity = (organizationSlug: string, activitySlug: string) => publicRequest<PublicActivityResponse>(`/organizations/${encodeURIComponent(organizationSlug)}/activities/${encodeURIComponent(activitySlug)}`);
export const publicMediaUrl = (url?: string | null) => url ? `/backend${url}` : undefined;
