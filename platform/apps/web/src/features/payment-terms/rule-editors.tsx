'use client';

import { useId, useRef, type ReactNode } from 'react';
import type { BillingKind, Json, Rules } from './api';
import { absenceExample, booleanAt, errorsFor, monthChoiceExample, numberAt, ordinalSuffix, pauseExample, valueAt } from './rules';
import styles from './payment-terms.module.css';

/**
 * The rules of "Условия за плащане" for each way of paying, asked as plain questions with numbers inside and an
 * example under them (the approved mockup "Предложение: по-ясни текстове", 30.10.2026). A percentage in the
 * rules is the share kept ("плаща"); camps are phrased as the share that comes back ("връщаме").
 */

export type EditorContext = {
  rules: Rules;
  disabled: boolean;
  errors: Record<string, string[]>;
  /** The organization takes applications through the platform's catalog, so its frame applies. */
  inMarketplace: boolean;
  change: (path: string, value: Json) => void;
};

const FRAME = 'родителите се записват при вас през платформата и това е общо правило за всички.';

const ICONS: Record<string, string[]> = {
  end: ['M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4', 'M16 17l5-5-5-5', 'M21 12H9'],
  start: ['M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4', 'M10 17l5-5-5-5', 'M15 12H3'],
  pause: ['M6 4h4v16H6z', 'M14 4h4v16h-4z'],
  absences: ['M3 6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z', 'M16 2v4M8 2v4M3 10h18M10 14l4 4M14 14l-4 4'],
  clock: ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z', 'M12 7v5l3 2'],
  money: ['M3 7h18v10H3z', 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z', 'M6 10v4M18 10v4'],
  document: ['M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z', 'M14 2v6h6M12 12v6M9 15h6'],
  person: ['M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z', 'M4 21c0-4 3.6-6 8-6s8 2 8 6'],
  swap: ['M7 7h13l-4-4', 'M17 17H4l4 4'],
  home: ['M3 11l9-7 9 7', 'M5 10v10h14V10'],
};

export function RuleIcon({ name }: { name: string }) {
  return (
    <svg className={styles.icon} width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      {(ICONS[name] ?? ICONS.clock).map((d) => <path key={d} d={d} />)}
    </svg>
  );
}

/** One rule, asked as a question; the server's messages for these paths appear under it. */
export function RuleRow({ icon, title, paths, context, children }: { icon: string; title: string; paths: string[]; context: EditorContext; children: ReactNode }) {
  const messages = errorsFor(context.errors, paths.map((path) => `rules.${path}`));
  const errorId = useId();
  return (
    <fieldset className={messages.length ? `${styles.rule} ${styles.ruleInvalid}` : styles.rule} aria-describedby={messages.length ? errorId : undefined} data-invalid={messages.length ? 'true' : undefined}>
      <legend className={styles.ruleTitle}><RuleIcon name={icon} />{title}</legend>
      <div className={styles.ruleBody}>
        {children}
        {messages.length ? <div id={errorId}>{messages.map((message) => <p key={message} className={styles.fieldError}>{message}</p>)}</div> : null}
      </div>
    </fieldset>
  );
}

export function Line({ children }: { children: ReactNode }) {
  return <div className={styles.line}>{children}</div>;
}

export function Hint({ children }: { children: ReactNode }) {
  return <p className={styles.hint}>{children}</p>;
}

function Example({ children }: { children: ReactNode }) {
  return <p className={styles.example}>{children}</p>;
}

/** Rarely needed settings, folded under a link. */
function More({ label, children }: { label: string; children: ReactNode }) {
  return (
    <details className={styles.more}>
      <summary>{label}</summary>
      <div className={styles.moreBody}>{children}</div>
    </details>
  );
}

type NumberProps = { context: EditorContext; path: string; label: string; min?: number; max?: number; wide?: boolean; returned?: boolean };

/** A whole number inside a sentence. Empty means "not set"; the server says what is missing. */
export function NumberInput({ context, path, label, min = 0, max, wide, returned }: NumberProps) {
  const stored = numberAt(context.rules, path);
  const shown = stored === null ? '' : String(returned ? 100 - stored : stored);
  const invalid = (context.errors[`rules.${path}`] ?? []).length > 0;
  return (
    <input
      className={wide ? `${styles.number} ${styles.numberWide}` : styles.number}
      type="number"
      inputMode="numeric"
      min={min}
      max={max}
      step={1}
      value={shown}
      aria-label={label}
      aria-invalid={invalid || undefined}
      disabled={context.disabled}
      onChange={(event) => {
        const raw = event.target.value.trim();
        if (raw === '') { context.change(path, null); return; }
        const value = Math.round(Number(raw));
        if (!Number.isFinite(value)) return;
        context.change(path, returned ? 100 - value : value);
      }}
    />
  );
}

export function Check({ context, path, children }: { context: EditorContext; path: string; children: ReactNode }) {
  return (
    <label className={styles.check}>
      <input type="checkbox" checked={booleanAt(context.rules, path)} disabled={context.disabled} onChange={(event) => context.change(path, event.target.checked)} />
      <span>{children}</span>
    </label>
  );
}

type Choice = { value: string; title: string; hint?: string; details?: ReactNode };

/** Choices as cards; the chosen one shows its numbers. Inputs sit outside the label (a label holds one control). */
function Choices({ context, name, value, choices, onPick }: { context: EditorContext; name: string; value: string; choices: Choice[]; onPick: (value: string) => void }) {
  const group = useId();
  return (
    <div className={styles.choices}>
      {choices.map((choice) => {
        const on = choice.value === value;
        return (
          <div key={choice.value} className={on ? `${styles.choiceCard} ${styles.choiceCardOn}` : styles.choiceCard}>
            <label className={styles.choiceLabel}>
              <input type="radio" name={`${group}-${name}`} value={choice.value} checked={on} disabled={context.disabled} onChange={() => onPick(choice.value)} />
              <span className={styles.choiceText}>
                <span className={styles.choiceTitle}>{choice.title}</span>
                {choice.hint ? <span className={styles.hint}>{choice.hint}</span> : null}
              </span>
            </label>
            {on && choice.details ? <div className={styles.choiceDetails}>{choice.details}</div> : null}
          </div>
        );
      })}
    </div>
  );
}

/** "Откаже ли поне N часа/дни преди → плаща/връщаме %"; the last step is "по-късно"; 1 to `max` steps. */
function Ladder({ context, path, unit, max, returned, creditFor, before }: {
  context: EditorContext; path: string; unit: 'min_hours' | 'min_days'; max: number; returned: boolean; creditFor: string | null; before: string;
}) {
  const addRef = useRef<HTMLButtonElement>(null);
  const credit = creditFor !== null;
  const list = valueAt(context.rules, path);
  const steps = Array.isArray(list) ? list : [];
  const word = unit === 'min_hours' ? 'часа' : 'дни';
  const verb = returned ? 'връщаме' : 'плаща';
  const replace = (next: Json[]) => context.change(path, next);
  const remove = (index: number) => {
    replace(steps.filter((__, other) => other !== index));
    // The removed row had the focus; "Добави срок" is next to it.
    requestAnimationFrame(() => addRef.current?.focus());
  };
  const add = () => {
    const last = steps[steps.length - 1];
    const previous = steps.length > 1 ? numberAt(steps[steps.length - 2], unit) ?? 0 : 0;
    const min = previous > 1 ? Math.floor(previous / 2) : unit === 'min_hours' ? 24 : 7;
    // Between its neighbours, so the ladder still reads in one direction.
    const lastKept = numberAt(last ?? {}, 'kept') ?? 100;
    const previousKept = steps.length > 1 ? numberAt(steps[steps.length - 2], 'kept') ?? 0 : 0;
    const kept = Math.round((previousKept + lastKept) / 2);
    const step: Json = credit ? { [unit]: min, kept, credit_offered: false } : { [unit]: min, kept };
    replace([...steps.slice(0, -1), step, ...(last === undefined ? [] : [last])]);
  };
  return (
    <>
      {steps.map((_, index) => {
        const last = index === steps.length - 1;
        return (
          <div key={index} className={styles.step}>
            <Line>
              {last
                ? <span>{steps.length === 1 ? `При отказ по всяко време ${before} → ${verb}` : `По-късно → ${verb}`}</span>
                : <><span>Откаже ли поне</span><NumberInput context={context} path={`${path}.${index}.${unit}`} label={`${unit === 'min_hours' ? 'Часове' : 'Дни'} преди, стъпало ${index + 1}`} min={1} /><span>{word} {before} → {verb}</span></>}
              <NumberInput context={context} path={`${path}.${index}.kept`} label={`Процент, който ${returned ? 'се връща' : 'се плаща'}, стъпало ${index + 1}`} max={100} returned={returned} />
              <span>%.</span>
              {!last && !context.disabled && steps.length > 1 ? (
                <button type="button" className={styles.linkButton} onClick={() => remove(index)}>
                  Махни<span className="sr-only"> стъпало {index + 1}</span>
                </button>
              ) : null}
            </Line>
            {credit ? <Check context={context} path={`${path}.${index}.credit_offered`}>Предлагаме кредит за {creditFor} — родителят решава</Check> : null}
          </div>
        );
      })}
      {!context.disabled && steps.length < max ? <button type="button" ref={addRef} className={styles.linkButton} onClick={add}>Добави срок</button> : null}
      {context.inMarketplace ? <Hint>При ранен отказ винаги се връща нещо: {FRAME}</Hint> : null}
    </>
  );
}

/** Up to 3 steps by the number of absences in a month; the last is "повече". */
function AbsenceTiers({ context }: { context: EditorContext }) {
  const addRef = useRef<HTMLButtonElement>(null);
  const path = 'absences.tiers';
  const list = valueAt(context.rules, path);
  const steps = Array.isArray(list) ? list : [];
  const replace = (next: Json[]) => context.change(path, next);
  return (
    <>
      {steps.map((_, index) => {
        const last = index === steps.length - 1;
        return (
          <Line key={index}>
            {last
              ? <span>{steps.length === 1 ? 'При отсъствия → плаща' : 'Повече → плаща'}</span>
              : <><span>До</span><NumberInput context={context} path={`${path}.${index}.up_to`} label={`Брой отсъствия, стъпало ${index + 1}`} max={31} /><span>отсъствия в месеца → плаща</span></>}
            <NumberInput context={context} path={`${path}.${index}.kept`} label={`Процент от таксата, стъпало ${index + 1}`} max={100} />
            <span>% от таксата.</span>
            {!last && !context.disabled ? (
              <button type="button" className={styles.linkButton} onClick={() => {
                replace(steps.filter((__, other) => other !== index));
                requestAnimationFrame(() => addRef.current?.focus());
              }}>
                Махни<span className="sr-only"> стъпало {index + 1}</span>
              </button>
            ) : null}
          </Line>
        );
      })}
      {!context.disabled && steps.length < 3 ? (
        <button type="button" ref={addRef} className={styles.linkButton} onClick={() => {
          const previous = steps.length > 1 ? numberAt(steps[steps.length - 2], 'up_to') ?? 0 : 0;
          const lastKept = numberAt(steps[steps.length - 1] ?? {}, 'kept') ?? 100;
          const previousKept = steps.length > 1 ? numberAt(steps[steps.length - 2], 'kept') ?? 100 : 100;
          replace([...steps.slice(0, -1), { up_to: previous + 3, kept: previousKept }, { up_to: null, kept: lastKept }]);
        }}>Добави стъпало</button>
      ) : null}
    </>
  );
}

/** The month a child stops or starts in: by sessions, by the day of the month, or the whole month. */
function MonthChoice({ context, part }: { context: EditorContext; part: 'start' | 'end' }) {
  const mode = valueAt(context.rules, `${part}.mode`);
  const pick = (next: string) => {
    const base = valueAt(context.rules, part);
    const current = base !== null && typeof base === 'object' && !Array.isArray(base) ? base : {};
    const cutoff = next === 'cutoff'
      ? { cutoff_day: numberAt(current, 'cutoff_day') ?? 15, kept_until_cutoff: numberAt(current, 'kept_until_cutoff') ?? (part === 'end' ? 50 : 100), kept_after_cutoff: numberAt(current, 'kept_after_cutoff') ?? (part === 'end' ? 100 : 50) }
      : { cutoff_day: null, kept_until_cutoff: null, kept_after_cutoff: null };
    context.change(part, { ...current, mode: next, ...cutoff });
  };
  const day = numberAt(context.rules, `${part}.cutoff_day`) ?? 15;
  return (
    <Choices context={context} name={part} value={typeof mode === 'string' ? mode : 'by_sessions'} onPick={pick} choices={[
      {
        value: 'by_sessions',
        title: part === 'end' ? 'Плаща само заниманията до последния ден' : 'Плаща само заниманията от първия ден',
        hint: monthChoiceExample(context.rules, part, 'by_sessions'),
      },
      {
        value: 'cutoff',
        title: 'Плаща част от месеца според датата',
        hint: mode === 'cutoff' ? monthChoiceExample(context.rules, part, 'cutoff') : 'Напр. до 15-то число — половината, след това — цялата такса.',
        details: (
          <Line>
            <span>{part === 'end' ? 'Спре ли до' : 'Започне ли до'}</span>
            <NumberInput context={context} path={`${part}.cutoff_day`} label="Ден от месеца" min={1} max={28} />
            <span>-{ordinalSuffix(day)} число → плаща</span>
            <NumberInput context={context} path={`${part}.kept_until_cutoff`} label="Процент от таксата до този ден" max={100} />
            <span>% от таксата. След това →</span>
            <NumberInput context={context} path={`${part}.kept_after_cutoff`} label="Процент от таксата след този ден" max={100} />
            <span>%.</span>
          </Line>
        ),
      },
      { value: 'full_month', title: 'Плаща целия месец', hint: monthChoiceExample(context.rules, part, 'full_month') },
    ]} />
  );
}

function PauseRow({ context, example }: { context: EditorContext; example: boolean }) {
  return (
    <RuleRow icon="pause" title="Пауза — ваканция, болест, пътуване" paths={['pause']} context={context}>
      <Hint>Детето пази мястото си, а за заниманията в паузата плаща по-малко или нищо.</Hint>
      <Line>
        <span>Ако родителят ви каже поне</span>
        <NumberInput context={context} path="pause.notice_days" label="Дни предизвестие за пауза" max={60} />
        <span>дни по-рано → за заниманията в паузата плаща</span>
        <NumberInput context={context} path="pause.kept_on_time" label="Процент при навременна пауза" max={100} />
        <span>%.</span>
      </Line>
      <Line>
        <span>Ако каже по-късно → плаща</span>
        <NumberInput context={context} path="pause.kept_late" label="Процент при късна пауза" max={100} />
        <span>%.</span>
      </Line>
      <Line>
        <span>Така — до</span>
        <NumberInput context={context} path="pause.free_days_per_school_year" label="Дни пауза в учебната година при тези условия" max={183} wide />
        <span>дни в учебната година (61 дни ≈ 2 месеца). Над тях — като казано късно.</span>
      </Line>
      {example ? <Example>{pauseExample(context.rules)}</Example> : null}
      <More label="Още за паузата: най-кратка пауза, само с бележка от лекар">
        <Line>
          <span>Най-кратка пауза</span>
          <NumberInput context={context} path="pause.min_days" label="Най-кратка пауза в дни" max={183} />
          <span>дни (0 — без ограничение).</span>
        </Line>
        <Check context={context} path="pause.document_required">Пауза само с бележка от лекар</Check>
      </More>
    </RuleRow>
  );
}

function MakeupLines({ context }: { context: EditorContext }) {
  return (
    <>
      <Line>
        <Check context={context} path="makeup.enabled">Пропуснато занимание може да се навакса до</Check>
        <NumberInput context={context} path="makeup.within_days" label="Дни за наваксване" min={1} max={60} />
        <span>дни, ако родителят каже поне</span>
        <NumberInput context={context} path="makeup.notice_hours" label="Часове предварително" max={72} />
        <span>часа по-рано.</span>
      </Line>
    </>
  );
}

function MakeupMore({ context }: { context: EditorContext }) {
  return (
    <More label="Още за наваксването: колко в месеца, само ако има място">
      <Line>
        <span>Най-много</span>
        <NumberInput context={context} path="makeup.per_month" label="Наваксвания в месеца (празно — без ограничение)" min={1} max={31} />
        <span>наваксвания в месеца (празно — без ограничение).</span>
      </Line>
      <Check context={context} path="makeup.only_if_space">Само ако в групата има свободно място</Check>
    </More>
  );
}

function RefundRow({ context }: { context: EditorContext }) {
  const value = valueAt(context.rules, 'refund');
  // Only as credit is not offered: a credit is always the parent's choice (spec, т. 3.3).
  return (
    <RuleRow icon="money" title="Как се връщат пари" paths={['refund']} context={context}>
      <Choices context={context} name="refund" value={typeof value === 'string' ? value : 'parent_choice'} onPick={(next) => context.change('refund', next)} choices={[
        { value: 'parent_choice', title: 'Родителят избира — пари или кредит', hint: 'Кредитът се приспада от следващото плащане.' },
        { value: 'money', title: 'Винаги пари', hint: 'По същия начин, по който е платено, до 14 дни.' },
      ]} />
    </RuleRow>
  );
}

export function RuleEditor({ kind, context }: { kind: BillingKind; context: EditorContext }) {
  switch (kind) {
    case 'monthly':
      return (
        <>
          <RuleRow icon="end" title="Ако детето спре по средата на месеца" paths={['end']} context={context}>
            <MonthChoice context={context} part="end" />
            <Line>
              <span>Колко дни по-рано трябва да ви каже родителят?</span>
              <NumberInput context={context} path="end.notice_days" label="Дни предизвестие" max={30} />
              <span>дни</span>
            </Line>
            <Hint>0 — може и в деня, в който спира.{context.inMarketplace ? ` Най-много 30 дни: ${FRAME}` : ''}</Hint>
          </RuleRow>
          <RuleRow icon="start" title="Ако детето започне по средата на месеца" paths={['start']} context={context}>
            <MonthChoice context={context} part="start" />
          </RuleRow>
          <PauseRow context={context} example />
          <RuleRow icon="absences" title="Отсъствия — детето не идва, без да е в пауза" paths={['absences', 'makeup']} context={context}>
            <AbsenceTiers context={context} />
            <MakeupLines context={context} />
            <Example>{absenceExample(context.rules)}</Example>
            <MakeupMore context={context} />
          </RuleRow>
          <RefundRow context={context} />
        </>
      );
    case 'full_period':
      return (
        <>
          <RuleRow icon="end" title="Ако се откаже преди началото" paths={['before_start']} context={context}>
            <Ladder context={context} path="before_start.tiers" unit="min_days" max={6} returned creditFor={null} before="преди началото" />
          </RuleRow>
          <RuleRow icon="clock" title="Ако се откаже след началото" paths={['after_start']} context={context}>
            <Line>
              <span>През първите</span>
              <NumberInput context={context} path="after_start.full_refund_days" label="Дни с пълно връщане след началото" max={60} />
              <span>дни връщаме всичко за оставащите занимания. След това връщаме</span>
              <NumberInput context={context} path="after_start.kept_of_remaining" label="Процент, който се връща от оставащите занимания" max={100} returned />
              <span>% от тях.</span>
            </Line>
          </RuleRow>
          <PauseRow context={context} example={false} />
          <RuleRow icon="absences" title="Отсъствия — детето не идва, без да е в пауза" paths={['makeup']} context={context}>
            <MakeupLines context={context} />
            <MakeupMore context={context} />
          </RuleRow>
          <RefundRow context={context} />
        </>
      );
    case 'visit':
      return (
        <>
          <RuleRow icon="clock" title="Ако откаже посещението" paths={['cancellation']} context={context}>
            <Ladder context={context} path="cancellation.tiers" unit="min_hours" max={3} returned={false} creditFor={null} before="преди началото" />
          </RuleRow>
          <RuleRow icon="person" title="Ако не дойде, без да откаже" paths={['no_show_kept']} context={context}>
            <Line>
              <span>Плаща</span>
              <NumberInput context={context} path="no_show_kept" label="Процент при неявяване" max={100} />
              <span>% от сумата.</span>
            </Line>
          </RuleRow>
          <RefundRow context={context} />
        </>
      );
    case 'package':
      return (
        <>
          <RuleRow icon="pause" title="Замразяване и удължаване на картата" paths={['freeze_days_with_document', 'extension_days_on_request']} context={context}>
            <Line>
              <span>С бележка от лекар картата се замразява до</span>
              <NumberInput context={context} path="freeze_days_with_document" label="Дни замразяване с бележка" max={90} />
              <span>дни.</span>
            </Line>
            <Line>
              <span>По молба срокът ѝ се удължава с до</span>
              <NumberInput context={context} path="extension_days_on_request" label="Дни удължаване по молба" max={90} />
              <span>дни.</span>
            </Line>
          </RuleRow>
          <RefundRow context={context} />
        </>
      );
    case 'day_camp':
      return (
        <>
          <RuleRow icon="end" title="Ако се откаже преди седмицата" paths={['before_start']} context={context}>
            <Ladder context={context} path="before_start.tiers" unit="min_days" max={6} returned creditFor="друга седмица" before="преди седмицата" />
          </RuleRow>
          <RuleRow icon="absences" title="Ако отсъства в записана седмица" paths={['absences']} context={context}>
            <Line>
              <span>Първите</span>
              <NumberInput context={context} path="absences.kept_first_days" label="Дни отсъствие без връщане" max={5} />
              <span>дни → не връщаме. За всеки следващ → връщаме</span>
              <NumberInput context={context} path="absences.kept_after" label="Процент, който се връща за следващите дни" max={100} returned />
              <span>% от дневната цена.</span>
            </Line>
          </RuleRow>
          <RuleRow icon="swap" title="Смяна на седмицата" paths={['week_change_free_until_days']} context={context}>
            <Line>
              <span>Без такса до</span>
              <NumberInput context={context} path="week_change_free_until_days" label="Дни за смяна на седмицата без такса" max={30} />
              <span>дни преди началото ѝ.</span>
            </Line>
          </RuleRow>
          <RefundRow context={context} />
        </>
      );
    case 'residential_camp':
      return (
        <>
          <RuleRow icon="money" title="Капаро" paths={['deposit']} context={context}>
            <DepositChoice context={context} />
          </RuleRow>
          <RuleRow icon="end" title="Ако се откаже преди тръгване" paths={['before_start']} context={context}>
            <Ladder context={context} path="before_start.tiers" unit="min_days" max={6} returned creditFor="друга смяна" before="преди тръгване" />
            <Hint>Задържаната сума не бива да е по-голяма от реалните ви разходи.</Hint>
          </RuleRow>
          <RuleRow icon="document" title="Болест и друго дете на мястото" paths={['illness_with_document', 'transfer_to_another_child_until_days']} context={context}>
            <Line>
              <span>Болест преди тръгване с бележка от лекар → връщаме</span>
              <NumberInput context={context} path="illness_with_document.kept" label="Процент, който се връща при болест" max={100} returned />
              <span>%.</span>
            </Line>
            <Check context={context} path="illness_with_document.plus_costs">Приспадаме реално направените разходи</Check>
            <Line>
              <span>Друго дете може да заеме мястото до</span>
              <NumberInput context={context} path="transfer_to_another_child_until_days" label="Дни за друго дете на мястото" max={60} />
              <span>дни преди тръгване — без такса.</span>
            </Line>
          </RuleRow>
          <RuleRow icon="home" title="Ако се прибере по-рано" paths={['early_departure_kept']} context={context}>
            <Line>
              <span>Връщаме</span>
              <NumberInput context={context} path="early_departure_kept" label="Процент, който се връща от неизползваните дни" max={100} returned />
              <span>% от неизползваните дни.</span>
            </Line>
          </RuleRow>
          <RefundRow context={context} />
        </>
      );
    case 'kindergarten':
      return (
        <>
          <RuleRow icon="end" title="Ако детето напусне" paths={['notice_days', 'deposit_months']} context={context}>
            <Line>
              <span>Родителят ви казва поне</span>
              <NumberInput context={context} path="notice_days" label="Дни предизвестие" max={30} />
              <span>дни по-рано.</span>
            </Line>
            {context.inMarketplace ? <Hint>Най-много 30 дни: {FRAME}</Hint> : null}
            <Choices context={context} name="deposit" value={String(numberAt(context.rules, 'deposit_months') ?? 0)} onPick={(next) => context.change('deposit_months', Number(next))} choices={[
              { value: '0', title: 'Без депозит' },
              { value: '1', title: 'Депозит — една месечна такса', hint: 'Връща се или се приспада от последния месец.' },
            ]} />
          </RuleRow>
          <RuleRow icon="absences" title="Храна и болест" paths={['meals_by_attendance', 'sick_over_working_days', 'sick_next_month_kept']} context={context}>
            <Check context={context} path="meals_by_attendance">Храната се плаща според присъствието</Check>
            <Line>
              <span>Болест над</span>
              <NumberInput context={context} path="sick_over_working_days" label="Работни дни болест" max={60} />
              <span>работни дни в месеца → за следващия месец плаща</span>
              <NumberInput context={context} path="sick_next_month_kept" label="Процент за следващия месец" max={100} />
              <span>% от таксата.</span>
            </Line>
          </RuleRow>
          <RefundRow context={context} />
        </>
      );
  }
}

function DepositChoice({ context }: { context: EditorContext }) {
  const mode = valueAt(context.rules, 'deposit.mode');
  const cents = numberAt(context.rules, 'deposit.amount_cents');
  const pick = (next: string) => context.change('deposit', {
    mode: next,
    amount_cents: next === 'amount' ? numberAt(context.rules, 'deposit.amount_cents') ?? 15000 : null,
    percent: next === 'percent' ? numberAt(context.rules, 'deposit.percent') ?? 25 : null,
  });
  return (
    <Choices context={context} name="deposit" value={typeof mode === 'string' ? mode : 'none'} onPick={pick} choices={[
      { value: 'none', title: 'Без капаро' },
      {
        value: 'percent',
        title: 'Процент от цената',
        hint: 'Приспада се от цената.',
        details: (
          <>
            <Line>
              <NumberInput context={context} path="deposit.percent" label="Процент капаро" max={30} />
              <span>% от цената при записване.</span>
            </Line>
            {context.inMarketplace ? <Hint>Най-много 30 %: {FRAME}</Hint> : null}
          </>
        ),
      },
      {
        value: 'amount',
        title: 'Фиксирана сума',
        hint: 'Приспада се от цената.',
        details: (
          <Line>
            <input className={`${styles.number} ${styles.numberWide}`} type="number" inputMode="numeric" min={0} step={1} aria-label="Капаро в евро"
              value={cents === null ? '' : String(Math.round(cents / 100))} disabled={context.disabled}
              onChange={(event) => context.change('deposit.amount_cents', event.target.value.trim() === '' ? null : Math.round(Number(event.target.value)) * 100)} />
            <span>€ при записване.</span>
          </Line>
        ),
      },
    ]} />
  );
}
