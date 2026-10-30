'use client';

import type { ReactNode } from 'react';
import type { BillingKind, Json, Rules } from './api';
import { booleanAt, errorsFor, numberAt, valueAt } from './rules';
import styles from './payment-terms.module.css';

/**
 * The rows of "Условия за плащане" for each way of paying, as sentences with numbers inside (the approved
 * mockup "Условия за плащане — макет"). A percentage in the rules is the share kept; camps are phrased as
 * the share that comes back.
 */

export type EditorContext = {
  rules: Rules;
  disabled: boolean;
  errors: Record<string, string[]>;
  change: (path: string, value: Json) => void;
};

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

/** One rule: an icon, a title and sentences; the server's messages for these paths appear under it. */
export function RuleRow({ icon, title, paths, context, children }: { icon: string; title: string; paths: string[]; context: EditorContext; children: ReactNode }) {
  const messages = errorsFor(context.errors, paths.map((path) => `rules.${path}`));
  return (
    <fieldset className={styles.rule}>
      <legend className={styles.ruleTitle}><RuleIcon name={icon} />{title}</legend>
      <div className={styles.ruleBody}>
        {children}
        {messages.map((message) => <p key={message} className={styles.fieldError}>{message}</p>)}
      </div>
    </fieldset>
  );
}

export function Line({ children }: { children: ReactNode }) {
  return <div className={styles.line}>{children}</div>;
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

export function Choice({ context, path, label, options, onPick }: { context: EditorContext; path: string; label: string; options: Array<[string, string]>; onPick?: (value: string) => void }) {
  const value = valueAt(context.rules, path);
  return (
    <label className={styles.choice}>
      <span>{label}</span>
      <select value={typeof value === 'string' || typeof value === 'number' ? String(value) : ''} disabled={context.disabled} onChange={(event) => (onPick ? onPick(event.target.value) : context.change(path, event.target.value))}>
        {options.map(([option, text]) => <option key={option} value={option}>{text}</option>)}
      </select>
    </label>
  );
}

/** "Поне N часа/дни преди → …%", the last step is "по-късно"; 1 to `max` steps. */
function Ladder({ context, path, unit, max, returned, credit, before }: {
  context: EditorContext; path: string; unit: 'min_hours' | 'min_days'; max: number; returned: boolean; credit: boolean; before: string;
}) {
  const list = valueAt(context.rules, path);
  const steps = Array.isArray(list) ? list : [];
  const word = unit === 'min_hours' ? 'часа' : 'дни';
  const verb = returned ? 'връща се' : 'дължи се';
  const replace = (next: Json[]) => context.change(path, next);
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
                ? <span>{steps.length === 1 ? `При отказ по всяко време ${before}` : 'По-късно'} → {verb}</span>
                : <><span>Поне</span><NumberInput context={context} path={`${path}.${index}.${unit}`} label={`${unit === 'min_hours' ? 'Часове' : 'Дни'} преди, стъпало ${index + 1}`} min={1} /><span>{word} {before} → {verb}</span></>}
              <NumberInput context={context} path={`${path}.${index}.kept`} label={`Процент, който ${verb}, стъпало ${index + 1}`} max={100} returned={returned} />
              <span>%</span>
              {!last && !context.disabled && steps.length > 1 ? (
                <button type="button" className={styles.linkButton} onClick={() => replace(steps.filter((__, other) => other !== index))}>
                  Махни<span className="sr-only"> стъпало {index + 1}</span>
                </button>
              ) : null}
            </Line>
            {credit ? <Check context={context} path={`${path}.${index}.credit_offered`}>Предлагаме кредит за друга седмица — родителят решава</Check> : null}
          </div>
        );
      })}
      {!context.disabled && steps.length < max ? <button type="button" className={styles.linkButton} onClick={add}>Добави срок</button> : null}
    </>
  );
}

