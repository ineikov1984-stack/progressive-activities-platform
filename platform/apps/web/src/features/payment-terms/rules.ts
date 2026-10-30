import type { BillingKind, BillingKindInfo, Json, Preset, ReadyPreset, Rules, SelfService } from './api';

/**
 * Reading, changing and describing the numbers of a set of payment terms (docs/product/payment-policies.md,
 * т. 5, 6, 8). A percentage in the rules is always the share KEPT; the words say "плащате" or "връщаме".
 */

export const READY_PRESETS: ReadyPreset[] = ['flexible', 'standard', 'strict'];

/** The value at a dotted path ("pause.notice_days", "cancellation.tiers.0.kept"). */
export function valueAt(rules: Json, path: string): Json | undefined {
  let current: Json | undefined = rules;
  for (const key of path.split('.')) {
    if (Array.isArray(current)) current = current[Number(key)];
    else if (current !== null && typeof current === 'object') current = current[key];
    else return undefined;
  }
  return current;
}

export function numberAt(rules: Json, path: string): number | null {
  const value = valueAt(rules, path);
  return typeof value === 'number' ? value : null;
}

export function booleanAt(rules: Json, path: string): boolean {
  return valueAt(rules, path) === true;
}

/** A copy with one value replaced; the original is not changed. */
export function withValue<T extends Json>(rules: T, path: string, value: Json): T {
  const [key, ...rest] = path.split('.');
  if (Array.isArray(rules)) {
    const copy = [...rules];
    const index = Number(key);
    copy[index] = rest.length ? withValue(copy[index] ?? {}, rest.join('.'), value) : value;
    return copy as T;
  }
  const object = (rules !== null && typeof rules === 'object' ? rules : {}) as { [key: string]: Json };
  return { ...object, [key]: rest.length ? withValue(object[key] ?? {}, rest.join('.'), value) : value } as T;
}

/** The same numbers in one key order (the database may return JSON keys in another order). */
export function canonical(value: Json | SelfService): string {
  const sort = (item: Json): Json => {
    if (Array.isArray(item)) return item.map(sort);
    if (item !== null && typeof item === 'object') {
      return Object.fromEntries(Object.keys(item).sort().map((key) => [key, sort(item[key])]));
    }
    return item;
  };
  return JSON.stringify(sort(value as Json));
}

/** Which ready-made set these numbers are — the same rule as on the server. */
export function matchPreset(kind: BillingKindInfo, rules: Rules, selfService: SelfService): Preset {
  const found = READY_PRESETS.find((preset) => canonical(kind.presets[preset].rules) === canonical(rules)
    && canonical(kind.presets[preset].self_service) === canonical(selfService));
  return found ?? 'custom';
}

/** Server errors under "rules.end.cutoff_day" belong to the row that edits "end". */
export function errorsFor(errors: Record<string, string[]>, prefixes: string[]): string[] {
  const messages = Object.entries(errors)
    .filter(([key]) => prefixes.some((prefix) => key === prefix || key.startsWith(`${prefix}.`)))
    .flatMap(([, list]) => list);
  return [...new Set(messages)];
}

const PRESET_BLURBS: Partial<Record<BillingKind, Record<ReadyPreset, string>>> = {
  monthly: {
    flexible: 'Плаща само заниманията, на които е записано. Пропуснатите се наваксват.',
    standard: 'Спре ли до 15-то число — половин такса. Пауза без такса, ако ви кажат 7 дни по-рано.',
    strict: 'Започнатият месец се плаща цял. Отсъствията не намаляват таксата.',
  },
  visit: {
    flexible: 'Отказ до 12 часа преди — без такса.',
    standard: 'Отказ до 24 часа преди — без такса; до 12 часа — половината.',
    strict: 'Отказ до 48 часа преди — без такса; по-късно — цялата сума.',
  },
  residential_camp: {
    flexible: 'Капаро 10 %. Всичко се връща до 21 дни преди.',
    standard: 'Капаро 25 %. Всичко се връща до 30 дни преди.',
    strict: 'Капаро 30 %. Всичко се връща до 45 дни преди.',
  },
  day_camp: {
    flexible: 'Всичко се връща до 7 дни преди седмицата.',
    standard: 'Всичко се връща до 14 дни преди седмицата; после — половината.',
    strict: 'Всичко се връща до 14 дни преди; после — нищо, с кредит.',
  },
};

