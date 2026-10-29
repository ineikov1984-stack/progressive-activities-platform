'use client';

import { type FormEvent, useEffect, useRef, useState } from 'react';
import {
  changeErrorMessage, endEnrollment, type GroupRoster, loadTransferOptions, type PauseReason, pauseEnrollment,
  type RosterEnrollment, skipSession, transferEnrollment, type TransferOption,
} from './api';
import { addDays, lastDayOfMonth, pauseReasonLabels, placesText, priceText, sessionText, shortDay, skippableSessions, sofiaDay } from './presentation';
import styles from './enrollments.module.css';

type Choice = 'transfer' | 'pause' | 'skip' | 'end';
type Props = {
  organizationId: string;
  roster: GroupRoster;
  enrollment: RosterEnrollment;
  onDone: (message: string) => void;
  onFailed: () => void;
  onClose: () => void;
};

const MAX_PAUSE_DAYS = 183;
const reasonHint = 'Пишете така, както бихте казали на родителя. Без диагнози и лични подробности.';

/** Transfer, pause, skip or end — one clear choice, then a short form with a summary before confirming. */
export function ChangePanel({ organizationId, roster, enrollment, onDone, onFailed, onClose }: Props) {
  const [choice, setChoice] = useState<Choice>();
  const heading = useRef<HTMLHeadingElement>(null);
  const name = enrollment.child.name ?? 'детето';
  const active = enrollment.status === 'active';

  // Focus follows the step: the panel's heading on open, on every choice and on "Назад".
  useEffect(() => { heading.current?.focus(); }, [choice]);

  const titles: Record<Choice | 'choose', string> = {
    choose: `Какво да променим за ${name}?`,
    transfer: `Премести ${name} в друга група`,
    pause: `Пауза за ${name}`,
    skip: `Пропусни занимание за ${name}`,
    end: `Прекрати записването на ${name}`,
  };
  const props = { organizationId, roster, enrollment, name, onDone, onFailed, onBack: () => setChoice(undefined) };

  return (
    <div className={styles.changePanel} role="group" aria-labelledby={`change-title-${enrollment.id}`}>
      <h4 id={`change-title-${enrollment.id}`} ref={heading} tabIndex={-1}>{titles[choice ?? 'choose']}</h4>
      {choice === undefined ? (
        <>
          <div className={styles.choices}>
            {active ? <button type="button" className={styles.choice} onClick={() => setChoice('transfer')}><strong>Премести в друга група</strong><span>От дата по ваш избор</span></button> : null}
            <button type="button" className={styles.choice} onClick={() => setChoice('pause')}><strong>Пауза</strong><span>Болест или ваканция — мястото се пази</span></button>
            <button type="button" className={styles.choice} onClick={() => setChoice('skip')}><strong>Пропусни занимание</strong><span>Една конкретна дата</span></button>
            {active ? <button type="button" className={styles.choice} onClick={() => setChoice('end')}><strong>Прекрати</strong><span>Детето спира да посещава</span></button> : null}
          </div>
          <div className={styles.actions}><button type="button" className={styles.quiet} onClick={onClose}>Затвори</button></div>
        </>
      ) : choice === 'transfer' ? <TransferForm {...props} />
        : choice === 'pause' ? <PauseForm {...props} />
          : choice === 'skip' ? <SkipForm {...props} />
            : <EndForm {...props} />}
    </div>
  );
}

type FormProps = Omit<Props, 'onClose'> & { name: string; onBack: () => void };

/** Submit with a plain message instead of a silently disabled button. */
function useSubmit(onDone: (message: string) => void, onFailed: () => void) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  function fail(message: string) { setError(message); }
  async function run(action: () => Promise<unknown>, success: string, fallback: string) {
    setBusy(true); setError('');
    try {
      await action();
      onDone(success);
    } catch (reason) {
      setError(changeErrorMessage(reason, fallback));
      onFailed();
    } finally { setBusy(false); }
  }
  return { busy, error, run, fail };
}

function FormButtons({ busy, label, danger, error, onBack }: { busy: boolean; label: string; danger?: boolean; error: string; onBack: () => void }) {
  return (
    <>
      {error ? <p className={styles.error} role="alert">{error}</p> : null}
      <div className={styles.actions}>
        <button type="submit" className={danger ? styles.danger : styles.primary} disabled={busy}>{busy ? 'Запазване…' : label}</button>
        <button type="button" className={styles.quiet} onClick={onBack} disabled={busy}>Назад</button>
      </div>
    </>
  );
}

