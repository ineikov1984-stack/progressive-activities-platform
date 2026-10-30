import type { ActivitySections, Availability, PublicActivityCard, PublicLocation, PublicPrice, PublicSchedule, PublicVisitOffer } from './api';

const weekdays = ['', 'понеделник', 'вторник', 'сряда', 'четвъртък', 'петък', 'събота', 'неделя'];
/** Сряда, събота и неделя are feminine: „всяка сряда“, but „всеки вторник“. */
const feminineWeekdays = new Set([3, 6, 7]);
const unitLabels: Record<string, string> = { session: 'занимание', month: 'месец', period: 'период', package: 'пакет' };

export function formatPublicPrice(price: Pick<PublicPrice, 'amount_cents' | 'currency' | 'unit'>): string {
  if (!Number.isInteger(price.amount_cents)) throw new Error('Цената трябва да е в цели евроцентове.');
  return `${new Intl.NumberFormat('bg-BG', { style: 'currency', currency: price.currency, minimumFractionDigits: 2 }).format(price.amount_cents / 100)} / ${unitLabels[price.unit] ?? price.unit}`;
}

export function availabilityLabel(availability: Availability): string {
  switch (availability.status) {
    case 'available': return 'Има места';
    case 'limited': return availability.remaining_places === null ? 'Малко свободни места' : `Остават ${availability.remaining_places} места`;
    case 'last_place': return 'Последно свободно място';
    case 'full': return 'Няма свободни места';
    case 'closed': return 'Записването е затворено';
  }
}

export const meaningfulText = (value?: string | null): string => value?.trim() ?? '';
export const safeAccent = (value?: string | null): string => /^#[0-9a-f]{6}$/i.test(value ?? '') ? value! : '#2f8b6d';

export function publicBadges(card: Pick<PublicActivityCard, 'availability' | 'special_offer'>): string[] {
  const special = { trial: 'Пробно занимание', single: 'Еднократно посещение', open_day: 'Отворени врати' };
  return [availabilityLabel(card.availability), ...(card.special_offer ? [special[card.special_offer]] : [])];
}

export const audienceLabel = (card: Pick<PublicActivityCard, 'age_label' | 'audience'>): string => meaningfulText(card.audience) || meaningfulText(card.age_label);

export function cardPriceLabel(price?: Pick<PublicPrice, 'label' | 'from'> | null): string {
  const label = meaningfulText(price?.label);
  if (!label) return 'Все още не е обявено';
  return price?.from && !/^От\s/i.test(label) ? `От ${label}` : label;
}

export function visitPriceLabel(offer: Pick<PublicVisitOffer, 'fee_type' | 'amount_cents' | 'currency'>): string {
  if (offer.fee_type === 'free') return 'Безплатно';
  const amount = new Intl.NumberFormat('bg-BG', { style: 'currency', currency: offer.currency, minimumFractionDigits: 2 }).format(offer.amount_cents / 100);
  return `${offer.fee_type === 'from_price' ? 'От ' : ''}${amount} / посещение`;
}

export function offerAction(action: PublicVisitOffer['action']): { label: string } {
  return { label: { apply: 'Записването е отворено', waitlist: 'Местата са заети — има списък на чакащите', closed: 'Записването е затворено' }[action] };
}

export function offerTarget(count: number): { href: string; label: string } {
  return count === 0 ? { href: '#groups', label: 'Вижте графика' } : { href: '#visit-offers', label: count === 1 ? 'Вижте посещението' : 'Изберете посещение' };
}

/** „До 12 часа преди началото“, „До 1 час преди началото“, „До началото“. */
export function bookingDeadlineLabel(hours: number): string {
  if (hours <= 0) return 'До началото';
  return `До ${hours} ${hours === 1 ? 'час' : 'часа'} преди началото`;
}