const GENERIC_BLURBS: Record<ReadyPreset, string> = {
  flexible: 'По-меки срокове, повече връщане и наваксване.',
  standard: 'Препоръчаният баланс за повечето организации.',
  strict: 'По-ранни срокове и по-малко връщане.',
};

/** What each way of paying means, in one line (the owner's review of 30.10.2026). */
export const KIND_HINTS: Record<BillingKind, string> = {
  monthly: 'Родителят плаща всеки месец.',
  visit: 'Плаща се за едно занимание.',
  full_period: 'Цялата сума за срока или курса отведнъж.',
  package: 'Карта за няколко занимания, напр. 8.',
  day_camp: 'Плаща се за седмица или за отделни дни.',
  residential_camp: 'Лагер с нощувки, обикновено с капаро.',
  kindergarten: 'Месечна такса за градина или занималня.',
};

export function presetBlurb(kind: BillingKind, preset: Preset): string {
  if (preset === 'custom') return 'Сменете което и да е число по-долу — условията стават ваши.';
  return PRESET_BLURBS[kind]?.[preset] ?? GENERIC_BLURBS[preset];
}

/** What the parent may do alone, per way of paying (review 1.10). */
export function selfServiceOptions(kind: BillingKind): Array<keyof SelfService> {
  switch (kind) {
    case 'visit': return ['cancel_visit'];
    case 'package': return ['absence', 'cancel_visit'];
    case 'day_camp':
    case 'residential_camp': return ['absence'];
    case 'kindergarten': return ['absence', 'end'];
    default: return ['absence', 'pause_weeks', 'end'];
  }
}

// ---- Words ----------------------------------------------------------------------------------------------

type Tier = { min: number; kept: number; credit: boolean };

function tiers(rules: Rules, path: string, unit: 'min_hours' | 'min_days'): Tier[] {
  const list = valueAt(rules, path);
  if (!Array.isArray(list)) return [];
  return list.map((tier) => ({
    min: numberAt(tier, unit) ?? 0,
    kept: numberAt(tier, 'kept') ?? 0,
    credit: booleanAt(tier, 'credit_offered'),
  }));
}

function capitalize(text: string): string {
  return text ? `${text.charAt(0).toUpperCase()}${text.slice(1)}` : text;
}

/** "плащате …": the share of a fee that is paid. */
export function paidShare(kept: number, whole: string, of: string): string {
  if (kept >= 100) return `цялата ${whole}`;
  if (kept <= 0) return 'нищо';
  if (kept === 50) return `половината ${of}`;
  return `${kept} % ${of}`;
}

/** "след това — …": a share said short. */
export function shortShare(kept: number): string {
  if (kept >= 100) return 'цялата';
  if (kept <= 0) return 'нищо';
  if (kept === 50) return 'половината';
  return `${kept} %`;
}

/** "връщаме …": the share that comes back. */
export function returnedShare(kept: number): string {
  const back = 100 - kept;
  if (back >= 100) return 'връщаме всичко';
  if (back <= 0) return 'не връщаме нищо';
  if (back === 50) return 'връщаме половината';
  return `връщаме ${back} %`;
}

/** "15-то", "1-во", "2-ро", "7-мо" число: the neuter ordinal ending of a day of the month. */
export function ordinalSuffix(day: number): string {
  if (day % 100 >= 11 && day % 100 <= 19) return 'то';
  const last = day % 10;
  if (last === 1) return 'во';
  if (last === 2) return 'ро';
  if (last === 7 || last === 8) return 'мо';
  return 'то';
}

function plural(count: number, one: string, many: string): string {
  return `${count} ${count === 1 ? one : many}`;
}

