import type { ActivitySections, PriceOption, ScheduleConflict, SchedulePattern } from './api';

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
