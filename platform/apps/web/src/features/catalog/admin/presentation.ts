import type { ActivityDraft, ActivityGroup, ActivitySections, CampaignMembershipInput, OpenDayCampaign, PriceOption, ScheduleConflict, SchedulePattern, VisitOffer, VisitOfferFields, VisitOfferInput } from './api';

const statusLabels: Record<string, string> = { draft: 'Чернова', published: 'Публикувана', archived: 'Архивирана' };
const weekdays: Record<number, string> = { 1: 'понеделник', 2: 'вторник', 3: 'сряда', 4: 'четвъртък', 5: 'петък', 6: 'събота', 7: 'неделя' };

export function formatEur(amountCents: number): string {
  if (!Number.isInteger(amountCents)) throw new Error('Цената трябва да е в цели евроцентове.');
  return new Intl.NumberFormat('bg-BG', { style: 'currency', currency: 'EUR', minimumFractionDigits: 2 }).format(amountCents / 100);
}

const invalidPriceMessage = 'Въведете положителна цена с най-много два знака след десетичната запетая.';
export function parseEuroCents(input: string): number {
  const normalized = input.trim().replace(',', '.');
  const match = /^(\d+)(?:\.(\d{1,2}))?$/.exec(normalized);
  if (!match) throw new Error(invalidPriceMessage);
  const euros = Number(match[1]); const fraction = (match[2] ?? '').padEnd(2, '0');
  const value = euros * 100 + Number(fraction || '0');
  if (!Number.isSafeInteger(value) || value <= 0) throw new Error(invalidPriceMessage);
  return value;
}

export function priceOptionsPayload(options: (Omit<PriceOption, 'amount_cents'> & { amount: string })[]): PriceOption[] {
  return options.map(({ amount, ...option }) => {
    delete option.id;
    return { ...option, amount_cents: parseEuroCents(amount), currency: 'EUR' };
  });
}

export function activityStatusLabel(status: string): string { return statusLabels[status] ?? status; }
export function availabilityLabel(available: boolean): string { return available ? 'Достъпна за записване' : 'Временно недостъпна'; }

const bgMap: Record<string, string> = {
  а: 'a', б: 'b', в: 'v', г: 'g', д: 'd', е: 'e', ж: 'zh', з: 'z', и: 'i', й: 'y', к: 'k', л: 'l', м: 'm', н: 'n', о: 'o', п: 'p', р: 'r', с: 's', т: 't', у: 'u', ф: 'f', х: 'h', ц: 'ts', ч: 'ch', ш: 'sh', щ: 'sht', ъ: 'a', ь: '', ю: 'yu', я: 'ya', '№': '',
};
export function slugPreview(title: string): string {
  return [...title.toLowerCase()].map((char) => bgMap[char] ?? char).join('').normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
}

export function compactOptionalSections(sections: ActivitySections): ActivitySections {
  const result: ActivitySections = {};
  for (const key of ['about', 'methodology', 'participation_terms', 'instructor_intro'] as const) {
    const value = sections[key]?.trim(); if (value) result[key] = value;
  }
  const benefits = sections.benefits?.map((value) => value.trim()).filter(Boolean);
  if (benefits?.length) result.benefits = benefits;
  const faq = sections.faq?.map((item) => ({ question: item.question.trim(), answer: item.answer.trim() })).filter((item) => item.question && item.answer);
  if (faq?.length) result.faq = faq;
  const gallery = sections.gallery?.map((item) => ({ path: item.path.trim(), alt: item.alt.trim() })).filter((item) => item.path && item.alt);
  if (gallery?.length) result.gallery = gallery;
  return result;
}

export function mergeActivitySections(current: ActivitySections, edited: ActivitySections): ActivitySections {
  return compactOptionalSections({ ...current, ...edited });
}

export function buildSchedulePattern(input: {
  startDate: string; endDate: string; frequency: 'weekly' | 'biweekly';
  slots: NonNullable<SchedulePattern['slots']>; excludedDates: string;
  addedDates: NonNullable<SchedulePattern['added_dates']>;
}): SchedulePattern {
  return {
    start_date: input.startDate, end_date: input.endDate, frequency: input.frequency,
    timezone: 'Europe/Sofia', slots: input.slots,
    excluded_dates: input.excludedDates.split(/[\n,]/).map((value) => value.trim()).filter(Boolean),
    added_dates: input.addedDates,
  };
}

