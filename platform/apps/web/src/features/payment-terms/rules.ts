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
    flexible: 'В непълен месец родителят плаща само заниманията, на които детето е записано. Пропуснатите занимания се наваксват.',
    standard: 'Ако детето спре до 15-о число, родителят плаща половината от таксата. Паузата е без такса, ако ви предупредят 7 дни по-рано.',
    strict: 'Месецът, в който детето спира, се плаща цял. Отсъствията не намаляват таксата.',
  },
  visit: {
    flexible: 'При отказ поне 12 часа преди посещението родителят не плаща нищо.',
    standard: 'При отказ поне 24 часа преди посещението родителят не плаща нищо. При отказ поне 12 часа преди плаща половината.',
    strict: 'При отказ поне 48 часа преди посещението родителят не плаща нищо. При по-късен отказ плаща цялата цена.',
  },
  residential_camp: {
    flexible: 'Капарото е 10 % от цената. При отказ поне 21 дни преди тръгване връщате всичко платено.',
    standard: 'Капарото е 25 % от цената. При отказ поне 30 дни преди тръгване връщате всичко платено.',
    strict: 'Капарото е 30 % от цената. При отказ поне 45 дни преди тръгване връщате всичко платено.',
  },
  day_camp: {
    flexible: 'При отказ поне 7 дни преди седмицата връщате цялата сума.',
    standard: 'При отказ поне 14 дни преди седмицата връщате цялата сума. При отказ поне 7 дни преди връщате половината.',
    strict: 'При отказ поне 14 дни преди седмицата връщате цялата сума. При по-късен отказ не връщате пари, но предлагате друга седмица.',
  },
};

