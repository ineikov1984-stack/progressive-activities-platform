import type { ActivityDraft, ActivityInput, PriceOption } from '../api';
import { parseEuroCents } from '../presentation';

export type PriceDraft = Omit<PriceOption, 'amount_cents'> & { amount: string };

/** Offering fields edited in steps 5 and 6 of the wizard. */
export type OfferingDraft = {
  location_id: string | null;
  lead_instructor_profile_id: string | null;
  contact_email: string;
  enrollment_instructions: string;
  cancellation_policy: string;
  terms: string;
  /** A set of payment terms chosen for the activity; null — the organization's terms. */
  payment_terms_id: string | null;
  prices: PriceDraft[];
};

export const enrollmentModes = [
  { value: 'monthly', label: 'Месечен абонамент' },
  { value: 'full_period', label: 'За целия период' },
  { value: 'package', label: 'Пакет посещения' },
  { value: 'single', label: 'Еднократно посещение' },
  { value: 'trial', label: 'Пробно занимание' },
] as const;

export const priceUnits = [
  { value: 'month', label: 'на месец' },
  { value: 'session', label: 'за занимание' },
  { value: 'period', label: 'за периода' },
  { value: 'package', label: 'за пакета' },
] as const;

/** One sentence per way of enrolling, so people pick the right one without guessing. */
export const modeHints: Record<string, string> = {
  monthly: 'Родителят плаща всеки месец, докато детето посещава.',
  full_period: 'Една цена за целия период на групата — например учебен срок или сезон.',
  package: 'Определен брой посещения за фиксирана цена. Напишете броя и срока в условията.',
  single: 'Плащане за едно посещение, без записване за период.',
  trial: 'Първо посещение, за да опитат. Безплатни пробни посещения се правят в стъпка 8.',
};

/** A sensible unit for each way of enrolling, so most people never touch the unit. */
export const defaultUnit: Record<string, string> = { monthly: 'month', full_period: 'period', package: 'package', single: 'session', trial: 'session' };

export function amountText(cents: number): string {
  return (cents / 100).toFixed(2).replace('.', ',');
}

export function blankPrice(): PriceDraft {
  return { enrollment_mode: 'monthly', unit: 'month', amount: '', currency: 'EUR', is_available: true, terms: '' };
}

export function offeringFromActivity(activity: ActivityDraft): OfferingDraft | null {
  const offering = activity.offering;
  if (!offering) return null;
  return {
    location_id: offering.location_id ?? null,
    lead_instructor_profile_id: offering.lead_instructor_profile_id ?? null,
    // The organization's address unless the activity has its own (decision of 30.10.2026).
    contact_email: offering.contact_email || offering.organization_contact_email || '',
    enrollment_instructions: offering.enrollment_instructions ?? '',
    cancellation_policy: offering.cancellation_policy ?? '',
    terms: offering.terms ?? '',
    payment_terms_id: offering.payment_terms_id ?? null,
    prices: offering.price_options.map(({ amount_cents, ...price }) => ({ ...price, amount: amountText(amount_cents) })),
  };
}

const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export type OfferingCheck = { payload: NonNullable<ActivityInput['offering']>; problems: Record<string, string> };

/**
 * Builds what the API receives and lists problems per field (in Bulgarian).
 * Fields with problems are left out, so the rest of the work is still saved.
 */
export function checkOffering(draft: OfferingDraft): OfferingCheck {
  const problems: Record<string, string> = {};
  const email = draft.contact_email.trim();
  if (!email) problems.contact_email = 'Въведете имейл — на него родителите пишат с въпроси.';
  else if (!emailPattern.test(email)) problems.contact_email = 'Проверете имейла — например info@vashata-shkola.bg.';

  const prices: PriceOption[] = [];
  const seenModes = new Set<string>();
  draft.prices.forEach((price, index) => {
    if (seenModes.has(price.enrollment_mode)) {
      problems[`price_${index}`] = 'Този начин на записване вече има цена. Изберете друг или премахнете едната.';
      return;
    }
    seenModes.add(price.enrollment_mode);
    try {
      // Only the fields the API accepts; loaded options can carry more.
      prices.push({
        enrollment_mode: price.enrollment_mode,
        unit: price.unit,
        amount_cents: parseEuroCents(price.amount),
        currency: 'EUR',
        terms: price.terms?.trim() || null,
        is_available: price.is_available !== false,
      });
    } catch (error) {
      problems[`price_${index}`] = error instanceof Error ? error.message : 'Проверете цената.';
    }
  });

  const payload: NonNullable<ActivityInput['offering']> = {
    location_id: draft.location_id,
    lead_instructor_profile_id: draft.lead_instructor_profile_id,
    enrollment_instructions: draft.enrollment_instructions.trim() || null,
    cancellation_policy: draft.cancellation_policy.trim() || null,
    terms: draft.terms.trim() || null,
    payment_terms_id: draft.payment_terms_id,
  };
  if (!problems.contact_email) payload.contact_email = email;
  // Prices are replaced as a whole on the server, so they are sent only when all are valid.
  if (!Object.keys(problems).some((key) => key.startsWith('price_'))) payload.price_options = prices;
  return { payload, problems };
}
