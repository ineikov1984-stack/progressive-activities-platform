'use client';

import { type FormEvent, useCallback, useEffect, useState } from 'react';
import { endPause, type GroupRoster, loadRoster, reopenEnrollment, restoreSession, type RosterEnrollment } from './api';
import { ChangePanel } from './change-panel';
import { addDays, dateText, placesText, type RosterNote, rosterNotes, scopeLabels, shortDay, sofiaDay } from './presentation';
import styles from './enrollments.module.css';

type Props = { organizationId: string; groupId: string; canManage: boolean };

const noteStyle: Record<RosterNote['kind'], string> = {
  starts: styles.noteInfo,
  moves: styles.noteInfo,
  ends: styles.noteEnd,
  paused: styles.notePause,
  pause: styles.notePause,
  skips: styles.note,
};

/** Children in a group, what is special about each, and the team's changes (docs/product/staff-enrollment.md, т. 9.7). */
export function GroupRosterPanel({ organizationId, groupId, canManage }: Props) {
  const [roster, setRoster] = useState<GroupRoster>();
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [openId, setOpenId] = useState<string>();
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    let active = true;
    loadRoster(organizationId, groupId)
      .then((value) => { if (active) { setRoster(value); setError(''); } })
      .catch((reason: unknown) => { if (active) setError(reason instanceof Error ? reason.message : 'Списъкът не се зареди.'); });
    return () => { active = false; };
  }, [organizationId, groupId, reloadKey]);

  const reload = useCallback(() => setReloadKey((key) => key + 1), []);
  const done = useCallback((message: string) => { setNotice(message); setOpenId(undefined); reload(); }, [reload]);
  // After a refused change the row keeps its message; the reload brings the latest version for a retry.
  const failed = useCallback(() => { setNotice(''); reload(); }, [reload]);

  if (error && !roster) return <p className={styles.error} role="alert">{error}</p>;
  if (!roster) return <span className={styles.skeleton} aria-busy="true" />;
  const today = sofiaDay();
  const fullPeriodCount = roster.enrollments.filter((item) => item.scope === 'full_period').length;

  return (
    <div className={styles.roster}>
      <p className={styles.places}>
        Заети места: <strong>{roster.group.enrolled_for_period}</strong>{roster.group.capacity_max ? ` от ${roster.group.capacity_max}` : ''} · {placesText(roster.group.remaining)}
        {roster.enrollments.length > fullPeriodCount ? ` · ${roster.enrollments.length - fullPeriodCount} с еднократно или пробно посещение` : ''}
      </p>
      {notice ? <p className={styles.success} role="status">{notice}</p> : null}
      {roster.enrollments.length === 0 ? <p className={styles.muted}>Все още няма записани деца.</p> : (
        <ul className={styles.rows}>
          {roster.enrollments.map((item) => (
            <RosterRow
              key={item.id}
              organizationId={organizationId}
              roster={roster}
              enrollment={item}
              today={today}
              canManage={canManage}
              open={openId === item.id}
              onToggle={() => setOpenId(openId === item.id ? undefined : item.id)}
              onDone={done}
              onFailed={failed}
            />
          ))}
        </ul>
      )}
      {roster.waitlist.length > 0 ? (
        <>
          <h4 className={styles.subTitle}>Чакащи</h4>
          <ol className={styles.list}>
            {roster.waitlist.map((item) => <li key={item.id}><span className={styles.listName}>{item.child.name}</span><span className={styles.small}>от {dateText(item.submitted_at)}</span></li>)}
          </ol>
        </>
      ) : null}
    </div>
  );
}

type RowProps = {
  organizationId: string;
  roster: GroupRoster;
  enrollment: RosterEnrollment;
  today: string;
  canManage: boolean;
  open: boolean;
  onToggle: () => void;
  onDone: (message: string) => void;
  onFailed: () => void;
};