/** The ranges of a ladder in words: "поне 30 дни", "15–29 дни", "по-малко от 15 дни". */
export function ladderRanges(steps: number[], unit: 'h' | 'd'): string[] {
  const word = (count: number) => (unit === 'h' ? plural(count, 'час', 'часа') : plural(count, 'ден', 'дни'));
  return steps.map((min, index) => {
    if (steps.length === 1) return unit === 'h' ? 'по всяко време' : 'по всяко време преди началото';
    if (index === 0) return `поне ${word(min)}`;
    const upper = steps[index - 1];
    if (index === steps.length - 1) return `по-малко от ${word(upper)}`;
    return unit === 'h' ? `между ${min} и ${upper} часа` : `${min}–${upper - 1} дни`;
  });
}

export type TermsLine = { title: string; text: string };

function sentence(text: string): string {
  const trimmed = text.trim();
  return trimmed ? `${trimmed.charAt(0).toUpperCase()}${trimmed.slice(1)}${/[.!?]$/.test(trimmed) ? '' : '.'}` : '';
}

function monthPart(rules: Rules, part: 'start' | 'end'): string {
  const mode = valueAt(rules, `${part}.mode`);
  const day = numberAt(rules, `${part}.cutoff_day`) ?? 15;
  const until = numberAt(rules, `${part}.kept_until_cutoff`) ?? 100;
  const after = numberAt(rules, `${part}.kept_after_cutoff`) ?? 100;
  const fee = (kept: number) => paidShare(kept, 'такса за месеца', 'от таксата за месеца');
  if (part === 'end') {
    const notice = numberAt(rules, 'end.notice_days') ?? 0;
    const warn = notice > 0 ? ` Кажете ни поне ${plural(notice, 'ден', 'дни')} по-рано.` : '';
    if (mode === 'cutoff') return `Спрете ли до ${day}-${ordinalSuffix(day)} число, плащате ${fee(until)}; след това — ${shortShare(after)}.${warn}`;
    if (mode === 'full_month') return `Месецът, в който спирате, се плаща цял.${warn}`;
    return `Плащате само заниманията до последния ден.${warn}`;
  }
  if (mode === 'cutoff') return `Започнете ли до ${day}-${ordinalSuffix(day)} число, плащате ${paidShare(until, 'такса', 'от таксата')} за първия месец; след това — ${shortShare(after)}.`;
  if (mode === 'full_month') return 'Първият месец се плаща цял, независимо от деня на започване.';
  return 'За първия месец плащате само заниманията от първия ден.';
}

function pauseText(rules: Rules): string {
  const notice = numberAt(rules, 'pause.notice_days') ?? 0;
  const onTime = numberAt(rules, 'pause.kept_on_time') ?? 0;
  const late = numberAt(rules, 'pause.kept_late') ?? 0;
  const freeDays = numberAt(rules, 'pause.free_days_per_school_year') ?? 0;
  const minDays = numberAt(rules, 'pause.min_days') ?? 0;
  const share = (kept: number) => (kept <= 0 ? 'заниманията в паузата не се плащат' : `за заниманията в паузата плащате ${paidShare(kept, 'им стойност', 'от стойността им')}`);
  const extra = [
    minDays > 0 ? `Най-кратката пауза е ${plural(minDays, 'ден', 'дни')}.` : '',
    booleanAt(rules, 'pause.document_required') ? 'Пауза — само с бележка от лекар.' : '',
  ];
  // Without days at the on-time terms every pause counts as a late one.
  const parts = freeDays <= 0 ? [sentence(share(late)), ...extra] : [
    notice > 0
      ? `Кажете ни поне ${plural(notice, 'ден', 'дни')} по-рано и ${share(onTime)}. По-късно — ${late <= 0 ? 'също не се плащат' : shortShare(late)}.`
      : sentence(share(onTime)),
    // Beyond the yearly days a pause counts as a late one; said only when that changes what is paid.
    onTime === late
      ? `До ${plural(freeDays, 'ден', 'дни')} в учебната година.`
      : `До ${plural(freeDays, 'ден', 'дни')} в учебната година; над тях ${late <= 0 ? 'също не плащате' : `плащате ${paidShare(late, 'им стойност', 'от стойността им')}`}.`,
    ...extra,
  ];
  return parts.filter(Boolean).join(' ');
}

