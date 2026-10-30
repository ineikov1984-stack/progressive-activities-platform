export type ActivitySections = { about?: string | null; benefits?: string[] | null; methodology?: string | null; participation_terms?: string | null; faq?: { question: string; answer: string }[] | null; gallery?: { path: string; alt: string }[] | null; instructor_intro?: string | null };
export type PriceOption = { id?: string; enrollment_mode: string; unit: string; amount_cents: number; currency?: 'EUR'; terms?: string | null; is_available?: boolean; cancellation_policy?: string | null; payment_due?: string | null };
export type ActivityDraft = { id: string; slug: string; status: string; version: number; title: string; category: string; summary: string; age_label: string; hero_image_path?: string | null; sections: ActivitySections; offering: null | { id: string; version: number; location_id?: string | null; lead_instructor_profile_id?: string | null; title?: string | null; description?: string | null; terms?: string | null; cancellation_policy?: string | null; contact_email?: string | null; organization_contact_email?: string | null; enrollment_instructions?: string | null; payment_terms?: Partial<Record<string, string>> | []; price_options: PriceOption[] } };
export type CatalogProfile = { id?: string; slug?: string; public_name: string; intro?: string | null; logo_path?: string | null; accent_color?: string | null; is_published?: boolean; version?: number };
export type Location = { id: string; name: string; type: 'physical' | 'online' | 'tbc'; space_usage: 'exclusive' | 'shared'; address?: string | null; latitude?: string | number | null; longitude?: string | number | null; map_url?: string | null; public_directions?: string | null; is_public: boolean; version: number };
export type InstructorProfile = { id: string; public_name: string; role_title?: string | null; bio?: string | null; qualifications?: string[] | null; photo_path?: string | null; is_public: boolean; version: number };
export type SchedulePattern = { timezone: string; frequency: 'weekly' | 'biweekly'; start_date?: string; end_date?: string; slots?: { weekday: number; starts_at: string; duration_minutes: number }[]; added_dates?: { date: string; starts_at: string; duration_minutes: number }[]; starts_on?: string; ends_on?: string; patterns?: { weekday: number; starts_at: string; duration_minutes: number }[]; excluded_dates?: string[] };
export type ScheduleConflict = { severity: 'blocking' | 'warning'; kind: string; message: string; session_id?: string };
export type ActivityGroup = { id: string; offering_id: string; version: number; name: string; age_label: string; status: string; capacity_min: number; capacity_max: number; enrollment_approval_mode?: 'manual' | 'automatic'; location_id?: string | null; instructor_ids: string[]; schedule_pattern: SchedulePattern; price_options: PriceOption[]; sessions: { id: string; starts_at: string; ends_at: string; status: string }[]; conflicts: ScheduleConflict[] };
export type ActivityInput = Partial<Pick<ActivityDraft, 'title' | 'category' | 'summary' | 'age_label' | 'hero_image_path' | 'sections'>> & { version?: number; offering?: Partial<NonNullable<ActivityDraft['offering']>> };
type NewActivityInput = Pick<ActivityDraft, 'title' | 'category' | 'summary' | 'age_label'> & ActivityInput;
export type CatalogWorkspace = { activities: ActivityDraft[]; profile: CatalogProfile | null; locations: Location[]; instructors: InstructorProfile[] };

export class CatalogApiError extends Error { constructor(message: string, public status: number, public fieldErrors: Record<string, string[]> = {}) { super(message); } }
type JsonObject = Record<string, unknown>;
function cookie(name: string): string | undefined { if (typeof document === 'undefined') return; const value = document.cookie.split('; ').find((item) => item.startsWith(`${name}=`)); return value ? decodeURIComponent(value.slice(name.length + 1)) : undefined; }
async function csrf(): Promise<string | undefined> { const current = cookie('XSRF-TOKEN'); if (current) return current; await fetch('/backend/sanctum/csrf-cookie', { credentials: 'include' }); return cookie('XSRF-TOKEN'); }
const knownApiMessages: Record<string, string> = {
  'Only drafts can be edited.': 'Публикуваната дейност не може да се променя директно. Скоро ще може да правите промени като чернова.',
  'Only drafts can be published.': 'Дейността вече е публикувана.',
  'Activity version conflict.': 'Някой току-що промени тази дейност. Презаредете страницата и опитайте отново.',
  'Blocking schedule conflict.': 'Графикът се застъпва с друго занимание. Проверете групите и опитайте отново.',
  'Only active organizations can publish.': 'Организацията трябва да бъде одобрена, преди да публикува.',
};