/** Up to 3 steps by the number of absences in a month; the last is "повече". */
function AbsenceTiers({ context }: { context: EditorContext }) {
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
              ? <span>{steps.length === 1 ? 'При отсъствия → дължи се' : 'Повече →'}</span>
              : <><span>До</span><NumberInput context={context} path={`${path}.${index}.up_to`} label={`Брой отсъствия, стъпало ${index + 1}`} max={31} /><span>отсъствия в месеца → дължи се</span></>}
            <NumberInput context={context} path={`${path}.${index}.kept`} label={`Процент от таксата, стъпало ${index + 1}`} max={100} />
            <span>% от таксата за месеца.</span>
            {!last && !context.disabled ? (
              <button type="button" className={styles.linkButton} onClick={() => replace(steps.filter((__, other) => other !== index))}>
                Махни<span className="sr-only"> стъпало {index + 1}</span>
              </button>
            ) : null}
          </Line>
        );
      })}
      {!context.disabled && steps.length < 3 ? (
        <button type="button" className={styles.linkButton} onClick={() => {
          const previous = steps.length > 1 ? numberAt(steps[steps.length - 2], 'up_to') ?? 0 : 0;
          const lastKept = numberAt(steps[steps.length - 1] ?? {}, 'kept') ?? 100;
          const previousKept = steps.length > 1 ? numberAt(steps[steps.length - 2], 'kept') ?? 100 : 100;
          replace([...steps.slice(0, -1), { up_to: previous + 3, kept: previousKept }, { up_to: null, kept: lastKept }]);
        }}>Добави стъпало</button>
      ) : null}
    </>
  );
}

function MonthPart({ context, part }: { context: EditorContext; part: 'start' | 'end' }) {
  const mode = valueAt(context.rules, `${part}.mode`);
  const pick = (next: string) => {
    const base = valueAt(context.rules, part);
    const current = base !== null && typeof base === 'object' && !Array.isArray(base) ? base : {};
    const cutoff = next === 'cutoff'
      ? { cutoff_day: numberAt(current, 'cutoff_day') ?? 15, kept_until_cutoff: numberAt(current, 'kept_until_cutoff') ?? (part === 'end' ? 50 : 100), kept_after_cutoff: numberAt(current, 'kept_after_cutoff') ?? (part === 'end' ? 100 : 50) }
      : { cutoff_day: null, kept_until_cutoff: null, kept_after_cutoff: null };
    context.change(part, { ...current, mode: next, ...cutoff });
  };
  return (
    <>
      <Choice context={context} path={`${part}.mode`} label={part === 'end' ? 'Месецът, в който спира:' : 'Месецът, в който започва:'} onPick={pick} options={[
        ['by_sessions', 'само заниманията до последния ден'],
        ['cutoff', 'според деня от месеца'],
        ['full_month', 'целият месец'],
      ].map(([value, text]) => [value, part === 'start' && value === 'by_sessions' ? 'само заниманията от първия ден' : text] as [string, string])} />
      {mode === 'cutoff' ? (
        <Line>
          <span>{part === 'end' ? 'Последен ден до' : 'Начало до'}</span>
          <NumberInput context={context} path={`${part}.cutoff_day`} label="Ден от месеца" min={1} max={28} />
          <span>-то число → дължи се</span>
          <NumberInput context={context} path={`${part}.kept_until_cutoff`} label="Процент от таксата до този ден" max={100} />
          <span>% от таксата за месеца. След това →</span>
          <NumberInput context={context} path={`${part}.kept_after_cutoff`} label="Процент от таксата след този ден" max={100} />
          <span>%.</span>
        </Line>
      ) : null}
    </>
  );
}

function PauseRow({ context }: { context: EditorContext }) {
  return (
    <RuleRow icon="pause" title="Пауза" paths={['pause']} context={context}>
      <Line>
        <span>Заявена поне</span>
        <NumberInput context={context} path="pause.notice_days" label="Дни предизвестие за пауза" max={60} />
        <span>дни по-рано → дължи се</span>
        <NumberInput context={context} path="pause.kept_on_time" label="Процент за заниманията при навременна пауза" max={100} />
        <span>% от заниманията в паузата. По-късно →</span>
        <NumberInput context={context} path="pause.kept_late" label="Процент за заниманията при късно заявена пауза" max={100} />
        <span>%.</span>
      </Line>
      <Line>
        <span>Най-много</span>
        <NumberInput context={context} path="pause.free_days_per_school_year" label="Дни пауза в учебната година" max={183} wide />
        <span>дни пауза в учебната година (61 дни ≈ 2 месеца). Най-кратка пауза</span>
        <NumberInput context={context} path="pause.min_days" label="Най-кратка пауза в дни" max={183} />
        <span>дни.</span>
      </Line>
      <Check context={context} path="pause.document_required">Пауза само с документ от лекар</Check>
    </RuleRow>
  );
}

