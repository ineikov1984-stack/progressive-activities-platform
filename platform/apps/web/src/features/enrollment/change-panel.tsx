'use client';

import { type FormEvent, useEffect, useState } from 'react';
import {
  endEnrollment, type GroupRoster, loadTransferOptions, type PauseReason, pauseEnrollment, type RosterEnrollment,
  skipSession, transferEnrollment, type TransferOption,
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

function errorText(reason: unknown, fallback: string): string {
  return reason instanceof Error && reason.message ? reason.message : fallback;
}

/** Transfer, pause, skip or end — one clear choice, then a short form with a summary before confirming. */
export function ChangePanel({ organizationId, roster, enrollment, onDone, onFailed, onClose }: Props) {
  const [choice, setChoice] = useState<Choice>();
  const name = enrollment.child.name ?? 'детето';
  const active = enrollment.status === 'active';
  const props = { organizationId, roster, enrollment, name, onDone, onFailed, onBack: () => setChoice(undefined) };

  return (
    <div className={styles.changePanel} role="group" aria-label={`Промяна за ${name}`}>
      {choice === undefined ? (
        <>
          <h4>Какво да променим за {name}?</h4>
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

function useSubmit(onDone: (message: string) => void, onFailed: () => void) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  async function run(action: () => Promise<unknown>, success: string, fallback: string) {
    setBusy(true); setError('');
    try {
      await action();
      onDone(success);
    } catch (reason) {
      setError(errorText(reason, fallback));
      onFailed();
    } finally { setBusy(false); }
  }
  return { busy, error, run };
}

function FormButtons({ busy, label, danger, disabled, onBack }: { busy: boolean; label: string; danger?: boolean; disabled?: boolean; onBack: () => void }) {
  return (
    <div className={styles.actions}>
      <button type="submit" className={danger ? styles.danger : styles.primary} disabled={busy || disabled}>{busy ? 'Запазване…' : label}</button>
      <button type="button" className={styles.quiet} onClick={onBack} disabled={busy}>Назад</button>
    </div>
  );
}

function ReasonField({ id, value, onChange, label = 'Обяснение за родителя' }: { id: string; value: string; onChange: (value: string) => void; label?: string }) {
  return (
    <div className={styles.field}>
      <label htmlFor={id}>{label}</label>
      <textarea id={id} value={value} required maxLength={500} aria-describedby={`${id}-hint`} onChange={(event) => onChange(event.target.value)} />
      <p id={`${id}-hint`} className={styles.hint}>{reasonHint}</p>
    </div>
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
  const { busy, error, run } = useSubmit(onDone, onFailed);

  useEffect(() => {
    if (!date) return;
    let active = true;
    loadTransferOptions(organizationId, enrollment.id, date)
      .then((groups) => { if (active) { setOptions(groups); setLoadError(''); } })
      .catch((reason: unknown) => { if (active) { setOptions([]); setLoadError(errorText(reason, 'Групите не се заредиха.')); } });
    return () => { active = false; };
  }, [organizationId, enrollment.id, date]);

  const target = options?.find((option) => option.id === targetId);
  const targetFull = target !== undefined && target.remaining !== null && target.remaining < 1;
  const price = target?.prices.find((option) => option.id === priceId) ?? (target?.prices.length === 1 ? target.prices[0] : undefined);
  const ready = target !== undefined && !targetFull && price !== undefined && reason.trim() !== '' && date >= today;

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!target || !price) return;
    void run(
      () => transferEnrollment(organizationId, enrollment, { targetGroupId: target.id, priceOptionId: price.id, effectiveDate: date, reason }),
      `${name} е преместено в „${target.name}“ от ${shortDay(date)}.`,
      'Преместването не беше запазено.',
    );
  }

  return (
    <form className={styles.form} onSubmit={submit}>
      <h4>Премести {name} в друга група</h4>
      <div className={styles.field}>
        <label htmlFor={`move-date-${enrollment.id}`}>От коя дата е в новата група</label>
        <input id={`move-date-${enrollment.id}`} type="date" required min={today} max={addDays(today, 365)} value={date} onChange={(event) => setDate(event.target.value)} />
      </div>
      <fieldset className={styles.fieldset}>
        <legend>Нова група</legend>
        {options === undefined ? <span className={styles.skeleton} aria-busy="true" /> : loadError ? <p className={styles.error} role="alert">{loadError}</p>
          : options.length === 0 ? <p className={styles.hint}>Дейността няма друга публикувана група.</p> : options.map((option) => {
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
        <p className={styles.summary} role="status">
          {name} ще е в „{target.name}“ от {shortDay(date)}. Последен ден в „{roster.group.name}“: {shortDay(addDays(date, -1))}.
          {' '}Цена: {priceText({ amount_cents: price.amount_cents, currency: price.currency, unit: price.unit })}.
        </p>
      ) : null}
      {error ? <p className={styles.error} role="alert">{error}</p> : null}
      <FormButtons busy={busy} label="Премести" disabled={!ready} onBack={onBack} />
    </form>
  );
}

function PauseForm({ organizationId, enrollment, name, onDone, onFailed, onBack }: FormProps) {
  const today = sofiaDay();
  const [from, setFrom] = useState(addDays(today, 1));
  const [until, setUntil] = useState(addDays(today, 7));
  const [category, setCategory] = useState<PauseReason>();
  const [note, setNote] = useState('');
  const { busy, error, run } = useSubmit(onDone, onFailed);
  const tooLong = from !== '' && until !== '' && until >= addDays(from, MAX_PAUSE_DAYS);
  const order = from !== '' && until !== '' && until < from;
  const ready = category !== undefined && from >= today && !tooLong && !order;

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!category) return;
    void run(
      () => pauseEnrollment(organizationId, enrollment, { startsOn: from, endsOn: until, reasonCategory: category, note }),
      `Пауза за ${name} от ${shortDay(from)} до ${shortDay(until)}. Мястото в групата се пази.`,
      'Паузата не беше запазена.',
    );
  }

  return (
    <form className={styles.form} onSubmit={submit}>
      <h4>Пауза за {name}</h4>
      <div className={styles.fieldRow}>
        <div className={styles.field}>
          <label htmlFor={`pause-from-${enrollment.id}`}>От</label>
          <input id={`pause-from-${enrollment.id}`} type="date" required min={today} max={addDays(today, 365)} value={from} onChange={(event) => setFrom(event.target.value)} />
        </div>
        <div className={styles.field}>
          <label htmlFor={`pause-until-${enrollment.id}`}>До (включително)</label>
          <input id={`pause-until-${enrollment.id}`} type="date" required min={from || today} max={from ? addDays(from, MAX_PAUSE_DAYS - 1) : undefined} value={until} onChange={(event) => setUntil(event.target.value)} />
        </div>
      </div>
      {order ? <p className={styles.error} role="alert">Крайната дата е преди началната.</p> : tooLong ? <p className={styles.error} role="alert">Паузата може да е до 6 месеца.</p> : null}
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
      {ready ? <p className={styles.summary} role="status">Заниманията от {shortDay(from)} до {shortDay(until)} няма да се броят. Мястото в групата се пази.</p> : null}
      {error ? <p className={styles.error} role="alert">{error}</p> : null}
      <FormButtons busy={busy} label="Пусни паузата" disabled={!ready} onBack={onBack} />
    </form>
  );
}

function SkipForm({ organizationId, roster, enrollment, name, onDone, onFailed, onBack }: FormProps) {
  const sessions = skippableSessions(roster, enrollment);
  const [sessionId, setSessionId] = useState('');
  const [reason, setReason] = useState('');
  const { busy, error, run } = useSubmit(onDone, onFailed);
  const selected = sessions.find((session) => session.id === sessionId);

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!selected) return;
    void run(
      () => skipSession(organizationId, enrollment, { sessionId: selected.id, reason }),
      `${name} пропуска заниманието на ${sessionText(selected.starts_at)}.`,
      'Пропускът не беше запазен.',
    );
  }

  return (
    <form className={styles.form} onSubmit={submit}>
      <h4>Пропусни занимание за {name}</h4>
      {sessions.length === 0 ? <p className={styles.hint}>Няма предстоящи занимания, които могат да се пропуснат.</p> : (
        <fieldset className={styles.fieldset}>
          <legend>Кое занимание</legend>
          {sessions.map((session) => (
            <label key={session.id} className={styles.option}>
              <input type="radio" name={`skip-${enrollment.id}`} value={session.id} checked={sessionId === session.id} onChange={() => setSessionId(session.id)} />
              <span>{sessionText(session.starts_at)}</span>
            </label>
          ))}
        </fieldset>
      )}
      <ReasonField id={`skip-reason-${enrollment.id}`} value={reason} onChange={setReason} />
      {error ? <p className={styles.error} role="alert">{error}</p> : null}
      <FormButtons busy={busy} label="Пропусни" disabled={!selected || reason.trim() === ''} onBack={onBack} />
    </form>
  );
}