const GENERIC_BLURBS: Record<ReadyPreset, string> = {
  flexible: 'Сроковете са по-меки, а родителят получава повече обратно и наваксва повече.',
  standard: 'Подходящи за повечето организации.',
  strict: 'Сроковете са по-ранни, а родителят получава по-малко обратно.',
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
  if (preset === 'custom') return 'Сменете което и да е число по-долу и условията стават ваши.';
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
// The rules in full sentences: the parent's situation, what happens, then the number; a share always says what
// it is a share of (docs/process/DECISIONS.md, 31.10.2026). A number and its word are joined by a space that
// does not break, so "25" and "%" never land on two lines.

const NBSP = '\u00A0';

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

/** "1 ден", "2 дни", "1 час", "24 часа" (also "21 дни": only 1 itself takes the singular). */
export function count(value: number, one: string, many: string): string {
  return `${value}${NBSP}${value === 1 ? one : many}`;
}

const days = (value: number) => count(value, 'ден', 'дни');
const hours = (value: number) => count(value, 'час', 'часа');

/** "25 %". */
export function percent(value: number): string {
  return `${value}${NBSP}%`;
}

/**
 * The neuter ordinal ending of a day of the month, by the official spelling: "1-во", "2-ро", "3-то", "4-то",
 * "5-о", "7-мо", "8-мо", "10-о", "11-о", "15-о", "20-о", "21-во" число.
 */
export function ordinalSuffix(day: number): string {
  if (day % 100 >= 11 && day % 100 <= 19) return 'о';
  switch (day % 10) {
    case 1: return 'во';
    case 2: return 'ро';
    case 3:
    case 4: return 'то';
    case 7:
    case 8: return 'мо';
    default: return 'о';
  }
}

const dayOfMonth = (day: number) => `${day}-${ordinalSuffix(day)}`;

/** The thing a share is of: "цялата месечна такса" / "половината от месечната такса" / "не плащате месечна такса". */
export type Base = { whole: string; part: string; none: string };

const FEE: Base = { whole: 'цялата месечна такса', part: 'месечната такса', none: 'не плащате месечна такса' };
/** The fee said a second time in the same rule. */
const FEE_AGAIN: Base = { whole: 'цялата такса', part: 'таксата', none: 'не плащате такса' };
const PRICE: Base = { whole: 'цялата цена', part: 'цената', none: 'не плащате нищо' };

/** What the parent pays, with the verb (at 0 % the verb itself changes). */
export function pays(kept: number, base: Base): string {
  if (kept >= 100) return `плащате ${base.whole}`;
  if (kept <= 0) return base.none;
  if (kept === 50) return `плащате половината от ${base.part}`;
  return `плащате ${percent(kept)} от ${base.part}`;
}

/** What comes back, with the verb: "връщаме цялата сума", "връщаме 75 % от цената", "не връщаме пари". */
export function returns(kept: number, of: string, whole = 'цялата сума'): string {
  const back = 100 - kept;
  if (back >= 100) return `връщаме ${whole}`;
  if (back <= 0) return 'не връщаме пари';
  if (back === 50) return `връщаме половината от ${of}`;
  return `връщаме ${percent(back)} от ${of}`;
}

const shareWord = (value: number) => (value === 50 ? 'половината' : percent(value));

export type TermsLine = { title: string; text: string };

function monthPart(rules: Rules, part: 'start' | 'end'): string {
  const mode = valueAt(rules, `${part}.mode`);
  const day = dayOfMonth(numberAt(rules, `${part}.cutoff_day`) ?? 15);
  const until = numberAt(rules, `${part}.kept_until_cutoff`) ?? 100;
  const after = numberAt(rules, `${part}.kept_after_cutoff`) ?? 100;
  if (part === 'start') {
    if (mode === 'full_month') return 'Ако детето започне по средата на месеца, за първия месец плащате цялата месечна такса.';
    if (mode !== 'cutoff') return 'Ако детето започне по средата на месеца, за първия месец плащате само заниманията от първия му ден нататък.';
    if (until === after) return `За първия месец ${pays(until, FEE)}, независимо в кой ден започва детето.`;
    return `Ако детето започне до ${day} число включително, за първия месец ${pays(until, FEE)}. Ако започне след ${day} число, ${pays(after, FEE_AGAIN)}.`;
  }
  const notice = numberAt(rules, 'end.notice_days') ?? 0;
  let text: string;
  if (mode === 'cutoff') {
    text = until === after
      ? `За месеца, в който е последният ден на детето в групата, ${pays(until, FEE)}.`
      : `Ако последният ден на детето в групата е до ${day} число включително, за този месец ${pays(until, FEE)}. Ако е след ${day} число, ${pays(after, FEE_AGAIN)}.`;
  } else if (mode === 'full_month') {
    text = 'Ако прекратите записването, за месеца, в който е последният ден на детето, плащате цялата месечна такса.';
  } else {
    text = 'Ако прекратите записването, за последния месец плащате само заниманията до последния ден на детето включително.';
  }
  return notice > 0 ? `${text} Предупредете ни поне ${days(notice)} преди последния ден.` : text;
}

/** The sessions in a pause after an "ако" clause: "…, не плащате заниманията в нея". */
function pausePays(kept: number, where: string): string {
  if (kept <= 0) return `не плащате заниманията ${where}`;
  if (kept >= 100) return `плащате заниманията ${where} изцяло`;
  return `плащате ${shareWord(kept)} от стойността на заниманията ${where}`;
}

/** The sessions in a pause said on their own. */
function pauseAlone(kept: number): string {
  if (kept <= 0) return 'Заниманията в паузата не се плащат.';
  if (kept >= 100) return 'Заниманията в паузата се плащат изцяло.';
  return `За заниманията в паузата плащате ${shareWord(kept)} от стойността им.`;
}

function pauseText(rules: Rules): string {
  const notice = numberAt(rules, 'pause.notice_days') ?? 0;
  const onTime = numberAt(rules, 'pause.kept_on_time') ?? 0;
  const late = numberAt(rules, 'pause.kept_late') ?? 0;
  const freeDays = numberAt(rules, 'pause.free_days_per_school_year') ?? 0;
  const minDays = numberAt(rules, 'pause.min_days') ?? 0;
  const parts = ['По време на паузата детето запазва мястото си в групата.'];
  if (freeDays <= 0) {
    // Without days at the on-time terms every pause counts as a late one.
    parts.push(pauseAlone(late));
  } else if (onTime === late) {
    // The notice and the yearly days change nothing in what is paid, so they are not explained.
    parts.push(pauseAlone(onTime));
    if (notice > 0) parts.push(`Предупредете ни поне ${days(notice)} преди началото ѝ.`);
  } else {
    parts.push(notice > 0
      ? `Ако ни предупредите поне ${days(notice)} преди началото на паузата, ${pausePays(onTime, 'в нея')}. Ако ни предупредите по-късно, ${pausePays(late, 'в нея')}.`
      : pauseAlone(onTime));
    // Beyond the yearly days a pause counts as a late one.
    parts.push(`Това важи за общо до ${days(freeDays)} пауза в учебната година (от 1 септември до 31 август). След като ${freeDays === 1 ? 'го' : 'ги'} използвате, ${pausePays(late, 'в паузата')}.`);
  }
  if (minDays > 0) parts.push(`Паузата трябва да е поне ${days(minDays)}.`);
  // A doctor's note is shown, never uploaded (docs/product/payment-policies.md, т. 3.8).
  if (booleanAt(rules, 'pause.document_required')) parts.push('Пауза е възможна само ако ни покажете бележка от лекар.');
  return parts.join(' ');
}

const times = (value: number) => (value === 1 ? 'веднъж' : `${value}${NBSP}пъти`);

function absenceSteps(rules: Rules): Array<{ upTo: number | null; kept: number }> {
  const list = valueAt(rules, 'absences.tiers');
  return Array.isArray(list) ? list.map((tier) => ({ upTo: numberAt(tier, 'up_to'), kept: numberAt(tier, 'kept') ?? 100 })) : [];
}

function absencesText(rules: Rules): string {
  const steps = absenceSteps(rules);
  if (steps.length <= 1) {
    const kept = steps[0]?.kept ?? 100;
    return kept >= 100 ? 'Отсъствията не намаляват месечната такса.' : `Ако детето отсъства, за този месец ${pays(kept, FEE)}.`;
  }
  return steps.map((step, index) => {
    if (index === 0) {
      if (step.upTo === 0) return `Ако детето няма отсъствия в месеца, ${pays(step.kept, FEE)}.`;
      return `Ако детето отсъства ${step.upTo === 1 ? 'най-много веднъж' : `до ${times(step.upTo ?? 0)}`} в месеца, ${pays(step.kept, FEE)}.`;
    }
    const lower = (steps[index - 1].upTo ?? 0) + 1;
    if (step.upTo === null || index === steps.length - 1) {
      return `Ако отсъства ${lower === 1 ? 'поне веднъж' : `${lower} или повече пъти`}, ${pays(step.kept, FEE_AGAIN)}.`;
    }
    const range = lower === step.upTo ? times(lower)
      : lower + 1 === step.upTo ? `${lower} или ${step.upTo}${NBSP}пъти` : `от ${lower} до ${step.upTo}${NBSP}пъти`;
    return `Ако отсъства ${range}, ${pays(step.kept, FEE_AGAIN)}.`;
  }).join(' ');
}

/** The parent says at least `notice_hours` before the missed session and makes it up within `within_days` after it (the owner, 31.10.2026). */
function makeupText(rules: Rules): string {
  if (!booleanAt(rules, 'makeup.enabled')) return 'Пропуснатите занимания не се наваксват.';
  const within = numberAt(rules, 'makeup.within_days') ?? 14;
  const notice = numberAt(rules, 'makeup.notice_hours') ?? 0;
  const perMonth = numberAt(rules, 'makeup.per_month');
  const parts = [notice > 0
    ? `Ако ни предупредите поне ${hours(notice)} преди заниманието, че детето няма да дойде, можете да го наваксате в рамките на ${days(within)} след него.`
    : `Пропуснато занимание можете да наваксате в рамките на ${days(within)} след него.`];
  if (perMonth !== null) parts.push(`В един месец можете да наваксате най-много ${count(perMonth, 'занимание', 'занимания')}.`);
  if (booleanAt(rules, 'makeup.only_if_space')) parts.push('Наваксването е възможно само ако в групата има свободно място.');
  return parts.join(' ');
}

function refundText(rules: Rules): string {
  switch (valueAt(rules, 'refund')) {
    case 'money': return 'Ако според условията ви се полагат пари, връщаме ги в срок до 14 дни по същия начин, по който сте платили.';
    // Not offered in the editor; a credit stays the parent's choice (docs/product/payment-policies.md, т. 3.3).
    case 'credit': return 'Ако според условията ви се полагат пари, можем да ги приспаднем от следващото ви плащане, ако желаете.';
    default: return 'Ако според условията ви се полагат пари, вие решавате дали да ви ги върнем, или да ги приспаднем от следващото ви плащане. Връщаме ги в срок до 14 дни по същия начин, по който сте платили.';
  }
}

type Ladder = {
  /** "началото", "началото на седмицата", "тръгване"; after the first sentence `eventAgain` ("началото ѝ"). */
  event: string; eventAgain?: string;
  /** The price a share is of; after the first share `ofAgain` ("цената"). */
  of: string; ofAgain?: string;
  /** What the kept money can go to instead ("друга седмица"), when a step offers it. */
  creditFor: string | null;
  /** A camp may be paid only in part (the deposit): keep a share of the price, never more than was paid (the owner, 31.10.2026). */
  keptFromPaid?: boolean;
};

/** Days before a start, money back: the full period, a day camp, a residential camp. */
function cancellationText(steps: Tier[], ladder: Ladder): string {
  const { event, eventAgain = event, of, ofAgain = of, creditFor, keptFromPaid = false } = ladder;
  let shareSaid = false;
  return steps.map((step, index) => {
    const at = index === 0 ? event : eventAgain;
    let when: string;
    if (steps.length === 1) when = `преди ${at}`;
    else if (index === 0) when = `поне ${days(step.min)} преди ${at}`;
    else {
      const upper = steps[index - 1].min - 1;
      if (index === steps.length - 1) when = upper === 0 ? `в деня на ${at}` : `по-малко от ${days(upper + 1)} преди ${at}`;
      else when = step.min === upper ? `${days(step.min)} преди ${at}` : `от ${step.min} до ${days(upper)} преди ${at}`;
    }
    const price = shareSaid ? ofAgain : of;
    let result: string;
    if (!keptFromPaid) result = returns(step.kept, price);
    else if (step.kept <= 0) result = 'връщаме всичко, което сте платили';
    else if (step.kept >= 100) result = 'не връщаме пари';
    else result = `задържаме ${shareWord(step.kept)} от ${price} и ви връщаме останалото`;
    if (step.kept > 0 && step.kept < 100) shareSaid = true;
    if (creditFor && step.credit && step.kept > 0) {
      result = step.kept >= 100
        ? `не връщаме пари, но можете да прехвърлите платената сума за ${creditFor}`
        : `${result}, а ${keptFromPaid ? 'задържаната сума' : 'останалата част'} можете да прехвърлите за ${creditFor}`;
    }
    return `Ако се откажете ${when}, ${result}.`;
  }).join(' ');
}

/** Hours before a visit, what is paid. */
function visitCancellationText(steps: Tier[]): string {
  if (steps.length === 1) {
    return steps[0].kept <= 0
      ? 'Можете да откажете посещението по всяко време, без да плащате.'
      : `Ако откажете посещението, ${pays(steps[0].kept, PRICE)}, независимо кога сте го отказали.`;
  }
  return steps.map((step, index) => {
    if (index === 0) return `Ако откажете посещението поне ${hours(step.min)} преди началото му, ${pays(step.kept, PRICE)}.`;
    if (index === steps.length - 1) return `Ако откажете по-малко от ${hours(steps[index - 1].min)} преди началото, ${pays(step.kept, PRICE)}.`;
    return `Ако откажете по-късно, но поне ${hours(step.min)} преди началото, ${pays(step.kept, PRICE)}.`;
  }).join(' ');
}

/** "Така го четат родителите": every rule of the set in plain words. */
export function describeTerms(kind: BillingKind, rules: Rules): TermsLine[] {
  switch (kind) {
    case 'monthly': {
      const lines: TermsLine[] = [];
      // "By sessions" is what a parent expects anyway; the line is shown for the other two ways.
      if (valueAt(rules, 'start.mode') !== 'by_sessions') lines.push({ title: 'Започване по средата на месеца', text: monthPart(rules, 'start') });
      lines.push(
        { title: 'Прекратяване', text: monthPart(rules, 'end') },
        { title: 'Пауза', text: pauseText(rules) },
        { title: 'Отсъствия', text: `${absencesText(rules)} ${makeupText(rules)}` },
        { title: 'Връщане на пари', text: refundText(rules) },
      );
      return lines;
    }
    case 'full_period': {
      const fullDays = numberAt(rules, 'after_start.full_refund_days') ?? 0;
      const kept = numberAt(rules, 'after_start.kept_of_remaining') ?? 0;
      const first = fullDays === 1 ? 'през първия ден' : `през първите ${days(fullDays)}`;
      let after: string;
      if (kept <= 0) after = 'Ако се откажете след началото, връщаме цялата стойност на оставащите занимания.';
      else if (fullDays > 0) after = `Ако се откажете ${first} след началото, връщаме цялата стойност на оставащите занимания. Ако се откажете по-късно, ${kept >= 100 ? 'не връщаме пари' : `връщаме ${shareWord(100 - kept)} от стойността им`}.`;
      else after = kept >= 100 ? 'Ако се откажете след началото, не връщаме пари за оставащите занимания.' : `Ако се откажете след началото, връщаме ${shareWord(100 - kept)} от стойността на оставащите занимания.`;
      return [
        { title: 'Отказ преди началото', text: cancellationText(tiers(rules, 'before_start.tiers', 'min_days'), { event: 'началото', of: 'цената', creditFor: null }) },
        { title: 'Отказ след началото', text: after },
        { title: 'Пауза', text: pauseText(rules) },
        { title: 'Наваксване', text: makeupText(rules) },
        { title: 'Връщане на пари', text: refundText(rules) },
      ];
    }
    case 'visit': {
      const noShow = numberAt(rules, 'no_show_kept') ?? 100;
      return [
        { title: 'Отказ', text: visitCancellationText(tiers(rules, 'cancellation.tiers', 'min_hours')) },
        { title: 'Пропуснато посещение', text: `Ако детето не дойде и не сте отказали посещението, ${pays(noShow, PRICE)}.` },
        { title: 'Връщане на пари', text: refundText(rules) },
      ];
    }
    case 'package': {
      const freeze = numberAt(rules, 'freeze_days_with_document') ?? 0;
      const extend = numberAt(rules, 'extension_days_on_request') ?? 0;
      return [
        { title: 'Замразяване на картата', text: freeze > 0 ? `Ако детето боледува и ни покажете бележка от лекар, замразяваме картата за до ${days(freeze)}. Докато е замразена, срокът ѝ не тече.` : 'Картата не може да се замразява.' },
        { title: 'Удължаване на срока', text: extend > 0 ? `Ако не успеете да използвате картата в срока ѝ, по ваша молба го удължаваме с до ${days(extend)}.` : 'Срокът на картата не може да се удължава.' },
        { title: 'Връщане на пари', text: refundText(rules) },
      ];
    }
    case 'day_camp': {
      const firstDays = numberAt(rules, 'absences.kept_first_days') ?? 0;
      const keptAfter = numberAt(rules, 'absences.kept_after') ?? 0;
      const change = numberAt(rules, 'week_change_free_until_days') ?? 0;
      const perDay = returns(keptAfter, 'дневната цена', 'цялата дневна цена');
      let absence: string;
      if (keptAfter >= 100) absence = 'Ако детето отсъства, не връщаме пари за пропуснатите дни.';
      else if (firstDays <= 0) absence = `За всеки ден, в който детето отсъства, ${perDay}.`;
      else absence = `За ${firstDays === 1 ? 'първия ден' : `първите ${days(firstDays)}`} отсъствие в седмицата не връщаме пари. За всеки следващ ден отсъствие ${perDay}.`;
      return [
        { title: 'Отказ преди седмицата', text: cancellationText(tiers(rules, 'before_start.tiers', 'min_days'), { event: 'началото на седмицата', eventAgain: 'началото ѝ', of: 'цената на седмицата', ofAgain: 'цената', creditFor: 'друга седмица' }) },
        { title: 'Отсъствия', text: absence },
        ...(change > 0 ? [{ title: 'Смяна на седмицата', text: `Можете да преместите детето в друга седмица без такса, ако ни предупредите поне ${days(change)} преди началото на записаната седмица.` }] : []),
        { title: 'Връщане на пари', text: refundText(rules) },
      ];
    }
    case 'residential_camp': {
      const mode = valueAt(rules, 'deposit.mode');
      const cents = numberAt(rules, 'deposit.amount_cents') ?? 0;
      const share = numberAt(rules, 'deposit.percent') ?? 0;
      const illness = numberAt(rules, 'illness_with_document.kept') ?? 0;
      const costs = booleanAt(rules, 'illness_with_document.plus_costs');
      const transfer = numberAt(rules, 'transfer_to_another_child_until_days') ?? 0;
      const early = numberAt(rules, 'early_departure_kept') ?? 100;
      const deposit = mode === 'amount' || mode === 'percent';
      const lines: TermsLine[] = [];
      if (mode === 'percent') lines.push({ title: 'Капаро', text: `При записване плащате капаро в размер на ${percent(share)} от цената на лагера. Капарото е част от цената, а не допълнителна сума.` });
      if (mode === 'amount') lines.push({ title: 'Капаро', text: `При записване плащате капаро в размер на ${money(cents / 100)}. Капарото е част от цената на лагера, а не допълнителна сума.` });
      // Never more than was paid: with only the deposit paid, the deposit is the most the parent loses.
      const limit = ` Никога не задържаме повече, отколкото сте платили до момента.${deposit ? ' Ако сте платили само капарото, губите най-много капарото.' : ''}`;
      let sick: string;
      if (illness >= 100) sick = 'не връщаме пари';
      else if (illness <= 0) sick = costs ? 'връщаме всичко, което сте платили, без разходите, които вече сме направили за детето' : 'връщаме всичко, което сте платили';
      else sick = `задържаме ${shareWord(illness)} от цената на лагера${costs ? ' и разходите, които вече сме направили за детето,' : ''} и ви връщаме останалото`;
      lines.push(
        { title: 'Отказ преди тръгване', text: `${cancellationText(tiers(rules, 'before_start.tiers', 'min_days'), { event: 'тръгване', of: 'цената на лагера', ofAgain: 'цената', creditFor: 'друга смяна', keptFromPaid: true })}${limit}` },
        { title: 'Болест преди тръгване', text: `Ако детето се разболее преди тръгване и ни покажете бележка от лекар, ${sick}.` },
      );
      if (transfer > 0) lines.push({ title: 'Друго дете на мястото', text: `Ако ни предупредите поне ${days(transfer)} преди тръгване, друго дете може да заеме мястото на вашето без допълнителна такса.` });
      lines.push(
        { title: 'Ранно прибиране', text: `Ако детето се прибере по-рано, ${early >= 100 ? 'не връщаме пари за неизползваните дни' : returns(early, 'стойността на неизползваните дни', 'цялата стойност на неизползваните дни')}.` },
        { title: 'Връщане на пари', text: refundText(rules) },
      );
      return lines;
    }
    case 'kindergarten': {
      const notice = numberAt(rules, 'notice_days') ?? 0;
      const sickDays = numberAt(rules, 'sick_over_working_days') ?? 0;
      const sickKept = numberAt(rules, 'sick_next_month_kept') ?? 100;
      return [
        { title: 'Напускане', text: notice > 0 ? `Ако детето напуска, предупредете ни поне ${days(notice)} по-рано.` : 'Можете да прекратите записването по всяко време, без предизвестие.' },
        // Taken off the fee for the last month (the owner, 31.10.2026).
        ...((numberAt(rules, 'deposit_months') ?? 0) > 0 ? [{ title: 'Депозит', text: 'При записване плащате депозит в размер на една месечна такса. Приспадаме го от таксата за последния месец.' }] : []),
        ...(booleanAt(rules, 'meals_by_attendance') ? [{ title: 'Храна', text: 'Храната плащате само за дните, в които детето е присъствало.' }] : []),
        ...(sickDays > 0 && sickKept < 100 ? [{ title: 'Болест', text: `Ако детето боледува повече от ${count(sickDays, 'работен ден', 'работни дни')} в един месец, за следващия месец ${pays(sickKept, FEE)}.` }] : []),
        { title: 'Връщане на пари', text: refundText(rules) },
      ];
    }
  }
}

// ---- An example with sums -----------------------------------------------------------------------------

/** "15,00 €": the format of the prices. */
export function money(amount: number): string {
  return new Intl.NumberFormat('bg-BG', { style: 'currency', currency: 'EUR', minimumFractionDigits: 2 }).format(Math.round(amount * 100) / 100);
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

/** November 2026 on Tuesdays: 3, 10, 17 and 24. */
const NOVEMBER = [3, 10, 17, 24];

/** The month's fee for a child who stops or starts on `day` of November. */
function monthShare(rules: Rules, part: 'start' | 'end', mode: Json | undefined, day: number, fee: number): number {
  if (mode === 'full_month') return fee;
  if (mode === 'cutoff') {
    const kept = day <= (numberAt(rules, `${part}.cutoff_day`) ?? 15) ? numberAt(rules, `${part}.kept_until_cutoff`) : numberAt(rules, `${part}.kept_after_cutoff`);
    return fee * (kept ?? 100) / 100;
  }
  return fee / NOVEMBER.length * NOVEMBER.filter((session) => (part === 'end' ? session <= day : session >= day)).length;
}

function absenceShare(rules: Rules, absences: number): number {
  const steps = absenceSteps(rules);
  return (steps.find((step) => step.upTo === null || absences <= step.upTo) ?? steps[steps.length - 1])?.kept ?? 100;
}

/**
 * A worked example in euro, with the price when it is known (`price`: the monthly fee, the visit, the week or
 * the camp). Not a calculation of what anyone owes — that comes with the calculator (step 3a).
 */
export function exampleFor(kind: BillingKind, rules: Rules, price?: number): { intro: string; lines: string[] } | null {
  switch (kind) {
    case 'monthly': {
      const fee = price ?? 60;
      const paid = (amount: number) => (amount >= fee ? `плащате цялата такса от ${money(fee)}` : amount <= 0 ? 'не плащате такса за ноември' : `за ноември плащате ${money(amount)} вместо ${money(fee)}`);
      return {
        intro: `Например: месечната такса е ${money(fee)}, а през ноември има ${NOVEMBER.length} занимания, по ${money(fee / NOVEMBER.length)} всяко.`,
        lines: [
          `Ако последният ден на детето е 10 ноември, ${paid(monthShare(rules, 'end', valueAt(rules, 'end.mode'), 10, fee))}.`,
          `Ако последният ден е 20 ноември, ${paid(monthShare(rules, 'end', valueAt(rules, 'end.mode'), 20, fee))}.`,
          `Ако детето отсъства 4 пъти през ноември, ${paid(fee * absenceShare(rules, 4) / 100)}.`,
        ],
      };
    }
    case 'visit': {
      const cost = price ?? 15;
      const steps = tiers(rules, 'cancellation.tiers', 'min_hours');
      const paid = (kept: number) => (kept <= 0 ? 'не плащате нищо' : `плащате ${money(cost * kept / 100)}`);
      return {
        intro: `Например: посещението струва ${money(cost)}.`,
        lines: [
          ...samples(steps).map((before) => `Ако го откажете ${hours(before)} преди началото, ${paid(tierFor(steps, before)?.kept ?? 100)}.`),
          `Ако детето не дойде, без да сте отказали, ${paid(numberAt(rules, 'no_show_kept') ?? 100)}.`,
        ],
      };
    }
    case 'residential_camp': {
      const cost = price ?? 600;
      const steps = tiers(rules, 'before_start.tiers', 'min_days');
      const mode = valueAt(rules, 'deposit.mode');
      const deposit = mode === 'percent' ? cost * (numberAt(rules, 'deposit.percent') ?? 0) / 100 : mode === 'amount' ? (numberAt(rules, 'deposit.amount_cents') ?? 0) / 100 : 0;
      const back = (before: number) => cost * (100 - (tierFor(steps, before)?.kept ?? 100)) / 100;
      const said = (amount: number) => (amount <= 0 ? 'не връщаме пари' : `връщаме ви ${money(amount)}`);
      const sampleDays = samples(steps);
      // The step where the camp keeps more than the deposit: there the parent who paid only the deposit loses just that.
      const late = sampleDays.find((before) => cost - back(before) > deposit && back(before) > 0) ?? sampleDays.find((before) => cost - back(before) > deposit);
      return {
        intro: deposit > 0
          ? `Например: лагерът струва ${money(cost)}. При записване сте платили капаро ${money(deposit)}, а после и останалите ${money(cost - deposit)}.`
          : `Например: лагерът струва ${money(cost)} и сте го платили изцяло.`,
        lines: [
          ...sampleDays.map((before) => `Ако се откажете ${days(before)} преди тръгване, ${said(back(before))}.`),
          ...(deposit > 0 && late !== undefined ? [`Ако сте платили само капарото и се откажете ${days(late)} преди тръгване, губите ${money(deposit)} и не дължите нищо повече.`] : []),
        ],
      };
    }
    case 'day_camp': {
      const week = price ?? 150;
      const perDay = week / 5;
      const firstDays = numberAt(rules, 'absences.kept_first_days') ?? 0;
      const keptAfter = numberAt(rules, 'absences.kept_after') ?? 0;
      const back = Math.max(0, 3 - firstDays) * perDay * (100 - keptAfter) / 100;
      return {
        intro: `Например: седмицата струва ${money(week)}, или ${money(perDay)} на ден.`,
        lines: [`Ако детето отсъства 3 дни, ${back <= 0 ? 'не връщаме пари' : `връщаме ${money(back)}`}.`],
      };
    }
    default:
      return null;
  }
}

// ---- Examples under each rule in the editor (a fee of 60 € a month, Tuesdays in November 2026) ----------

const FEE_EXAMPLE = 60;

/** "плаща 30,00 €", "плаща цялата такса от 60,00 €", "не плаща нищо" — about the parent, for the organization. */
function parentPays(amount: number): string {
  if (amount <= 0) return 'не плаща нищо';
  if (amount >= FEE_EXAMPLE) return `плаща цялата такса от ${money(FEE_EXAMPLE)}`;
  return `плаща ${money(amount)}`;
}

/** The example under one choice for the month the child stops or starts in. */
export function monthChoiceExample(rules: Rules, part: 'start' | 'end', mode: string): string {
  const when = (day: number, again = false) => (part === 'end'
    ? (again ? `Ако е ${day} ноември` : `Например: ако последният ден е ${day} ноември`)
    : (again ? `Ако започне на ${day} ноември` : `Например: ако детето започне на ${day} ноември`));
  if (mode === 'by_sessions') {
    const day = part === 'end' ? 10 : 17;
    const sessions = NOVEMBER.filter((session) => (part === 'end' ? session <= day : session >= day)).length;
    return `${when(day)}, родителят ${sessions ? `плаща ${money(FEE_EXAMPLE / NOVEMBER.length * sessions)} за ${count(sessions, 'занимание', 'занимания')}` : 'не плаща нищо'}.`;
  }
  if (mode === 'full_month') return `${when(part === 'end' ? 10 : 17)}, родителят ${parentPays(FEE_EXAMPLE)}.`;
  return `${when(10)}, родителят ${parentPays(monthShare(rules, part, mode, 10, FEE_EXAMPLE))}. ${when(20, true)}, ${parentPays(monthShare(rules, part, mode, 20, FEE_EXAMPLE))}.`;
}

/** A pause as long as the shortest one allowed: 2–15 November (2 sessions) or the whole month (4). */
export function pauseExample(rules: Rules): string | null {
  const notice = numberAt(rules, 'pause.notice_days') ?? 0;
  const onTime = numberAt(rules, 'pause.kept_on_time') ?? 0;
  const late = numberAt(rules, 'pause.kept_late') ?? 0;
  const minDays = numberAt(rules, 'pause.min_days') ?? 0;
  if (minDays > 29) return null;
  const [until, sessions] = minDays <= 14 ? [15, 2] : [30, 4];
  const perSession = FEE_EXAMPLE / NOVEMBER.length;
  const paid = (kept: number) => money(FEE_EXAMPLE - perSession * sessions + perSession * sessions * kept / 100);
  const pause = `Например: пауза от 2 до ${until} ноември обхваща ${count(sessions, 'занимание', 'занимания')}.`;
  if (notice <= 0 || onTime === late) return `${pause} За ноември родителят плаща ${paid(onTime)}.`;
  return `${pause} Ако родителят ви предупреди навреме, за ноември плаща ${paid(onTime)}. Ако ви предупреди по-късно, плаща ${paid(late)}.`;
}

export function absenceExample(rules: Rules): string {
  const amount = FEE_EXAMPLE * absenceShare(rules, 4) / 100;
  return `Например: ако детето отсъства 4 пъти през ноември, родителят ${amount >= FEE_EXAMPLE ? parentPays(amount) : amount <= 0 ? 'не плаща такса за ноември' : `плаща ${money(amount)} вместо ${money(FEE_EXAMPLE)}`}.`;
}
