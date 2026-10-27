import type { ActivitySections, Availability, PublicLocation, PublicPrice, PublicSchedule } from './api';

const weekdays = ['', 'понеделник', 'вторник', 'сряда', 'четвъртък', 'петък', 'събота', 'неделя'];
const unitLabels: Record<string, string> = { session: 'занимание', month: 'месец', period: 'период', package: 'пакет' };

export function formatPublicPrice(price: Pick<PublicPrice, 'amount_cents' | 'currency' | 'unit'>): string {
  if (!Number.isInteger(price.amount_cents)) throw new Error('Цената трябва да е в цели евроцентове.');
  return `${new Intl.NumberFormat('bg-BG', { style: 'currency', currency: price.currency, minimumFractionDigits: 2 }).format(price.amount_cents / 100)} / ${unitLabels[price.unit] ?? price.unit}`;
}

export function availabilityLabel(availability: Availability): string {
  if (availability.status === 'limited' && availability.remaining_places !== null) return `Остават ${availability.remaining_places} места`;
  return 'Има места';
}

function endTime(start: string, minutes: number): string {
  const [hour, minute] = start.split(':').map(Number);
  const total = hour * 60 + minute + minutes;
  return `${String(Math.floor(total / 60) % 24).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}

export function summarizePublicSchedule(schedule?: PublicSchedule | null): string {
  const slot = schedule?.slots?.[0];
  if (!slot) return 'Графикът ще бъде обявен скоро';
  const day = weekdays[slot.weekday] ?? 'избран ден';
  const frequency = schedule?.frequency === 'biweekly' ? `През седмица, ${day}` : `Всеки ${day}`;
  return `${frequency} · ${slot.starts_at}–${endTime(slot.starts_at, slot.duration_minutes)}`;
}

export function optionalActivitySections(sections: ActivitySections): { key: keyof ActivitySections; title: string; value: unknown }[] {
  const titles: Partial<Record<keyof ActivitySections, string>> = {
    about: 'За дейността', benefits: 'Какво развива заниманието?', methodology: 'Как протичат заниманията?',
    participation_terms: 'Условия за участие', instructor_intro: 'Нашият екип', gallery: 'Моменти от заниманията', faq: 'Често задавани въпроси',
  };
  return (Object.keys(titles) as (keyof ActivitySections)[]).flatMap((key) => {
    const value = sections[key];
    if (value === null || value === undefined || value === '' || (typeof value === 'string' && value.trim() === '') || (Array.isArray(value) && value.length === 0)) return [];
    return [{ key, title: titles[key]!, value }];
  });
}

export function navigationAction(location?: Pick<PublicLocation, 'type' | 'navigation_url'> | null): { label: string; href: string } | null {
  if (location?.type !== 'physical' || !location.navigation_url) return null;
  return { label: 'Навигация до мястото', href: location.navigation_url };
}

export const enrollmentCta = 'Записването ще бъде добавено в следващ етап';