function EndForm({ organizationId, enrollment, name, onDone, onFailed, onBack }: FormProps) {
  const today = sofiaDay();
  const [lastDay, setLastDay] = useState(lastDayOfMonth(today));
  const [reason, setReason] = useState('');
  const { busy, error, run } = useSubmit(onDone, onFailed);
  const ready = lastDay >= today && reason.trim() !== '';

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    void run(
      () => endEnrollment(organizationId, enrollment, { lastDay, reason }),
      `Записването на ${name} приключва на ${shortDay(lastDay)}.`,
      'Прекратяването не беше запазено.',
    );
  }

  return (
    <form className={styles.form} onSubmit={submit}>
      <h4>Прекрати записването на {name}</h4>
      <div className={styles.field}>
        <label htmlFor={`end-day-${enrollment.id}`}>Последен ден, в който детето посещава</label>
        <input id={`end-day-${enrollment.id}`} type="date" required min={today} max={addDays(today, 365)} value={lastDay} onChange={(event) => setLastDay(event.target.value)} />
      </div>
      <ReasonField id={`end-reason-${enrollment.id}`} value={reason} onChange={setReason} />
      {lastDay >= today ? (
        <p className={styles.summary} role="status">
          Последното посещение е на {shortDay(lastDay)}. След това мястото в групата се освобождава. До тази дата можете да отмените прекратяването.
        </p>
      ) : null}
      {error ? <p className={styles.error} role="alert">{error}</p> : null}
      <FormButtons busy={busy} label="Прекрати записването" danger disabled={!ready} onBack={onBack} />
    </form>
  );
}