function absencesText(rules: Rules): string {
  const list = valueAt(rules, 'absences.tiers');
  const steps = Array.isArray(list) ? list.map((_, index) => ({ upTo: numberAt(list, `${index}.up_to`), kept: numberAt(list, `${index}.kept`) ?? 100 })) : [];
  const fee = (kept: number) => paidShare(kept, 'такса', 'от таксата');
  if (steps.length <= 1) {
    const kept = steps[0]?.kept ?? 100;
    return kept >= 100 ? 'Отсъствията не намаляват таксата за месеца.' : `При отсъствия плащате ${fee(kept)} за месеца.`;
  }
  return sentence(steps.map((step, index) => (step.upTo === null || index === steps.length - 1
    ? `при повече — ${shortShare(step.kept)}`
    : `до ${plural(step.upTo, 'отсъствие', 'отсъствия')} в месеца — ${fee(step.kept)}`)).join(', '));
}

function makeupText(rules: Rules): string {
  if (!booleanAt(rules, 'makeup.enabled')) return 'Пропуснатите занимания не се наваксват.';
  const within = numberAt(rules, 'makeup.within_days') ?? 14;
  const hours = numberAt(rules, 'makeup.notice_hours') ?? 0;
  const perMonth = numberAt(rules, 'makeup.per_month');
  return sentence([
    `пропуснато занимание наваксвате до ${plural(within, 'ден', 'дни')}`,
    hours > 0 ? `ако ни кажете поне ${plural(hours, 'час', 'часа')} по-рано` : '',
    perMonth !== null ? `до ${perMonth} в месеца` : '',
    booleanAt(rules, 'makeup.only_if_space') ? 'когато има свободно място' : '',
  ].filter(Boolean).join(', '));
}

function refundText(rules: Rules): string {
  switch (valueAt(rules, 'refund')) {
    case 'money': return 'Връщаме парите по същия начин, по който сте платили, до 14 дни.';
    case 'credit': return 'Върнатата сума остава като кредит за следващо плащане.';
    default: return 'Пари или кредит — по ваш избор.';
  }
}

function cancellationText(steps: Tier[], unit: 'h' | 'd', whole: string, of: string, before: string, creditFor: string | null, returning: boolean): string {
  const ranges = ladderRanges(steps.map((step) => step.min), unit);
  return steps.map((step, index) => {
    const range = capitalize(`${ranges[index]}${steps.length > 1 ? ` ${before}` : ''}`);
    const result = returning ? returnedShare(step.kept) : (step.kept <= 0 ? 'не плащате' : `плащате ${paidShare(step.kept, whole, of)}`);
    const credit = creditFor && step.credit ? `, но предлагаме кредит за ${creditFor}` : '';
    return `${range} — ${result}${credit}.`;
  }).join(' ');
}