function ReasonField({ id, value, onChange }: { id: string; value: string; onChange: (value: string) => void }) {
  return (
    <div className={styles.field}>
      <label htmlFor={id}>Обяснение за родителя</label>
      <textarea id={id} value={value} maxLength={500} aria-describedby={`${id}-hint`} onChange={(event) => onChange(event.target.value)} />
      <p id={`${id}-hint`} className={styles.hint}>{reasonHint}</p>
    </div>
  );
}

function NothingToDo({ text, onBack }: { text: string; onBack: () => void }) {
  return (
    <>
      <p className={styles.hint}>{text}</p>
      <div className={styles.actions}><button type="button" className={styles.secondary} onClick={onBack}>Назад</button></div>
    </>
  );
}

function TransferForm({ organizationId, roster, enrollment, name, onDone, onFailed, onBack }: FormProps) {
  const today = sofiaDay();
  const [date, setDate] = useState(addDays(today, 1));
  const [options, setOptions] = useState<TransferOption[]>();
  const [loadError, setLoadError] = useState('');
  const [targetId, setTargetId] = useState('');
  const [priceId, setPriceId] = useState('');
  const [reason, setReason] = useState('');
  const { busy, error, run, fail } = useSubmit(onDone, onFailed);

  useEffect(() => {
    if (!date) return;
    let active = true;
    loadTransferOptions(organizationId, enrollment.id, date)
      .then((groups) => { if (active) { setOptions(groups); setLoadError(''); } })
      .catch((reason: unknown) => { if (active) { setOptions([]); setLoadError(changeErrorMessage(reason, 'Групите не се заредиха. Опитайте отново.')); } });
    return () => { active = false; };
  }, [organizationId, enrollment.id, date]);

  if (options !== undefined && options.length === 0 && !loadError) {
    return <NothingToDo text="Дейността няма друга публикувана група. Създайте я в стъпка 7 на дейността." onBack={onBack} />;
  }

  const target = options?.find((option) => option.id === targetId);
  const targetFull = target !== undefined && target.remaining !== null && target.remaining < 1;
  const price = target?.prices.find((option) => option.id === priceId) ?? (target?.prices.length === 1 ? target.prices[0] : undefined);
  const notStarted = date !== '' && date <= enrollment.starts_on;

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const missing = date < today ? 'Изберете дата от днес нататък.'
      : !target ? 'Изберете новата група.'
        : targetFull ? 'В тази група няма място от избраната дата. Изберете друга група или дата.'
          : !price ? 'Изберете цена в новата група.'
            : !reason.trim() ? 'Напишете обяснение за родителя.' : '';
    if (missing || !target || !price) { fail(missing); return; }
    void run(
      () => transferEnrollment(organizationId, enrollment, { targetGroupId: target.id, priceOptionId: price.id, effectiveDate: date, reason }),
      `Преместването на ${name} в „${target.name}“ е записано от ${shortDay(date)}.`,
      'Преместването не беше запазено.',
    );
  }

  return (
    <form className={styles.form} onSubmit={submit} noValidate>
      <div className={styles.field}>
        <label htmlFor={`move-date-${enrollment.id}`}>От коя дата е в новата група</label>
        <input id={`move-date-${enrollment.id}`} type="date" min={today} max={addDays(today, 365)} value={date} onChange={(event) => setDate(event.target.value)} />
      </div>
      <fieldset className={styles.fieldset}>
        <legend>Нова група</legend>
        {options === undefined ? <span className={styles.skeleton} aria-busy="true" /> : loadError ? <p className={styles.error} role="alert">{loadError}</p>
          : options.map((option) => {
            const full = option.remaining !== null && option.remaining < 1;
            return (
              <label key={option.id} className={`${styles.option} ${full ? styles.optionDisabled : ''}`}>
                <input type="radio" name={`target-${enrollment.id}`} value={option.id} checked={targetId === option.id} disabled={full}
                  onChange={() => { setTargetId(option.id); setPriceId(option.prices.length === 1 ? option.prices[0].id : ''); }} />
                <span><strong>{option.name}</strong><span className={styles.small}>{full ? 'Няма свободно място от тази дата' : placesText(option.remaining)}</span></span>
              </label>
            );
          })}
      </fieldset>
      {target && target.prices.length === 0 ? <p className={styles.error} role="alert">Групата няма цена за записване за периода. Добавете я в стъпка 6 на дейността.</p> : null}
      {target && target.prices.length > 1 ? (
        <fieldset className={styles.fieldset}>
          <legend>Цена в новата група</legend>
          {target.prices.map((option) => (
            <label key={option.id} className={styles.option}>
              <input type="radio" name={`price-${enrollment.id}`} value={option.id} checked={priceId === option.id} onChange={() => setPriceId(option.id)} />
              <span>{priceText({ amount_cents: option.amount_cents, currency: option.currency, unit: option.unit })}</span>
            </label>
          ))}
        </fieldset>
      ) : null}
      <ReasonField id={`move-reason-${enrollment.id}`} value={reason} onChange={setReason} />
      {target && price && !targetFull ? (
        <p className={styles.summary}>
          {name} ще е в „{target.name}“ от {shortDay(date)}.{' '}
          {notStarted
            ? `Записването в „${roster.group.name}“ още не е започнало и ще бъде отменено.`
            : `Последен ден в „${roster.group.name}“: ${shortDay(addDays(date, -1))}.`}
          {' '}Цена: {priceText({ amount_cents: price.amount_cents, currency: price.currency, unit: price.unit })}.
        </p>
      ) : null}
      <FormButtons busy={busy} label="Премести" error={error} onBack={onBack} />
    </form>
  );
}