/** API messages are shown to people; never show English or technical text. */
export function catalogErrorMessage(message: unknown): string {
  if (typeof message !== 'string' || message.trim() === '') return 'Заявката към каталога не беше изпълнена.';
  if (knownApiMessages[message]) return knownApiMessages[message];
  return /[А-Яа-я]/.test(message) ? message : 'Действието не беше изпълнено. Опитайте отново или се свържете с поддръжката.';
}
async function request<T>(organizationId: string, path: string, options: RequestInit = {}, write = false): Promise<T> {
  const token = write ? await csrf() : undefined;
  const response = await fetch(`/backend/api/v1/catalog${path}`, { ...options, credentials: 'include', headers: { Accept: 'application/json', 'X-Organization-Id': organizationId, ...(token ? { 'X-XSRF-TOKEN': token } : {}), ...(options.headers ?? {}) } });
  if (!response.ok) { const body = await response.json().catch(() => ({})) as JsonObject; throw new CatalogApiError(catalogErrorMessage(body.message), response.status, body.errors && typeof body.errors === 'object' ? body.errors as Record<string, string[]> : {}); }
  return response.json() as Promise<T>;
}
const json = (body: unknown): RequestInit => ({ headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
export async function loadCatalogWorkspace(organizationId: string): Promise<CatalogWorkspace> {
  const [{ activities }, { profile }, { locations }, { instructors }] = await Promise.all([
    request<{ activities: ActivityDraft[] }>(organizationId, '/activities'), request<{ profile: CatalogProfile | null }>(organizationId, '/profile'), request<{ locations: Location[] }>(organizationId, '/locations'), request<{ instructors: InstructorProfile[] }>(organizationId, '/instructors'),
  ]); return { activities, profile, locations, instructors };
}
export const loadActivity = (organizationId: string, id: string) => request<{ activity: ActivityDraft }>(organizationId, `/activities/${id}`).then((x) => x.activity);
export const loadGroups = (organizationId: string, id: string) => request<{ groups: ActivityGroup[] }>(organizationId, `/activities/${id}/groups`).then((x) => x.groups);
export const createActivity = (organizationId: string, body: NewActivityInput) => request<{ activity: ActivityDraft }>(organizationId, '/activities', { method: 'POST', ...json(body) }, true).then((x) => x.activity);
export const updateActivity = (organizationId: string, id: string, body: ActivityInput) => request<{ activity: ActivityDraft }>(organizationId, `/activities/${id}`, { method: 'PATCH', ...json(body) }, true).then((x) => x.activity);
export const publishActivity = (organizationId: string, id: string, version: number) => request<{ activity: Pick<ActivityDraft, 'id' | 'status' | 'version'> }>(organizationId, `/activities/${id}/publish`, { method: 'POST', ...json({ version }) }, true).then((x) => x.activity);
export const saveProfile = (organizationId: string, body: Partial<CatalogProfile>) => request<{ profile: CatalogProfile }>(organizationId, '/profile', { method: 'PATCH', ...json(body) }, true).then((x) => x.profile);
export const createLocation = (organizationId: string, body: Partial<Location>) => request<{ location: Location }>(organizationId, '/locations', { method: 'POST', ...json(body) }, true).then((x) => x.location);
export const createInstructor = (organizationId: string, body: Partial<InstructorProfile>) => request<{ instructor: InstructorProfile }>(organizationId, '/instructors', { method: 'POST', ...json(body) }, true).then((x) => x.instructor);
export const saveGroup = (organizationId: string, activityId: string, body: Record<string, unknown>, groupId?: string) => request<{ group: ActivityGroup }>(organizationId, groupId ? `/groups/${groupId}` : `/activities/${activityId}/groups`, { method: groupId ? 'PATCH' : 'POST', ...json(body) }, true).then((x) => x.group);
export async function uploadCatalogMedia(organizationId: string, file: File, activityId?: string): Promise<{ path: string }> { const body = new FormData(); body.append('file', file); if (activityId) body.append('activity_id', activityId); return request<{ media: { path: string } }>(organizationId, '/media', { method: 'POST', body }, true).then((x) => x.media); }
export const privateMediaUrl = (path?: string | null) => path ? `/backend/api/v1/catalog/media/${encodeURIComponent(path.replace(/^catalog\//, ''))}` : undefined;

export type VisitOfferType = 'trial' | 'single' | 'open_day';
export type VisitOfferStatus = 'draft' | 'published' | 'closed' | 'cancelled';
export type VisitOfferFields = {
  activity_id: string; group_id: string; type: VisitOfferType; title: string; description: string | null;
  audience_age_label: string | null; audience_class_label: string | null; visit_limit: number;
  fee_type: 'free' | 'paid' | 'from_price'; amount_cents: number; currency: 'EUR'; fee_reason: string | null;
  booking_deadline_hours: number; cancellation_deadline_hours: number; cancellation_policy: string | null;
  cancellation_outcome: 'refund' | 'credit' | 'non_refundable'; terms_version: string | null;
  trial_limit_per_academic_year: number; single_visit_limit: number; single_visit_window_days: number;
  unlimited_single_visits: boolean; no_show_trial_limit: number | null; waitlist_enabled: boolean;
};
export type VisitOffer = VisitOfferFields & {
  id: string; campaign_id: string | null; status: VisitOfferStatus; version: number;
  sessions: ActivityGroup['sessions']; published_at: string | null; closed_at: string | null; cancelled_at: string | null;
};
export type VisitOfferInput = Pick<VisitOfferFields, 'activity_id' | 'group_id' | 'type' | 'title' | 'visit_limit'> & Partial<VisitOfferFields> & { version?: number; session_ids?: string[] };
export type OpenDayCampaign = {
  id: string; slug: string; title: string; description: string | null; starts_on: string | null; ends_on: string | null;
  image_path: string | null; status: VisitOfferStatus; version: number; published_at: string | null;
  visit_offers: Pick<VisitOffer, 'id' | 'title' | 'status' | 'type'>[];
};
export type CampaignMembershipInput = { version: number; visit_offer_ids: string[]; visit_offer_versions: Record<string, number>; source_campaign_versions: Record<string, number> };
export type OpenDayCampaignInput = Pick<OpenDayCampaign, 'title'> & Partial<Pick<OpenDayCampaign, 'slug' | 'description' | 'starts_on' | 'ends_on' | 'image_path'>> & Partial<CampaignMembershipInput>;
export const loadVisitOffers = (organizationId: string) => request<{ visit_offers: VisitOffer[] }>(organizationId, '/visit-offers').then((x) => x.visit_offers);
export const loadOpenDayCampaigns = (organizationId: string) => request<{ campaigns: OpenDayCampaign[] }>(organizationId, '/open-day-campaigns').then((x) => x.campaigns);
export const saveVisitOffer = (organizationId: string, body: VisitOfferInput, id?: string) => request<{ visit_offer: VisitOffer }>(organizationId, id ? `/visit-offers/${id}` : '/visit-offers', { method: id ? 'PATCH' : 'POST', ...json(body) }, true).then((x) => x.visit_offer);
export const transitionVisitOffer = (organizationId: string, id: string, version: number, transition: 'publish' | 'close' | 'cancel') => request<{ visit_offer: VisitOffer }>(organizationId, `/visit-offers/${id}/${transition}`, { method: 'POST', ...json({ version }) }, true).then((x) => x.visit_offer);
export const updateCampaignMembership = (organizationId: string, id: string, body: CampaignMembershipInput) => request<{ campaign: OpenDayCampaign }>(organizationId, `/open-day-campaigns/${id}`, { method: 'PATCH', ...json(body) }, true).then((x) => x.campaign);
export const saveOpenDayCampaign = (organizationId: string, body: OpenDayCampaignInput, id?: string) => request<{ campaign: OpenDayCampaign }>(organizationId, id ? `/open-day-campaigns/${id}` : '/open-day-campaigns', { method: id ? 'PATCH' : 'POST', ...json(body) }, true).then((x) => x.campaign);
export const transitionOpenDayCampaign = (organizationId: string, id: string, version: number, transition: 'publish' | 'close' | 'cancel') => request<{ campaign: OpenDayCampaign }>(organizationId, `/open-day-campaigns/${id}/${transition}`, { method: 'POST', ...json({ version }) }, true).then((x) => x.campaign);