function endTime(start: string, minutes: number): string {
  const [hours = 0, mins = 0] = start.split(':').map(Number); const total = hours * 60 + mins + minutes;
  return `${String(Math.floor(total / 60) % 24).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}
export function summarizeSchedule(schedule: SchedulePattern): string {
  const frequency = schedule.frequency === 'biweekly' ? 'През седмица' : 'Всяка седмица';
  const items = (schedule.slots ?? schedule.patterns ?? []).map((item) => `${weekdays[item.weekday] ?? `ден ${item.weekday}`} ${item.starts_at}–${endTime(item.starts_at, item.duration_minutes)}`);
  return `${frequency} · ${items.join('; ')}`;
}

export function conflictPresentation(conflict: ScheduleConflict): { label: string; tone: 'danger' | 'warning'; message: string } {
  return { label: conflict.severity === 'blocking' ? 'Блокира публикуването' : 'Провери преди публикуване', tone: conflict.severity === 'blocking' ? 'danger' : 'warning', message: conflict.message };
}

export type VisitOfferDraft = Omit<VisitOfferFields, 'amount_cents'> & { amount: string; session_ids: string[]; campaign_id: string };
export type VisitOfferErrors = Record<string, string>;
const visitOfferFieldSteps: Record<string, number> = {
  type: 0, title: 0, description: 0, audience_age_label: 0, audience_class_label: 0, campaign_id: 0,
  activity_id: 1, group_id: 1, session_ids: 1,
  visit_limit: 2, trial_limit_per_academic_year: 2, single_visit_limit: 2, single_visit_window_days: 2, no_show_trial_limit: 2, waitlist_enabled: 2, unlimited_single_visits: 2,
  fee_type: 3, currency: 3, amount_cents: 3, fee_reason: 3,
  booking_deadline_hours: 4, cancellation_deadline_hours: 4, cancellation_policy: 4, cancellation_outcome: 4, terms_version: 4,
};
export function visitOfferErrorTarget(key = ''): { step: number; controlId: string } {
  const field = key.startsWith('session_ids.') ? 'session_ids' : key;
  return { step: visitOfferFieldSteps[field] ?? 5, controlId: field in visitOfferFieldSteps ? `visit-field-${field}` : 'visit-heading-5' };
}
export const cancelledCampaignMembershipMessage = 'Кампанията е отменена. Предложението не може да се премества или отделя от нея. Можете да редактирате останалото съдържание.';
export class CampaignMembershipLockedError extends Error {}
export function newVisitOfferDraft(activityId: string): VisitOfferDraft {
  return { activity_id: activityId, group_id: '', type: 'trial', title: '', description: '', audience_age_label: '', audience_class_label: '', visit_limit: 3,
    fee_type: 'free', amount: '', currency: 'EUR', fee_reason: '', booking_deadline_hours: 12, cancellation_deadline_hours: 24,
    cancellation_policy: '', cancellation_outcome: 'non_refundable', terms_version: '', trial_limit_per_academic_year: 1,
    single_visit_limit: 2, single_visit_window_days: 30, unlimited_single_visits: false, no_show_trial_limit: null, waitlist_enabled: true, session_ids: [], campaign_id: '' };
}
export function visitOfferDraftFromRecord(offer: VisitOffer): VisitOfferDraft {
  const draft = newVisitOfferDraft(offer.activity_id);
  for (const key of Object.keys(draft) as (keyof VisitOfferDraft)[]) {
    if (key in offer && key !== 'campaign_id') Object.assign(draft, { [key]: offer[key as keyof VisitOffer] });
  }
  return { ...draft, amount: (offer.amount_cents / 100).toFixed(2), session_ids: offer.sessions.map((session) => session.id), campaign_id: offer.campaign_id ?? '' };
}
export function visitOfferPayload(draft: VisitOfferDraft, version?: number): VisitOfferInput {
  const { amount, campaign_id: _campaign, ...fields } = draft;
  void _campaign;
  const payload: VisitOfferInput = { ...fields, title: draft.title.trim(), amount_cents: draft.fee_type === 'free' ? 0 : parseEuroCents(amount), ...(version === undefined ? {} : { version }) };
  if (draft.type !== 'trial') { delete payload.trial_limit_per_academic_year; delete payload.no_show_trial_limit; }
  if (draft.type !== 'single') { delete payload.single_visit_limit; delete payload.single_visit_window_days; delete payload.unlimited_single_visits; }
  else if (draft.unlimited_single_visits) { delete payload.single_visit_limit; delete payload.single_visit_window_days; }
  return payload;
}
export function changeVisitOfferGroup(draft: VisitOfferDraft, groupId: string): VisitOfferDraft {
  return { ...draft, group_id: groupId, session_ids: draft.group_id === groupId ? draft.session_ids : [] };
}
export function visitOfferErrors(draft: VisitOfferDraft, groups: ActivityGroup[], publish: boolean, now = Date.now(), retainedSessionIds: string[] = []): VisitOfferErrors {
  const errors: VisitOfferErrors = {};
  if (!draft.title.trim()) errors.title = 'Въведете публично заглавие.';
  const group = groups.find((item) => item.id === draft.group_id);
  if (!group) errors.group_id = 'Изберете група от тази дейност.';
  const integer = (key: keyof VisitOfferDraft, minimum: number) => {
    const value = draft[key]; if (typeof value !== 'number' || !Number.isSafeInteger(value) || value < minimum) errors[key] = `Въведете цяло число, не по-малко от ${minimum}.`;
  };
  integer('visit_limit', 1); integer('booking_deadline_hours', 0); integer('cancellation_deadline_hours', 0);
  if (draft.type === 'trial') { integer('trial_limit_per_academic_year', 1); if (draft.no_show_trial_limit !== null) integer('no_show_trial_limit', 1); }
  if (draft.type === 'single' && !draft.unlimited_single_visits) { integer('single_visit_limit', 1); integer('single_visit_window_days', 1); }
  if (draft.fee_type !== 'free') {
    try { parseEuroCents(draft.amount); } catch { errors.amount_cents = invalidPriceMessage; }
    if (!draft.fee_reason?.trim()) errors.fee_reason = 'Обяснете публично какво покрива таксата.';
  }
  if (draft.session_ids.some((id) => !group?.sessions.some((session) => session.id === id))) errors.session_ids = 'Изберете занятия от избраната група.';
  if (publish) {
    if (!draft.session_ids.length || draft.session_ids.some((id) => !retainedSessionIds.includes(id) && !group?.sessions.some((s) => s.id === id && s.status === 'scheduled' && Date.parse(s.starts_at) > now))) errors.session_ids = 'Новите занятия трябва да са бъдещи и неотменени. Изберете поне едно занятие.';
    const conflicts = group?.conflicts.filter((item) => item.severity === 'blocking');
    if (conflicts?.length) errors.session_ids = conflicts.map((item) => item.message).join(' ');
    if (!draft.terms_version?.trim()) errors.terms_version = 'Посочете издание на условията преди публикуване.';
  }
  return errors;
}
export function campaignMembershipChange(offer: Pick<VisitOffer, 'id' | 'version' | 'campaign_id'>, nextId: string, campaigns: OpenDayCampaign[]): { id: string; body: CampaignMembershipInput } | null {
  if ((offer.campaign_id ?? '') === nextId) return null;
  const target = campaigns.find((item) => item.id === (nextId || offer.campaign_id));
  const source = campaigns.find((item) => item.id === offer.campaign_id);
  if (source?.status === 'cancelled' || target?.status === 'cancelled') throw new CampaignMembershipLockedError(cancelledCampaignMembershipMessage);
  if (!target || (offer.campaign_id && !source)) throw new Error('Кампанията е променена. Заредете актуалните данни.');
  return { id: target.id, body: { version: target.version, visit_offer_ids: nextId ? [...new Set([...target.visit_offers.map((item) => item.id), offer.id])] : target.visit_offers.filter((item) => item.id !== offer.id).map((item) => item.id), visit_offer_versions: { [offer.id]: offer.version }, source_campaign_versions: source && source.id !== target.id ? { [source.id]: source.version } : {} } };
}

export type CompletenessStep = { id: string; label: string; done: boolean };
export type ActivityCompleteness = { done: number; total: number; steps: CompletenessStep[]; next?: CompletenessStep };

// Values the old quick-create form stored before the organisation filled them in.
const placeholderSummary = 'Добавете кратко описание на дейността.';
const placeholderAge = 'Добавете възраст';
const placeholderCategory = 'Друго';

const filled = (value?: string | null) => Boolean(value && value.trim());

/**
 * What still separates an activity from a page parents can trust.
 * Groups and schedules are loaded per activity and are checked in the editor.
 */
export function activityCompleteness(activity: ActivityDraft): ActivityCompleteness {
  const offering = activity.offering;
  const steps: CompletenessStep[] = [
    { id: 'basics', label: 'Основна информация', done: filled(activity.title) && activity.category !== placeholderCategory && filled(activity.summary) && activity.summary !== placeholderSummary },
    { id: 'audience', label: 'Аудитория', done: filled(activity.age_label) && activity.age_label !== placeholderAge },
    { id: 'story', label: 'Представяне и ползи', done: filled(activity.sections?.about) },
    { id: 'media', label: 'Снимки', done: filled(activity.hero_image_path) },
    { id: 'people', label: 'Място и преподавател', done: Boolean(offering?.location_id && offering?.lead_instructor_profile_id) },
    { id: 'price', label: 'Цени и записване', done: Boolean(offering?.price_options?.length) },
    { id: 'terms', label: 'Условия', done: filled(offering?.terms) || filled(offering?.cancellation_policy) },
  ];
  const done = steps.filter((step) => step.done).length;
  return { done, total: steps.length, steps, next: steps.find((step) => !step.done) };
}

export const activityCategories = ['Спорт и движение', 'Изкуства', 'Наука и технологии', 'Музика', 'Езици и четене', 'Логика и игри', 'Друго'] as const;