/** "Така го виждат родителите": every rule of the set in plain words. */
export function describeTerms(kind: BillingKind, rules: Rules): TermsLine[] {
  switch (kind) {
    case 'monthly': {
      const lines: TermsLine[] = [];
      if (valueAt(rules, 'start.mode') !== 'by_sessions') lines.push({ title: 'Ако започнете по средата на месеца', text: monthPart(rules, 'start') });
      lines.push(
        { title: 'Ако спрете', text: monthPart(rules, 'end') },
        { title: 'Пауза', text: pauseText(rules) },
        { title: 'Отсъствия', text: `${absencesText(rules)} ${makeupText(rules)}` },
        { title: 'Връщане', text: refundText(rules) },
      );
      return lines;
    }
    case 'full_period': {
      const fullDays = numberAt(rules, 'after_start.full_refund_days') ?? 0;
      const kept = numberAt(rules, 'after_start.kept_of_remaining') ?? 0;
      const then = fullDays > 0 ? 'След това' : 'След началото';
      const rest = kept >= 100 ? 'не връщаме нищо за оставащите занимания' : kept <= 0 ? 'връщаме всичко за оставащите занимания' : `${returnedShare(kept)} от стойността на оставащите занимания`;
      const after = [
        fullDays > 0 ? `През първите ${plural(fullDays, 'ден', 'дни')} след началото връщаме всичко за оставащите занимания.` : '',
        `${then} ${rest}.`,
      ].filter(Boolean).join(' ');
      return [
        { title: 'Отказ преди началото', text: cancellationText(tiers(rules, 'before_start.tiers', 'min_days'), 'd', 'сума', 'от сумата', 'преди началото', null, true) },
        { title: 'Отказ след началото', text: after },
        { title: 'Пауза', text: pauseText(rules) },
        { title: 'Отсъствия', text: makeupText(rules) },
        { title: 'Връщане', text: refundText(rules) },
      ];
    }
    case 'visit': {
      const noShow = numberAt(rules, 'no_show_kept') ?? 100;
      return [
        { title: 'Отказ', text: cancellationText(tiers(rules, 'cancellation.tiers', 'min_hours'), 'h', 'сума', 'от сумата', 'преди началото', null, false) },
        { title: 'Неявяване', text: `Ако не дойдете, без да откажете, ${noShow <= 0 ? 'не плащате' : `плащате ${paidShare(noShow, 'сума', 'от сумата')}`}.` },
        { title: 'Връщане', text: refundText(rules) },
      ];
    }
    case 'package': {
      const freeze = numberAt(rules, 'freeze_days_with_document') ?? 0;
      const extend = numberAt(rules, 'extension_days_on_request') ?? 0;
      return [
        { title: 'Замразяване', text: freeze > 0 ? `С бележка от лекар картата се замразява до ${plural(freeze, 'ден', 'дни')}.` : 'Картата не се замразява.' },
        { title: 'Удължаване', text: extend > 0 ? `По молба срокът на картата се удължава с до ${plural(extend, 'ден', 'дни')}.` : 'Срокът на картата не се удължава.' },
        { title: 'Връщане', text: refundText(rules) },
      ];
    }
    case 'day_camp': {
      const firstDays = numberAt(rules, 'absences.kept_first_days') ?? 0;
      const keptAfter = numberAt(rules, 'absences.kept_after') ?? 0;
      const change = numberAt(rules, 'week_change_free_until_days') ?? 0;
      const absence = keptAfter >= 100
        ? 'Дните отсъствие не се връщат.'
        : `${firstDays > 0 ? `Първите ${plural(firstDays, 'ден', 'дни')} отсъствие не се връщат; за всеки следващ` : 'За всеки ден отсъствие'} ${returnedShare(keptAfter)} от дневната цена.`;
      return [
        { title: 'Отказ преди седмицата', text: cancellationText(tiers(rules, 'before_start.tiers', 'min_days'), 'd', 'сума', 'от сумата', 'преди седмицата', 'друга седмица', true) },
        { title: 'Отсъствия', text: absence },
        ...(change > 0 ? [{ title: 'Смяна на седмицата', text: `Без такса до ${plural(change, 'ден', 'дни')} преди началото ѝ.` }] : []),
        { title: 'Връщане', text: refundText(rules) },
      ];
    }
    case 'residential_camp': {
      const mode = valueAt(rules, 'deposit.mode');
      const cents = numberAt(rules, 'deposit.amount_cents') ?? 0;
      const percent = numberAt(rules, 'deposit.percent') ?? 0;
      const illness = numberAt(rules, 'illness_with_document.kept') ?? 0;
      const costs = booleanAt(rules, 'illness_with_document.plus_costs');
      const transfer = numberAt(rules, 'transfer_to_another_child_until_days') ?? 0;
      const early = numberAt(rules, 'early_departure_kept') ?? 100;
      const lines: TermsLine[] = [];
      if (mode === 'amount') lines.push({ title: 'Капаро', text: `${money(cents / 100)} при записване — приспада се от цената.` });
      if (mode === 'percent') lines.push({ title: 'Капаро', text: `${percent} % от цената при записване — приспада се от нея.` });
      lines.push(
        { title: 'Отказ преди тръгване', text: cancellationText(tiers(rules, 'before_start.tiers', 'min_days'), 'd', 'сума', 'от сумата', 'преди тръгване', 'друга смяна', true) },
        { title: 'Болест', text: `При болест преди тръгване с бележка от лекар ${returnedShare(illness)}${costs ? ', като приспадаме реално направените разходи' : ''}.` },
      );
      if (transfer > 0) lines.push({ title: 'Друго дете', text: `Друго дете може да заеме мястото до ${plural(transfer, 'ден', 'дни')} преди тръгване — без такса.` });
      lines.push(
        { title: 'Ранно прибиране', text: early >= 100 ? 'Неизползваните дни не се връщат.' : `${capitalize(returnedShare(early))} от неизползваните дни.` },
        { title: 'Връщане', text: refundText(rules) },
      );
      return lines;
    }
    case 'kindergarten': {
      const notice = numberAt(rules, 'notice_days') ?? 0;
      const sickDays = numberAt(rules, 'sick_over_working_days') ?? 0;
      const sickKept = numberAt(rules, 'sick_next_month_kept') ?? 100;
      return [
        { title: 'Напускане', text: notice > 0 ? `С предизвестие ${plural(notice, 'ден', 'дни')}.` : 'Без предизвестие.' },
        ...((numberAt(rules, 'deposit_months') ?? 0) > 0 ? [{ title: 'Депозит', text: 'Една месечна такса при записване.' }] : []),
        ...(booleanAt(rules, 'meals_by_attendance') ? [{ title: 'Храна', text: 'Плаща се според присъствието.' }] : []),
        ...(sickDays > 0 && sickKept < 100 ? [{ title: 'Болест', text: `При болест над ${plural(sickDays, 'работен ден', 'работни дни')} в месеца за следващия месец плащате ${paidShare(sickKept, 'такса', 'от таксата')}.` }] : []),
        { title: 'Връщане', text: refundText(rules) },
      ];
    }
  }
}