function MakeupLines({ context }: { context: EditorContext }) {
  return (
    <>
      <Line>
        <Check context={context} path="makeup.enabled">Наваксване до</Check>
        <NumberInput context={context} path="makeup.within_days" label="Дни за наваксване" min={1} max={60} />
        <span>дни, ако отсъствието е заявено поне</span>
        <NumberInput context={context} path="makeup.notice_hours" label="Часове предварително" max={72} />
        <span>ч по-рано.</span>
      </Line>
      <Line>
        <span>Най-много</span>
        <NumberInput context={context} path="makeup.per_month" label="Наваксвания в месеца (празно — без ограничение)" min={1} max={31} />
        <span>в месеца (празно — без ограничение).</span>
      </Line>
      <Check context={context} path="makeup.only_if_space">Само ако в групата има свободно място</Check>
    </>
  );
}

function RefundRow({ context }: { context: EditorContext }) {
  return (
    <RuleRow icon="money" title="Връщане на пари" paths={['refund']} context={context}>
      <Choice context={context} path="refund" label="Върнатата сума:" options={[
        ['parent_choice', 'пари или кредит — по избор на родителя'],
        ['money', 'пари, по същия начин на плащане'],
        ['credit', 'кредит за следващо плащане'],
      ]} />
    </RuleRow>
  );
}

export function RuleEditor({ kind, context }: { kind: BillingKind; context: EditorContext }) {
  switch (kind) {
    case 'monthly':
      return (
        <>
          <RuleRow icon="end" title="Прекратяване" paths={['end']} context={context}>
            <MonthPart context={context} part="end" />
            <Line>
              <span>Предизвестие</span>
              <NumberInput context={context} path="end.notice_days" label="Дни предизвестие за прекратяване" max={30} />
              <span>дни (0 — без предизвестие).</span>
            </Line>
          </RuleRow>
          <RuleRow icon="start" title="Начало в средата на месеца" paths={['start']} context={context}>
            <MonthPart context={context} part="start" />
          </RuleRow>
          <PauseRow context={context} />
          <RuleRow icon="absences" title="Отсъствия" paths={['absences', 'makeup']} context={context}>
            <AbsenceTiers context={context} />
            <MakeupLines context={context} />
          </RuleRow>
          <RefundRow context={context} />
        </>
      );
    case 'full_period':
      return (
        <>
          <RuleRow icon="end" title="Отказ преди началото" paths={['before_start']} context={context}>
            <Ladder context={context} path="before_start.tiers" unit="min_days" max={6} returned credit={false} before="преди началото" />
          </RuleRow>
          <RuleRow icon="clock" title="Отказ след началото" paths={['after_start']} context={context}>
            <Line>
              <span>През първите</span>
              <NumberInput context={context} path="after_start.full_refund_days" label="Дни с пълно връщане след началото" max={60} />
              <span>дни се връща всичко за оставащите занимания. След това се връща</span>
              <NumberInput context={context} path="after_start.kept_of_remaining" label="Процент, който се връща от оставащите занимания" max={100} returned />
              <span>% от тях.</span>
            </Line>
          </RuleRow>
          <PauseRow context={context} />
          <RuleRow icon="absences" title="Отсъствия" paths={['makeup']} context={context}>
            <MakeupLines context={context} />
          </RuleRow>
          <RefundRow context={context} />
        </>
      );
    case 'visit':
      return (
        <>
          <RuleRow icon="clock" title="Отказ от посещение" paths={['cancellation']} context={context}>
            <Ladder context={context} path="cancellation.tiers" unit="min_hours" max={3} returned={false} credit={false} before="преди началото" />
          </RuleRow>
          <RuleRow icon="person" title="Неявяване" paths={['no_show_kept']} context={context}>
            <Line>
              <span>Детето не дойде без отказ → дължи се</span>
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
          <RuleRow icon="pause" title="Замразяване и удължаване" paths={['freeze_days_with_document', 'extension_days_on_request']} context={context}>
            <Line>
              <span>С документ от лекар картата се замразява до</span>
              <NumberInput context={context} path="freeze_days_with_document" label="Дни замразяване с документ" max={90} />
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
          <RuleRow icon="end" title="Отказ преди началото на седмицата" paths={['before_start']} context={context}>
            <Ladder context={context} path="before_start.tiers" unit="min_days" max={6} returned credit before="преди седмицата" />
          </RuleRow>
          <RuleRow icon="absences" title="Отсъствия в записана седмица" paths={['absences']} context={context}>
            <Line>
              <span>Първите</span>
              <NumberInput context={context} path="absences.kept_first_days" label="Дни отсъствие без връщане" max={5} />
              <span>дни → не се връщат. Всеки следващ → връща се</span>
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
            <DepositLines context={context} />
          </RuleRow>
          <RuleRow icon="end" title="Отказ преди тръгване" paths={['before_start']} context={context}>
            <Ladder context={context} path="before_start.tiers" unit="min_days" max={6} returned credit before="преди тръгване" />
            <p className={styles.hint}>Задържаната сума не бива да е по-голяма от реалните ви разходи.</p>
          </RuleRow>
          <RuleRow icon="document" title="Болест и друго дете" paths={['illness_with_document', 'transfer_to_another_child_until_days']} context={context}>
            <Line>
              <span>Болест преди тръгване с документ от лекар → връща се</span>
              <NumberInput context={context} path="illness_with_document.kept" label="Процент, който се връща при болест" max={100} returned />
              <span>%.</span>
            </Line>
            <Check context={context} path="illness_with_document.plus_costs">Без реално направените разходи</Check>
            <Line>
              <span>Друго дете може да заеме мястото до</span>
              <NumberInput context={context} path="transfer_to_another_child_until_days" label="Дни за друго дете на мястото" max={60} />
              <span>дни преди тръгване — без такса.</span>
            </Line>
          </RuleRow>
          <RuleRow icon="home" title="Ранно прибиране" paths={['early_departure_kept']} context={context}>
            <Line>
              <span>Връща се</span>
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
          <RuleRow icon="end" title="Напускане и депозит" paths={['notice_days', 'deposit_months']} context={context}>
            <Line>
              <span>Предизвестие</span>
              <NumberInput context={context} path="notice_days" label="Дни предизвестие" max={30} />
              <span>дни.</span>
            </Line>
            <Choice context={context} path="deposit_months" label="Депозит:" options={[['0', 'без депозит'], ['1', 'една месечна такса']]}
              onPick={(value) => context.change('deposit_months', Number(value))} />
          </RuleRow>
          <RuleRow icon="absences" title="Храна и болест" paths={['meals_by_attendance', 'sick_over_working_days', 'sick_next_month_kept']} context={context}>
            <Check context={context} path="meals_by_attendance">Храната се плаща според присъствието</Check>
            <Line>
              <span>Болест над</span>
              <NumberInput context={context} path="sick_over_working_days" label="Работни дни болест" max={60} />
              <span>работни дни в месеца → за следващия месец се дължи</span>
              <NumberInput context={context} path="sick_next_month_kept" label="Процент за следващия месец" max={100} />
              <span>% от таксата.</span>
            </Line>
          </RuleRow>
          <RefundRow context={context} />
        </>
      );
  }
}