function PauseForm({ organizationId, roster, enrollment, name, onDone, onFailed, onBack }: FormProps) {
  const today = sofiaDay();
  // From today when today's session has not started yet ("болен е от днес"), otherwise from tomorrow.
  const todayStillAhead = (roster.upcoming_sessions ?? []).some((session) => sofiaDay(session.starts_at) === today);
  const firstDay = todayStillAhead ? today : addDays(today, 1);
  const [from, setFrom] = useState(firstDay);
  const [until, setUntil] = useState(addDays(firstDay, 6));
  const [category, setCategory] = useState<PauseReason>();
  const [note, setNote] = useState('');
  const { busy, error, run, fail } = useSubmit(onDone, onFailed);
  const order = from !== '' && until !== '' && until < from;
  const tooLong = from !== '' && until !== '' && until >= addDays(from, MAX_PAUSE_DAYS);

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const missing = from < today ? 'Изберете начало от днес нататък.'
      : order ? 'Изберете крайна дата след началната.'
        : tooLong ? 'Изберете крайна дата до 6 месеца от началото.'
          : !category ? 'Изберете причина.' : '';
    if (missing || !category) { fail(missing); return; }
    void run(
      () => pauseEnrollment(organizationId, enrollment, { startsOn: from, endsOn: until, reasonCategory: category, note }),
      `Пауза за ${name} от ${shortDay(from)} до ${shortDay(until)}. Мястото в групата се пази.`,
      'Паузата не беше запазена.',
    );
  }

  return (
    <form className={styles.form} onSubmit={submit} noValidate>
      <div className={styles.fieldRow}>
        <div className={styles.field}>
          <label htmlFor={`pause-from-${enrollment.id}`}>От</label>
          <input id={`pause-from-${enrollment.id}`} type="date" min={today} max={addDays(today, 365)} value={from} onChange={(event) => setFrom(event.target.value)} />
        </div>
        <div className={styles.field}>
          <label htmlFor={`pause-until-${enrollment.id}`}>До (включително)</label>
          <input id={`pause-until-${enrollment.id}`} type="date" min={from || today} max={from ? addDays(from, MAX_PAUSE_DAYS - 1) : undefined} value={until} onChange={(event) => setUntil(event.target.value)} />
        </div>
      </div>
      {order ? <p className={styles.error} role="alert">Изберете крайна дата след началната.</p> : tooLong ? <p className={styles.error} role="alert">Изберете крайна дата до 6 месеца от началото.</p> : null}
      <fieldset className={styles.fieldset}>
        <legend>Причина</legend>
        {(Object.keys(pauseReasonLabels) as PauseReason[]).map((key) => (
          <label key={key} className={styles.option}>
            <input type="radio" name={`pause-reason-${enrollment.id}`} value={key} checked={category === key} onChange={() => setCategory(key)} />
            <span>{pauseReasonLabels[key]}</span>
          </label>
        ))}
      </fieldset>
      <div className={styles.field}>
        <label htmlFor={`pause-note-${enrollment.id}`}>Бележка за родителя (по желание)</label>
        <textarea id={`pause-note-${enrollment.id}`} value={note} maxLength={300} aria-describedby={`pause-note-hint-${enrollment.id}`} onChange={(event) => setNote(event.target.value)} />
        <p id={`pause-note-hint-${enrollment.id}`} className={styles.hint}>{reasonHint}</p>
      </div>
      {!order && !tooLong && from >= today ? <p className={styles.summary}>Заниманията от {shortDay(from)} до {shortDay(until)} няма да се броят. Мястото в групата се пази.</p> : null}
      <FormButtons busy={busy} label="Запиши паузата" error={error} onBack={onBack} />
    </form>
  );
}