function RosterRow({ organizationId, roster, enrollment, today, canManage, open, onToggle, onDone, onFailed }: RowProps) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [resuming, setResuming] = useState<string>();
  const name = enrollment.child.name ?? 'Детето';
  const notes = rosterNotes(enrollment, today);
  const fullPeriod = enrollment.scope === 'full_period';
  // A transfer is undone by moving back, so a child who moves has nothing more to change here.
  const changeable = canManage && fullPeriod && !enrollment.moves_to;

  async function quick(action: () => Promise<unknown>, success: string) {
    setBusy(true); setError('');
    try {
      await action();
      onDone(success);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Промяната не беше запазена.');
      onFailed();
    } finally { setBusy(false); }
  }

  function resume(event: FormEvent<HTMLFormElement>, pauseId: string) {
    event.preventDefault();
    const day = String(new FormData(event.currentTarget).get('resume_on') ?? '');
    void quick(() => endPause(organizationId, enrollment, pauseId, day), `${name} се връща на ${shortDay(day)}.`);
  }

  return (
    <li className={styles.row}>
      <div className={styles.rowMain}>
        <span className={styles.listName}>{name}</span>
        <span className={styles.small}>
          {enrollment.child.class_label ? `${enrollment.child.class_label} · ` : ''}
          {fullPeriod ? `от ${dateText(enrollment.starts_on)}` : scopeLabels[enrollment.scope] ?? 'Записване'}
        </span>
        {notes.length > 0 ? (
          <ul className={styles.notes} aria-label={`Бележки за ${name}`}>
            {notes.map((note) => (
              <li key={`${note.kind}-${'pauseId' in note ? note.pauseId : 'sessionId' in note ? note.sessionId : note.text}`} className={`${styles.note} ${noteStyle[note.kind]}`}>
                <span>{note.text}</span>
                {canManage && note.kind === 'skips' ? (
                  <button type="button" className={styles.linkButton} disabled={busy}
                    onClick={() => void quick(() => restoreSession(organizationId, enrollment, note.sessionId), `Заниманието отново се брои за ${name}.`)}>Върни</button>
                ) : null}
                {canManage && note.kind === 'pause' ? (
                  <button type="button" className={styles.linkButton} disabled={busy}
                    onClick={() => void quick(() => endPause(organizationId, enrollment, note.pauseId, note.startsOn), `Паузата за ${name} е отменена.`)}>Отмени паузата</button>
                ) : null}
                {canManage && note.kind === 'paused' && resuming !== note.pauseId ? (
                  <button type="button" className={styles.linkButton} disabled={busy} onClick={() => setResuming(note.pauseId)}>Върни по-рано</button>
                ) : null}
                {canManage && note.kind === 'ends' ? (
                  <button type="button" className={styles.linkButton} disabled={busy}
                    onClick={() => void quick(() => reopenEnrollment(organizationId, enrollment), `Прекратяването за ${name} е отменено.`)}>Отмени прекратяването</button>
                ) : null}
              </li>
            ))}
          </ul>
        ) : null}
        {resuming ? (
          <form className={styles.actions} onSubmit={(event) => resume(event, resuming)}>
            <label className={styles.small} htmlFor={`resume-${enrollment.id}`}>Връща се на</label>
            <input id={`resume-${enrollment.id}`} className={styles.inlineDate} name="resume_on" type="date" required min={today}
              max={enrollment.pauses?.find((pause) => pause.id === resuming)?.ends_on} defaultValue={addDays(today, 1)} />
            <button type="submit" className={styles.secondary} disabled={busy}>Върни</button>
            <button type="button" className={styles.quiet} disabled={busy} onClick={() => setResuming(undefined)}>Отказ</button>
          </form>
        ) : null}
        {error ? <p className={styles.error} role="alert">{error}</p> : null}
      </div>
      {changeable ? (
        <button type="button" className={styles.secondary} aria-expanded={open} onClick={onToggle}>{open ? 'Затвори' : 'Промени'}</button>
      ) : null}
      {open && changeable ? (
        <ChangePanel organizationId={organizationId} roster={roster} enrollment={enrollment} onDone={onDone} onFailed={onFailed} onClose={onToggle} />
      ) : null}
    </li>
  );
}