// ---- An example with sums -----------------------------------------------------------------------------

export function money(amount: number): string {
  const rounded = Math.round(amount * 100) / 100;
  return `${Number.isInteger(rounded) ? rounded : rounded.toFixed(2).replace('.', ',')} €`;
}

function tierFor(steps: Tier[], value: number): Tier | undefined {
  return steps.find((step) => value >= step.min) ?? steps[steps.length - 1];
}

function samples(steps: Tier[]): number[] {
  return steps.map((step, index) => {
    if (index === 0) return step.min + (steps.length === 1 ? 1 : 6);
    if (index === steps.length - 1) return Math.max(0, Math.floor(steps[index - 1].min / 2));
    return Math.floor((step.min + steps[index - 1].min) / 2);
  });
}

/**
 * A worked example with illustrative sums. Not a calculation of what anyone owes — that comes with the
 * calculator (step 3a).
 */
export function exampleFor(kind: BillingKind, rules: Rules): { intro: string; lines: string[] } | null {
  switch (kind) {
    case 'monthly': {
      // November 2026 on Tuesdays: 3, 10, 17 and 24 — 15 € a session.
      const fee = 60;
      const end = (day: number): number => {
        const mode = valueAt(rules, 'end.mode');
        if (mode === 'full_month') return fee;
        if (mode === 'cutoff') return fee * ((day <= (numberAt(rules, 'end.cutoff_day') ?? 15) ? numberAt(rules, 'end.kept_until_cutoff') : numberAt(rules, 'end.kept_after_cutoff')) ?? 100) / 100;
        return 15 * [3, 10, 17, 24].filter((session) => session <= day).length;
      };
      const list = valueAt(rules, 'absences.tiers');
      const steps = Array.isArray(list) ? list.map((_, index) => ({ upTo: numberAt(list, `${index}.up_to`), kept: numberAt(list, `${index}.kept`) ?? 100 })) : [];
      const absences = (count: number) => fee * ((steps.find((step) => step.upTo === null || count <= step.upTo) ?? steps[steps.length - 1])?.kept ?? 100) / 100;
      return {
        intro: 'Такса 60 € на месец, 4 занимания в ноември.',
        lines: [
          `Последен ден 10 ноември → за ноември плащате ${money(end(10))}.`,
          `Последен ден 20 ноември → плащате ${money(end(20))}.`,
          `4 отсъствия в ноември → плащате ${money(absences(4))}.`,
        ],
      };
    }
    case 'visit': {
      const steps = tiers(rules, 'cancellation.tiers', 'min_hours');
      return {
        intro: 'Пробно занимание за 15 €.',
        lines: samples(steps).map((hours) => `Отказ ${plural(hours, 'час', 'часа')} преди → плащате ${money(15 * (tierFor(steps, hours)?.kept ?? 100) / 100)}.`),
      };
    }
    case 'residential_camp': {
      const steps = tiers(rules, 'before_start.tiers', 'min_days');
      return {
        intro: 'Лагер за 600 €.',
        lines: samples(steps).map((days) => `Отказ ${plural(days, 'ден', 'дни')} преди тръгване → връщаме ${money(600 * (100 - (tierFor(steps, days)?.kept ?? 100)) / 100)}.`),
      };
    }
    case 'day_camp': {
      const firstDays = numberAt(rules, 'absences.kept_first_days') ?? 0;
      const keptAfter = numberAt(rules, 'absences.kept_after') ?? 0;
      const back = Math.max(0, 3 - firstDays) * 30 * (100 - keptAfter) / 100;
      return {
        intro: 'Седмица за 150 € (30 € на ден).',
        lines: [`3 дни отсъствие → връщаме ${money(back)}.`],
      };
    }
    default:
      return null;
  }
}