export function localDayLabel(date: string): string {
  return new Intl.DateTimeFormat('bg-BG', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${date}T12:00:00Z`));
}

export function visitSchedule(offer: Pick<PublicVisitOffer, 'starts_at' | 'ends_at' | 'timezone'>): string {
  const options = { timeZone: offer.timezone };
  const date = new Intl.DateTimeFormat('bg-BG', { ...options, day: 'numeric', month: 'long', year: 'numeric' });
  const time = new Intl.DateTimeFormat('bg-BG', { ...options, hour: '2-digit', minute: '2-digit' });
  const start = new Date(offer.starts_at); const end = new Date(offer.ends_at);
  const endDate = date.format(start) === date.format(end) ? '' : `${date.format(end)} · `;
  return `${date.format(start)} · ${time.format(start)}–${endDate}${time.format(end)}`;
}

function endTime(start: string, minutes: number): string {
  const [hour, minute] = start.split(':').map(Number);
  const total = hour * 60 + minute + minutes;
  return `${String(Math.floor(total / 60) % 24).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}

export function summarizePublicSchedule(schedule?: PublicSchedule | null): string {
  const slot = schedule?.slots?.[0];
  if (!slot) return 'Графикът още не е обявен';
  const day = weekdays[slot.weekday] ?? 'избран ден';
  const frequency = schedule?.frequency === 'biweekly'
    ? `През седмица ${slot.weekday === 2 ? 'във' : 'в'} ${day}`
    : `${feminineWeekdays.has(slot.weekday) ? 'Всяка' : 'Всеки'} ${day}`;
  return `${frequency} · ${slot.starts_at}–${endTime(slot.starts_at, slot.duration_minutes)}`;
}

export function groupScheduleLines(schedule?: PublicSchedule | null): string[] {
  if (!schedule?.slots?.length) return ['Графикът още не е обявен'];
  return schedule.slots.map((slot) => summarizePublicSchedule({ ...schedule, slots: [slot] }));
}

export function optionalActivitySections(sections: ActivitySections): { key: keyof ActivitySections; title: string; value: unknown }[] {
  const cleaned = cleanActivitySections(sections);
  const titles: Partial<Record<keyof ActivitySections, string>> = {
    about: 'За дейността', benefits: 'Какво развива заниманието?', methodology: 'Как протичат заниманията?',
    participation_terms: 'Условия за участие', instructor_intro: 'Нашият екип', gallery: 'Моменти от заниманията', faq: 'Често задавани въпроси',
  };
  return (Object.keys(titles) as (keyof ActivitySections)[]).flatMap((key) => {
    const value = cleaned[key];
    if (value === null || value === undefined || value === '' || (typeof value === 'string' && value.trim() === '') || (Array.isArray(value) && value.length === 0)) return [];
    return [{ key, title: titles[key]!, value }];
  });
}

export function cleanActivitySections(sections: ActivitySections): ActivitySections {
  return {
    about: meaningfulText(sections.about), methodology: meaningfulText(sections.methodology), participation_terms: meaningfulText(sections.participation_terms), instructor_intro: meaningfulText(sections.instructor_intro),
    benefits: (sections.benefits ?? []).map(meaningfulText).filter(Boolean),
    faq: (sections.faq ?? []).map((item) => ({ question: meaningfulText(item.question), answer: meaningfulText(item.answer) })).filter((item) => item.question && item.answer),
    gallery: (sections.gallery ?? []).map((item) => ({ url: meaningfulText(item.url), alt: meaningfulText(item.alt) })).filter((item) => item.url),
  };
}

export function navigationAction(location?: Pick<PublicLocation, 'type' | 'navigation_url'> | null): { label: string; href: string } | null {
  if (location?.type !== 'physical' || !meaningfulText(location.navigation_url)) return null;
  return { label: 'Как да стигнете', href: meaningfulText(location.navigation_url) };
}

export const enrollmentCta = 'Онлайн записването предстои. Дотогава се свържете с организацията.';
