'use client';
import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { loadOptions, submitRequest, SubstituteError, type Options, type Reason, type SubmitBody } from './api';
import { dateLabel, selection, when } from './presentation';
import { sofiaDay } from '../schedule/presentation';
import dialogStyles from '../schedule/changes.module.css';
import styles from './substitute.module.css';

export function RequestDialog({ organizationId, initialDay, sessionId, onClose, onSent }: { organizationId: string; initialDay?: string; sessionId?: string; onClose: () => void; onSent: () => void }) {
  const id = useId();
  const dialog = useRef<HTMLDialogElement>(null);
  const heading = useRef<HTMLHeadingElement>(null);
  const reasonGroup = useRef<HTMLFieldSetElement>(null);
  const [key] = useState(() => crypto.randomUUID());
  const [limits] = useState(() => { const now = new Date(); const end = new Date(now); end.setUTCDate(end.getUTCDate() + 365); return { min: sofiaDay(now), max: sofiaDay(end) }; });
  const [from, setFrom] = useState(initialDay ?? limits.min);
  const [to, setTo] = useState(initialDay ?? limits.min);
  const [multiple, setMultiple] = useState(false);
  const [reason, setReason] = useState<Reason>();
  const [reasonError, setReasonError] = useState(false);
  const [note, setNote] = useState('');
  const [suggested, setSuggested] = useState('');
  const [picked, setPicked] = useState<string[]>([]);
  const [attempt, setAttempt] = useState(0);
  const [loaded, setLoaded] = useState<{ range: string; data?: Options; error?: string }>();
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState('');
  // A network retry sends exactly the same body and key; do not permit edits after an uncertain response.
  const [retryBody, setRetryBody] = useState<SubmitBody>();
  const keepSelection = useRef<string[] | undefined>(undefined);
  const range = `${from}|${multiple ? to : from}`;
  const validDates = !!from && from >= limits.min && from <= limits.max && (!multiple || (!!to && to >= from && to <= limits.max && (Date.parse(to) - Date.parse(from)) / 86400000 <= 31));
  const current = loaded?.range === range ? loaded : undefined;
  const data = current?.data;
  const locked = saving || !!retryBody;
  useEffect(() => { dialog.current?.showModal(); heading.current?.focus(); }, []);
  useEffect(() => {
    if (!validDates) return;
    const controller = new AbortController();
    const timer = setTimeout(() => {
      const [start, end] = range.split('|');
      loadOptions(organizationId, start, end, controller.signal).then(data => {
        if (controller.signal.aborted) return;
        setLoaded({ range, data }); setPicked(selection(data.sessions, keepSelection.current, from === initialDay && !multiple ? sessionId : undefined)); keepSelection.current = undefined;
        setSuggested(old => data.colleagues.some(p => p.profile_id === old) ? old : '');
      }).catch((error: unknown) => { if (!controller.signal.aborted) setLoaded({ range, error: error instanceof Error ? error.message : 'Заниманията не се заредиха.' }); });
    }, 300);
    return () => { clearTimeout(timer); controller.abort(); };
  }, [organizationId, range, validDates, attempt, sessionId, from, initialDay, multiple]);
  async function submit(event: FormEvent) {
    event.preventDefault();
    if (saving) return;
    if (!reason) { setReasonError(true); reasonGroup.current?.focus(); return; }
    if (!retryBody && (!data || !validDates || !picked.length || picked.length > 50)) { setNotice('Изберете от 1 до 50 занимания.'); return; }
    const body = retryBody ?? { sessions: data!.sessions.filter(s => picked.includes(s.id) && !s.requested).map(({ id, version }) => ({ id, version })), reason, note: note.trim() || null, suggested_profile_id: suggested || null };
    setSaving(true); setNotice('');
    try { await submitRequest(organizationId, body, key); onSent(); }
    catch (error: unknown) {
      if (error instanceof SubstituteError && error.status === 409) {
        setRetryBody(undefined); keepSelection.current = picked; setLoaded(undefined); setAttempt(n => n + 1); setNotice('Графикът е променен междувременно.');
      } else {
        if (!(error instanceof SubstituteError) || error.status >= 500) setRetryBody(body);
        else {
          setRetryBody(undefined);
          if (error.status === 422) { keepSelection.current = picked; setLoaded(undefined); setAttempt(n => n + 1); }
        }
        setNotice(error instanceof SubstituteError ? error.message : 'Заявката не се изпрати. Проверете връзката и опитайте пак.');
      }
    } finally { setSaving(false); }
  }
  const close = () => dialog.current?.close();
  const days = [...new Set(data?.sessions.map(s => sofiaDay(s.starts_at)) ?? [])];
  return <dialog ref={dialog} className={dialogStyles.dialog} aria-labelledby={`${id}-title`} onCancel={e => { e.stopPropagation(); if (saving) e.preventDefault(); }} onClose={e => { e.stopPropagation(); onClose(); }}>
    <form className={dialogStyles.inner} onSubmit={submit} noValidate>
      <header className={dialogStyles.header}><div><h2 id={`${id}-title`} tabIndex={-1} ref={heading}>Търся заместник</h2><p>Офисът ще избере кой ще ви замести.</p></div><button type="button" className={dialogStyles.close} disabled={saving} aria-label="Затвори заявката" onClick={close}>×</button></header>
      <div className={dialogStyles.body}>
        <fieldset disabled={locked}><legend>Кога няма да можете?</legend><div className={styles.choices}>
          <label className={styles.option}><input type="radio" name={`${id}-days`} checked={!multiple} onChange={() => { setMultiple(false); setLoaded(undefined); }} />Един ден</label>
          <label className={styles.option}><input type="radio" name={`${id}-days`} checked={multiple} onChange={() => { setMultiple(true); setTo(from); setLoaded(undefined); }} />Няколко дни</label>
        </div><div className={styles.dates}><label>{multiple ? 'От' : 'Ден'}<input type="date" {...limits} value={from} onChange={e => setFrom(e.target.value)} /></label>
          {multiple ? <label>До<input type="date" min={from || limits.min} max={limits.max} value={to} onChange={e => setTo(e.target.value)} /></label> : null}</div></fieldset>
        {!validDates ? <p role="alert" className={dialogStyles.warning}>Изберете ден от днес до една година напред (най-много 31 дни).</p> : !current ? <div className={styles.skeleton} role="status">Зареждане на заниманията…</div> : current.error ? <p role="alert">{current.error} <button type="button" disabled={locked} onClick={() => { setLoaded(undefined); setAttempt(n => n + 1); }}>Опитай пак</button></p> : data?.sessions.length === 0 ? <p>Нямате занимания в този ден.</p> : <fieldset disabled={locked}><legend>За кои занимания?</legend>
          {days.map(day => <div key={day} className={styles.stack}>{multiple ? <h3 className={styles.day}>{dateLabel(`${day}T12:00:00+02:00`)}</h3> : null}{data?.sessions.filter(s => sofiaDay(s.starts_at) === day).map(s => <label key={s.id} className={styles.option}><input type="checkbox" disabled={s.requested} checked={picked.includes(s.id) && !s.requested} onChange={e => setPicked(old => e.target.checked ? [...old, s.id] : old.filter(i => i !== s.id))} /><span><strong>{s.activity} · {s.group}</strong><small>{when(s)} · {s.location ?? 'Без място'} · {s.role === 'assistant' ? 'Помощник' : s.role === 'substitute' ? 'Заместник' : 'Водещ'}</small>{s.requested ? <small>Вече търсите заместник</small> : null}</span></label>)}</div>)}
        </fieldset>}
        <fieldset disabled={locked} ref={reasonGroup} tabIndex={-1} aria-describedby={reasonError ? `${id}-reason-error` : undefined}><legend>Причина</legend><div className={styles.choices}>{([['vacation','Отпуск'],['personal','Лични причини'],['other','Друго']] as const).map(([value,label]) => <label key={value} className={styles.option}><input type="radio" name={`${id}-reason`} checked={reason === value} onChange={() => { setReason(value); setReasonError(false); }} />{label}</label>)}</div>{reasonError ? <p id={`${id}-reason-error`} role="alert">Изберете причина.</p> : null}</fieldset>
        <label>Бележка за офиса (по избор)<textarea disabled={locked} rows={3} maxLength={300} value={note} onChange={e => setNote(e.target.value)} aria-describedby={`${id}-note`} /></label><p id={`${id}-note`} className={dialogStyles.hint}>Не пишете здравни подробности.{300 - note.length < 50 ? ` Остават ${300 - note.length} знака.` : ''}</p>
        <fieldset disabled={locked}><legend>Предлагам колега (по избор)</legend><div className={styles.stack}><label className={styles.option}><input type="radio" name={`${id}-person`} checked={!suggested} onChange={() => setSuggested('')} />Нямам предложение</label>{data?.colleagues.map(person => <label className={styles.option} key={person.profile_id}><input type="radio" name={`${id}-person`} checked={suggested === person.profile_id} onChange={() => setSuggested(person.profile_id)} /><span><strong>{person.name}</strong><small>{person.groups.join(' · ')}</small></span></label>)}</div><p className={dialogStyles.hint}>Кой ще замества, решава офисът.</p></fieldset>
        {notice ? <p role="alert" className={dialogStyles.warning}>{notice}</p> : null}
        {retryBody ? <p className={dialogStyles.hint}>Потвърждението не пристигна. Опитайте отново със същата заявка.</p> : null}
      </div>
      <footer className={dialogStyles.footer}><button type="button" disabled={saving} onClick={close}>Назад</button><button type="submit" className={dialogStyles.primary} disabled={saving || (!retryBody && (!data || !validDates || picked.length === 0 || picked.length > 50))}>{saving ? 'Изпращане…' : retryBody ? 'Опитай пак' : 'Изпрати заявката'}</button></footer>
    </form>
  </dialog>;
}
