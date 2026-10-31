'use client';

import { useEffect, useId, useRef, useState } from 'react';
import { loadSessionPanel, type ScheduleBoard, type ScheduleLocation, type ScheduleSession } from './api';
import { applyChange, ChangeApiError, changeLocations, previewChange } from './change-api';
import { canApply, changeTitles, type ChangeAction, type ChangeBody, type ChangePreview, type ChangeResult, warningKeys } from './changes';
import { dayName, movedFromLabel, scheduleNames, sessionWhen, sofiaDay, startClock, timeRange } from './presentation';
import styles from './changes.module.css';

type Form = { date: string; from: string; to: string; location: string; group: string; reason: string; reasonToParents: boolean; notify: boolean;
  days: string[]; limited: boolean; locations: string[]; activities: string[] };
type Props = { organizationId: string; action: ChangeAction; sessions: ScheduleSession[]; board: ScheduleBoard; day: string;
  onClose: () => void; onApplied: (result: ChangeResult) => void };
const check = (values: string[], value: string) => values.includes(value) ? values.filter((id) => id !== value) : [...values, value];

/** The approved ScheduleNew/ScheduleBulk dialogs; native modal focus, Escape and return to the opener. */
export function ScheduleChangeDialog({ organizationId, action, sessions, board, day, onClose, onApplied }: Props) {
  const id = useId();
  const dialog = useRef<HTMLDialogElement>(null);
  const heading = useRef<HTMLHeadingElement>(null);
  const [key] = useState(() => crypto.randomUUID());
  const [targets, setTargets] = useState(sessions);
  const [form, setForm] = useState<Form>(() => ({ date: sessions[0] ? sofiaDay(sessions[0].starts_at) : day,
    from: sessions[0] ? startClock(sessions[0]) : '10:00', to: sessions[0] ? timeRange(sessions[0]).split('–')[1] : '11:00',
    location: action === 'add' ? '__group__' : sessions[0]?.location_id ?? '', group: board.groups.find((g) => board.abilities[g.id]?.manage)?.id ?? '',
    reason: '', reasonToParents: action === 'move', notify: true, days: [], limited: false, locations: [], activities: [] }));
  const [dateToAdd, setDateToAdd] = useState(day);
  const [locations, setLocations] = useState<ScheduleLocation[]>(board.locations);
  const [placesError, setPlacesError] = useState('');
  const [placesAttempt, setPlacesAttempt] = useState(0);
  const [confirmed, setConfirmed] = useState(false);
  const [saving, setSaving] = useState(false);
  const [attempt, setAttempt] = useState(0);
  const [notice, setNotice] = useState('');
  const [loaded, setLoaded] = useState<{ key: string; data?: ChangePreview; error?: string }>();
  const names = scheduleNames(board);
  const timed = action === 'move' || action === 'add';
  const placeField = timed || action === 'change_location';
  const reasonRequired = ['move', 'cancel', 'change_location', 'holiday'].includes(action);
  const optionalNotify = ['change_location', 'holiday', 'add'].includes(action);
  const body: ChangeBody = { action, reason: form.reason.trim(), reason_to_parents: action === 'holiday' || form.reasonToParents,
    ...(optionalNotify ? { notify_parents: form.notify } : {}),
    ...(!['holiday', 'add'].includes(action) ? { sessions: targets.map((s) => ({ id: s.id, version: s.version ?? 0 })) } : {}),
    ...(timed ? { date: form.date, from: form.from, to: form.to } : {}), ...(placeField && form.location !== '__group__' ? { location_id: form.location || null } : {}),
    ...(action === 'add' ? { group_id: form.group } : {}),
    ...(action === 'holiday' ? { days: form.days, locations: form.limited ? form.locations : [], activities: form.limited ? form.activities : [] } : {}) };
  const bodyKey = JSON.stringify(body);
  const valid = (!reasonRequired || !!form.reason.trim()) && form.reason.trim().length <= 2000 &&
    (!timed || (!!form.date && !!form.from && !!form.to && form.to > form.from)) && (action !== 'add' || !!form.group) &&
    (action !== 'holiday' || (form.days.length > 0 && (!form.limited || form.locations.length + form.activities.length > 0)));
  const invalidTimes = timed && !!form.from && !!form.to && form.to <= form.from;
  const original = targets[0]?.moved_from ?? targets[0]?.starts_at;
  const backToOriginal = !!original && form.date === sofiaDay(original) && form.from === startClock({ starts_at: original });
  const current = loaded?.key === bodyKey ? loaded : undefined;
  const preview = current?.data;
  const pending = valid && !current;
  useEffect(() => {
    const element = dialog.current;
    if (element && !element.open) element.showModal();
    heading.current?.focus();
  }, []);
  useEffect(() => {
    const controller = new AbortController();
    changeLocations(organizationId, controller.signal).then((values) => { setLocations(values); setPlacesError(''); })
      .catch((error: unknown) => { if (!controller.signal.aborted) setPlacesError(error instanceof Error ? error.message : 'Местата не се заредиха.'); });
    return () => controller.abort();
  }, [organizationId, placesAttempt]);
  useEffect(() => {
    if (!valid) return;
    const controller = new AbortController();
    const timer = window.setTimeout(() => {
      previewChange(organizationId, JSON.parse(bodyKey) as ChangeBody, controller.signal)
        .then((data) => { if (!controller.signal.aborted) setLoaded({ key: bodyKey, data }); })
        .catch(async (error: unknown) => {
          if (controller.signal.aborted) return;
          if (error instanceof ChangeApiError && error.status === 409 && error.details.changed?.length) {
            setConfirmed(false); setNotice('Графикът е променен междувременно. Прегледайте отново.');
            try {
              const refreshed = await Promise.all(error.details.changed.map((id) => loadSessionPanel(organizationId, id)));
              if (!controller.signal.aborted) setTargets((old) => old.map((s) => refreshed.find((p) => p.session.id === s.id)?.session ?? s));
              return;
            } catch { if (controller.signal.aborted) return; }
          }
          setLoaded({ key: bodyKey, error: error instanceof Error ? error.message : 'Прегледът не се зареди.' });
        });
    }, 400);
    return () => { window.clearTimeout(timer); controller.abort(); };
  }, [organizationId, bodyKey, valid, attempt]);
  function edit<K extends keyof Form>(field: K, value: Form[K]) {
    setForm((old) => ({ ...old, [field]: value })); setConfirmed(false); setNotice('');
  }
  async function submit(event: React.FormEvent) {
    event.preventDefault();
    if (saving || !valid || !canApply(preview, confirmed) || !preview) return;
    setSaving(true); setNotice('');
    try {
      const result = await applyChange(organizationId, { ...body,
        ...(action === 'holiday' ? { sessions: preview.sessions.map(({ id, version }) => ({ id, version })) } : {}),
        confirmed_warnings: confirmed ? warningKeys(preview.warnings) : [] }, key);
      dialog.current?.close(); onApplied(result);
    } catch (error: unknown) {
      setConfirmed(false);
      if (error instanceof ChangeApiError && error.status === 409) {
        setNotice('Графикът е променен междувременно. Прегледайте отново.');
        if (error.details.preview) setLoaded({ key: bodyKey, data: error.details.preview });
        else {
          const changed = error.details.changed ?? targets.map((s) => s.id);
          try {
            const refreshed = await Promise.all(changed.map((id) => loadSessionPanel(organizationId, id)));
            setTargets((old) => old.map((s) => refreshed.find((p) => p.session.id === s.id)?.session ?? s));
            setAttempt((old) => old + 1);
          } catch { setLoaded({ key: bodyKey, error: 'Графикът не се обнови. Затворете прозореца и заредете отново.' }); }
        }
      } else if (error instanceof ChangeApiError && error.details.warnings) {
        setLoaded({ key: bodyKey, data: { ...preview, warnings: error.details.warnings } });
        setNotice('Има ново предупреждение. Прегледайте го и потвърдете отново.');
      } else setNotice(error instanceof Error ? error.message : 'Промяната не се запази. Опитайте пак.');
    } finally { setSaving(false); }
  }
  const [dateLimits] = useState(() => { const now = new Date(); const end = new Date(now); end.setUTCDate(end.getUTCDate() + 365); return { min: sofiaDay(now), max: sofiaDay(end) }; });
  const confirmLabel = action === 'move' ? 'Знам, премести въпреки това' : 'Знам, продължи въпреки това';
  const buttonLabel = action === 'holiday' ? `Отмени ${preview?.totals.sessions ?? 0} занимания`
    : action === 'cancel' && targets.length > 1 ? 'Отмени избраните' : action === 'add' ? 'Добави заниманието' : action === 'move' ? 'Премести' : changeTitles[action];
  return (
    <dialog ref={dialog} className={`${styles.dialog} ${action === 'holiday' ? styles.holiday : ''}`} aria-labelledby={`${id}-title`}
      onCancel={(e) => { e.stopPropagation(); if (saving) e.preventDefault(); }} onClose={(e) => { e.stopPropagation(); onClose(); }}>
      <form className={styles.inner} onSubmit={submit}>
        <header className={styles.header}>
          <div><h2 id={`${id}-title`} ref={heading} tabIndex={-1}>{action === 'cancel' && targets.length > 1 ? 'Отмени избраните' : changeTitles[action]}</h2>
            {targets.length === 1 ? <p>{names.activity(targets[0].activity_id)?.title} · {names.group(targets[0].group_id)?.name} · {sessionWhen(targets[0])}</p> : null}
            {action === 'holiday' ? <p>Отменя заниманията в избраните дни — за празници и ваканции.</p> : null}</div>
          <button type="button" className={styles.close} aria-label="Затвори промяната" disabled={saving} onClick={() => dialog.current?.close()}>×</button>
        </header>
        <div className={styles.body}>
          {action === 'add' ? <label>Група<select value={form.group} onChange={(e) => { const group = board.groups.find((g) => g.id === e.target.value); edit('group', group?.id ?? ''); edit('location', '__group__'); }} required>
            {board.groups.filter((g) => board.abilities[g.id]?.manage).map((g) => <option key={g.id} value={g.id}>{names.activity(g.activity_id)?.title} · {g.name}</option>)}</select></label> : null}
          {timed ? <div className={styles.times}><label>{action === 'move' ? 'Нов ден' : 'Ден'}<input type="date" {...dateLimits} value={form.date} onChange={(e) => edit('date', e.target.value)} required /></label>
            <label>От<input type="time" aria-invalid={invalidTimes || undefined} aria-describedby={invalidTimes ? `${id}-time-error` : undefined} value={form.from} onChange={(e) => edit('from', e.target.value)} required /></label>
            <label>До<input type="time" aria-invalid={invalidTimes || undefined} aria-describedby={invalidTimes ? `${id}-time-error` : undefined} value={form.to} onChange={(e) => edit('to', e.target.value)} required /></label></div> : null}
          {invalidTimes ? <p id={`${id}-time-error`} className={styles.warning} role="alert">Крайният час трябва да е след началния.</p> : null}
          {placeField ? <label>Място<select value={form.location} onChange={(e) => edit('location', e.target.value)}>{action === 'add' ? <option value="__group__">Мястото на групата</option> : null}<option value="">Без място</option>
            {locations.map((location) => <option key={location.id} value={location.id}>{location.name}</option>)}</select></label> : null}
          {placesError && (placeField || action === 'holiday') ? <div role="alert">{placesError} <button type="button" onClick={() => setPlacesAttempt((n) => n + 1)}>Опитай пак</button></div> : null}
          {action === 'holiday' ? <>
            <fieldset><legend>Дни</legend><div className={styles.days}>{form.days.map((day) => <button type="button" key={day} aria-label={`Премахни ${dayName(day).date}`} onClick={() => edit('days', form.days.filter((d) => d !== day))}>{dayName(day).short}, {dayName(day).date} ×</button>)}</div>
              <div className={styles.addDay}><label>Добави ден<input type="date" {...dateLimits} value={dateToAdd} onChange={(e) => setDateToAdd(e.target.value)} /></label>
                <button type="button" disabled={!dateToAdd || form.days.length >= 31 || form.days.includes(dateToAdd)} onClick={() => edit('days', [...form.days, dateToAdd].sort())}>+ Добави ден</button></div></fieldset>
            <fieldset><legend>Кои занимания</legend>
              <label className={styles.check}><input type="radio" name={`${id}-scope`} checked={!form.limited} onChange={() => edit('limited', false)} />Всички</label>
              <label className={styles.check}><input type="radio" name={`${id}-scope`} checked={form.limited} onChange={() => edit('limited', true)} />Само на избрани места или дейности</label>
              {form.limited ? <div className={styles.filters}>{[['Места', locations, 'locations'], ['Дейности', board.activities.map((a) => ({ id: a.id, name: a.title })), 'activities']].map(([label, values, field]) => <details key={String(field)}><summary>{String(label)}</summary>
                <div className={styles.choices}>{(values as ScheduleLocation[]).map((value) => <label key={value.id} className={styles.check}><input type="checkbox" checked={form[field as 'locations' | 'activities'].includes(value.id)} onChange={() => edit(field as 'locations' | 'activities', check(form[field as 'locations' | 'activities'], value.id))} />{value.name}</label>)}</div></details>)}</div> : null}
            </fieldset>
          </> : null}
          {action !== 'restore' ? <label>Причина{!reasonRequired ? ' (по избор)' : ''}<textarea rows={3} maxLength={2000} value={form.reason} required={reasonRequired} onChange={(e) => edit('reason', e.target.value)} /></label> : null}
          {action !== 'holiday' && action !== 'restore' ? <label className={styles.check}><input type="checkbox" checked={form.reasonToParents} onChange={(e) => edit('reasonToParents', e.target.checked)} />Изпрати причината на родителите</label> : null}
          {action === 'cancel' && !form.reasonToParents ? <p className={styles.hint}>Иначе писмото казва само, че заниманието няма да се проведе.</p> : null}
          {optionalNotify ? <label className={styles.check}><input type="checkbox" checked={form.notify} onChange={(e) => edit('notify', e.target.checked)} />Изпрати писмо на родителите</label> : null}
          {action === 'holiday' ? <p className={styles.hint}>Причината ще бъде включена в писмото.</p> : null}
          {preview?.warnings.map((warning, i) => <p key={`${warning.type}-${warning.session_id}-${warning.other_session_id}-${i}`} className={styles.warning}>⚠ {warning.message}</p>)}
          {preview && warningKeys(preview.warnings).length ? <label className={styles.check}><input type="checkbox" checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} />{confirmLabel}</label> : null}
          {pending ? <div className={styles.summary} role="status">Подготвяме прегледа…</div> : null}
          {current?.error ? <div className={styles.warning} role="alert">{current.error} <button type="button" onClick={() => { setLoaded(undefined); setAttempt((n) => n + 1); }}>Опитай пак</button></div> : null}
          {preview ? <div className={styles.summary} aria-live="polite">
            <strong>{action === 'holiday' ? `Ще се отменят ${preview.totals.sessions} занимания в ${preview.totals.groups} групи` : `Засяга ${preview.totals.children} деца`}</strong>
            {preview.totals.visits + preview.totals.visit_applications > 0 && ['cancel', 'holiday'].includes(action) ? <p>{preview.totals.visits + preview.totals.visit_applications} пробни или еднократни посещения — родителите ще получат покана за друг ден.</p> : null}
            <p>Писмо до {preview.totals.letters} семейства{optionalNotify && !form.notify ? ' — само поканите за друг ден' : ''}.</p>
            {preview.totals.without_verified_email > 0 ? <p>{preview.totals.without_verified_email} семейства нямат потвърден имейл — обадете се.</p> : null}
            {preview.skipped.same_location > 0 ? <p>Вече са на това място: {preview.skipped.same_location}.</p> : null}
            {preview.skipped.started + preview.skipped.cancelled > 0 ? <p>Пропуснати: {preview.skipped.started} започнали · {preview.skipped.cancelled} отменени.</p> : null}
            {['cancel', 'holiday'].includes(action) ? <p>Таксите — по правилата в „Такси, отсъствия и отказ“.</p> : null}
            {action === 'move' && targets[0] ? <p>{backToOriginal ? 'Заниманието ще е в първоначалния си час, без знак за преместване.' : `В графика ще пише „${movedFromLabel({ moved_from: original })}“.`}</p> : null}
            {action === 'holiday' ? <details><summary>Виж списъка</summary><ul>{preview.sessions.map((session) => <li key={session.id}>{sessionWhen(session)} · {names.activity(session.activity_id)?.title} · {names.group(session.group_id)?.name} · {names.location(session.location_id) ?? 'Без място'} · {session.children} деца</li>)}</ul></details> : null}
          </div> : null}
          {notice ? <p className={styles.warning} role="alert">{notice}</p> : null}
          {action === 'cancel' ? <p className={styles.hint}>Отмененото остава в графика като „Отменено“ и може да се върне, докато не е започнало.</p> : null}
        </div>
        <footer className={styles.footer}><button type="button" disabled={saving} onClick={() => dialog.current?.close()}>Отказ</button>
          <button type="submit" className={['cancel', 'holiday'].includes(action) ? styles.danger : styles.primary} disabled={saving || !valid || !canApply(preview, confirmed)}>{saving ? 'Запазване…' : buttonLabel}</button></footer>
      </form>
    </dialog>
  );
}