function DepositLines({ context }: { context: EditorContext }) {
  const mode = valueAt(context.rules, 'deposit.mode');
  const pick = (next: string) => context.change('deposit', {
    mode: next,
    amount_cents: next === 'amount' ? numberAt(context.rules, 'deposit.amount_cents') ?? 15000 : null,
    percent: next === 'percent' ? numberAt(context.rules, 'deposit.percent') ?? 25 : null,
  });
  const cents = numberAt(context.rules, 'deposit.amount_cents');
  return (
    <>
      <Choice context={context} path="deposit.mode" label="При записване:" onPick={pick} options={[['none', 'без капаро'], ['percent', 'процент от цената'], ['amount', 'фиксирана сума']]} />
      {mode === 'percent' ? (
        <Line>
          <NumberInput context={context} path="deposit.percent" label="Процент капаро" max={30} />
          <span>% от цената — приспада се от нея.</span>
        </Line>
      ) : null}
      {mode === 'amount' ? (
        <Line>
          <input className={`${styles.number} ${styles.numberWide}`} type="number" inputMode="numeric" min={0} step={1} aria-label="Капаро в евро"
            value={cents === null ? '' : String(Math.round(cents / 100))} disabled={context.disabled}
            onChange={(event) => context.change('deposit.amount_cents', event.target.value.trim() === '' ? null : Math.round(Number(event.target.value)) * 100)} />
          <span>€ — приспада се от цената.</span>
        </Line>
      ) : null}
    </>
  );
}