// ---- Examples under each rule (a fee of 60 € a month, Tuesdays in November 2026: 3, 10, 17 and 24) -------

const NOVEMBER = [3, 10, 17, 24];

function monthShare(rules: Rules, part: 'start' | 'end', mode: Json | undefined, day: number): number {
  if (mode === 'full_month') return 60;
  if (mode === 'cutoff') {
    const kept = day <= (numberAt(rules, `${part}.cutoff_day`) ?? 15) ? numberAt(rules, `${part}.kept_until_cutoff`) : numberAt(rules, `${part}.kept_after_cutoff`);
    return 60 * (kept ?? 100) / 100;
  }
  return 15 * NOVEMBER.filter((session) => (part === 'end' ? session <= day : session >= day)).length;
}

/** "Спира на 10 ноември → 30 €" for one choice of the month the child stops or starts in. */
export function monthChoiceExample(rules: Rules, part: 'start' | 'end', mode: string): string {
  const verb = part === 'end' ? 'Спира' : 'Започва';
  if (mode === 'by_sessions') {
    const day = part === 'end' ? 10 : 17;
    const sessions = NOVEMBER.filter((session) => (part === 'end' ? session <= day : session >= day)).length;
    return `${verb} на ${day} ноември → плаща ${sessions} занимания = ${money(15 * sessions)}.`;
  }
  if (mode === 'full_month') return `${verb} на ${part === 'end' ? 10 : 17} ноември → ${money(60)}.`;
  return `${verb} на 10 ноември → ${money(monthShare(rules, part, mode, 10))}. ${verb} на 20 ноември → ${money(monthShare(rules, part, mode, 20))}.`;
}

/** A pause as long as the shortest one allowed: 2–15 November (2 sessions) or the whole month (4). */
export function pauseExample(rules: Rules): string | null {
  const notice = numberAt(rules, 'pause.notice_days') ?? 0;
  const onTime = numberAt(rules, 'pause.kept_on_time') ?? 0;
  const late = numberAt(rules, 'pause.kept_late') ?? 0;
  const minDays = numberAt(rules, 'pause.min_days') ?? 0;
  if (minDays > 29) return null;
  const [range, sessions] = minDays <= 14 ? ['2–15 ноември', 2] : ['2–30 ноември', 4];
  const paid = (kept: number) => money(60 - 15 * sessions + 15 * sessions * kept / 100);
  const pause = `Пример: пауза ${range} (${sessions} занимания)`;
  if (notice <= 0 || onTime === late) return `${pause} → за ноември ${paid(onTime)}.`;
  return `${pause}. Казано навреме → за ноември ${paid(onTime)}. Казано късно → ${paid(late)}.`;
}

export function absenceExample(rules: Rules): string {
  const list = valueAt(rules, 'absences.tiers');
  const steps = Array.isArray(list) ? list.map((tier) => ({ upTo: numberAt(tier, 'up_to'), kept: numberAt(tier, 'kept') ?? 100 })) : [];
  const kept = (steps.find((step) => step.upTo === null || 4 <= step.upTo) ?? steps[steps.length - 1])?.kept ?? 100;
  const amount = 60 * kept / 100;
  return `Пример: 4 отсъствия в ноември → ${money(amount)}${amount < 60 ? ' вместо 60 €' : ''}.`;
}