function SkipForm({ organizationId, roster, enrollment, name, onDone, onFailed, onBack }: FormProps) {
  const sessions = skippableSessions(roster, enrollment);
  const [sessionId, setSessionId] = useState('');
  const [reason, setReason] = useState('');
  const { busy, error, run, fail } = useSubmit(onDone, onFailed);
  const selected = sessions.find((session) => session.id === sessionId);

  if (sessions.length === 0) return <NothingToDo text="Няма предстоящи занимания, които детето посещава и могат да се пропуснат." onBack={onBack} />;

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const missing = !selected ? 'Изберете занимание.' : !reason.trim() ? 'Напишете обяснение за родителя.' : '';
    if (missing || !selected) { fail(missing); return; }
    void run(
      () => skipSession(organizationId, enrollment, { sessionId: selected.id, reason }),
      `Записано е, че ${name} пропуска заниманието ${sessionText(selected.starts_at)}.`,
      'Пропускът не беше запазен.',
    );
  }

  return (
    <form className={styles.form} onSubmit={submit} noValidate>
      <fieldset className={styles.fieldset}>
        <legend>Кое занимание</legend>
        {sessions.map((session) => (
          <label key={session.id} className={styles.option}>
            <input type="radio" name={`skip-${enrollment.id}`} value={session.id} checked={sessionId === session.id} onChange={() => setSessionId(session.id)} />
            <span>{sessionText(session.starts_at)}</span>
          </label>
        ))}
      </fieldset>
      <ReasonField id={`skip-reason-${enrollment.id}`} value={reason} onChange={setReason} />
      {selected ? <p className={styles.summary}>{name} няма да дойде на {sessionText(selected.starts_at)}. Заниманието няма да се брои.</p> : null}
      <FormButtons busy={busy} label="Пропусни заниманието" error={error} onBack={onBack} />
    </form>
  );
}

function EndForm({ organizationId, enrollment, name, onDone, onFailed, onBack }: FormProps) {
  const today = sofiaDay();
  const [lastDay, setLastDay] = useState(lastDayOfMonth(today));
  const [reason, setReason] = useState('');
  const { busy, error, run, fail } = useSubmit(onDone, onFailed);
  const notStarted = lastDay !== '' && lastDay < enrollment.starts_on;

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const missing = lastDay < today ? 'Изберете дата от днес нататък.' : !reason.trim() ? 'Напишете обяснение за родителя.' : '';
    if (missing) { fail(missing); return; }
    void run(
      () => endEnrollment(organizationId, enrollment, { lastDay, reason }),
      notStarted ? `Записването на ${name} е отменено.` : `Записването на ${name} приключва на ${shortDay(lastDay)}.`,
      'Прекратяването не беше запазено.',
    );
  }

  return (
    <form className={styles.form} onSubmit={submit} noValidate>
      <div className={styles.field}>
        <label htmlFor={`end-day-${enrollment.id}`}>Последен ден, в който детето посещава</label>
        <input id={`end-day-${enrollment.id}`} type="date" min={today} max={enrollment.ends_on ?? addDays(today, 365)} value={lastDay} onChange={(event) => setLastDay(event.target.value)} />
      </div>
      <ReasonField id={`end-reason-${enrollment.id}`} value={reason} onChange={setReason} />
      {lastDay >= today ? (
        <p className={styles.summary}>
          {notStarted
            ? 'Записването още не е започнало и ще бъде отменено. Това не може да се върне.'
            : `Последното посещение е на ${shortDay(lastDay)}. След това мястото в групата се освобождава. До тази дата можете да отмените прекратяването.`}
        </p>
      ) : null}
      <FormButtons busy={busy} label={notStarted ? 'Отмени записването' : 'Прекрати записването'} danger error={error} onBack={onBack} />
    </form>
  );
}
