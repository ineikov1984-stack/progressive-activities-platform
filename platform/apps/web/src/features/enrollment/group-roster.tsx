'use client';

import { type FormEvent, useCallback, useEffect, useRef, useState } from 'react';
import { changeErrorMessage, endPause, type GroupRoster, loadRoster, reopenEnrollment, restoreSession, type RosterEnrollment } from './api';
import { ChangePanel } from './change-panel';
import { addDays, dateText, placesText, type RosterNote, rosterNotes, scopeLabels, shortDay, sofiaDay } from './presentation';
import styles from './enrollments.module.css';

type Props = { organizationId: string; groupId: string; canManage: boolean; onChanged?: () => void };
type Notice = { enrollmentId: string; text: string; token: number };

const noteStyle: Record<RosterNote['kind'], string> = {
  starts: styles.noteInfo,
  moves: styles.noteInfo,
  ends: styles.noteEnd,
  paused: styles.notePause,
  pause: styles.notePause,
  skips: styles.note,
};

/** Children in a group, what is special about each, and the team's changes (docs/product/staff-enrollment.md, т. 9.7). */
export function GroupRosterPanel({ organizationId, groupId, canManage, onChanged }: Props) {
  const [roster, setRoster] = useState<GroupRoster>();
  const [error, setError] = useState('');
  const [notice, setNotice] = useState<Notice>();
  const [openId, setOpenId] = useState<string>();
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    let active = true;
    loadRoster(organizationId, groupId)
      .then((value) => { if (active) { setRoster(value); setError(''); } })
      .catch((reason: unknown) => { if (active) setError(changeErrorMessage(reason, 'Списъкът не се зареди. Проверете връзката.')); });
    return () => { active = false; };
  }, [organizationId, groupId, reloadKey]);

  const reload = useCallback(() => setReloadKey((key) => key + 1), []);
  const done = useCallback((enrollmentId: string, text: string) => {
    setNotice({ enrollmentId, text, token: Date.now() });
    setOpenId(undefined);
    reload();
    onChanged?.();
  }, [reload, onChanged]);
  // After a refused change the form keeps its message; the reload brings the latest version for a retry.
  const failed = useCallback(() => { setNotice(undefined); reload(); }, [reload]);

  const retry = <button type="button" className={styles.secondary} onClick={reload}>Опитай отново</button>;
  if (error && !roster) return <div className={styles.roster}><p className={styles.error} role="alert">{error}</p>{retry}</div>;
  if (!roster) return <span className={styles.skeleton} aria-busy="true" />;
  const today = sofiaDay();
  const fullPeriodCount = roster.enrollments.filter((item) => item.scope === 'full_period').length;
  // A cancelled enrollment leaves the list; its message then shows above the list.
  const orphanNotice = notice && !roster.enrollments.some((item) => item.id === notice.enrollmentId) ? notice.text : '';

  return (
    <div className={styles.roster}>
      <p className={styles.places}>
        Заети места: <strong>{roster.group.enrolled_for_period}</strong>{roster.group.capacity_max ? ` от ${roster.group.capacity_max}` : ''} · {placesText(roster.group.remaining)}
        {roster.enrollments.length > fullPeriodCount ? ` · ${roster.enrollments.length - fullPeriodCount} с еднократно или пробно посещение` : ''}
      </p>
      <p className={orphanNotice ? styles.success : styles.srOnly} role="status">{orphanNotice}</p>
      {error ? <div className={styles.actions}><p className={styles.error} role="alert">{error}</p>{retry}</div> : null}
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
              notice={notice?.enrollmentId === item.id ? notice : undefined}
              onOpen={() => setOpenId(item.id)}
              onClose={() => setOpenId(undefined)}
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
  notice?: Notice;
  onOpen: () => void;
  onClose: () => void;
  onDone: (enrollmentId: string, message: string) => void;
  onFailed: () => void;
};

type Confirm = { kind: 'pause'; pauseId: string; startsOn: string; text: string } | { kind: 'reopen'; text: string };

function RosterRow({ organizationId, roster, enrollment, today, canManage, open, notice, onOpen, onClose, onDone, onFailed }: RowProps) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [resuming, setResuming] = useState<string>();
  const [confirm, setConfirm] = useState<Confirm>();
  const row = useRef<HTMLLIElement>(null);
  const noticeRef = useRef<HTMLParagraphElement>(null);
  const changeButton = useRef<HTMLButtonElement>(null);
  const name = enrollment.child.name ?? 'Детето';
  const notes = rosterNotes(enrollment, today);
  const fullPeriod = enrollment.scope === 'full_period';
  // A transfer is undone by moving back, so a child who moves has nothing more to change here.
  const changeable = canManage && fullPeriod && !enrollment.moves_to;

  // After a change the confirmation is in this row: bring it into view and give it focus (phones, screen readers).
  useEffect(() => {
    if (!notice) return;
    row.current?.scrollIntoView({ block: 'nearest' });
    noticeRef.current?.focus();
  }, [notice]);

  async function quick(action: () => Promise<unknown>, success: string) {
    setBusy(true); setError('');
    try {
      await action();
      setConfirm(undefined);
      setResuming(undefined);
      onDone(enrollment.id, success);
    } catch (reason) {
      setError(changeErrorMessage(reason, 'Промяната не беше запазена.'));
      onFailed();
    } finally { setBusy(false); }
  }

  function resume(event: FormEvent<HTMLFormElement>, pauseId: string) {
    event.preventDefault();
    const day = String(new FormData(event.currentTarget).get('resume_on') ?? '');
    if (!day || day < today) { setError('Изберете дата от днес нататък.'); return; }
    void quick(() => endPause(organizationId, enrollment, pauseId, day), `${name} се връща на ${shortDay(day)}.`);
  }

  function closePanel() {
    onClose();
    requestAnimationFrame(() => changeButton.current?.focus());
  }

  return (
    <li className={styles.row} ref={row}>
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
                    onClick={() => void quick(() => restoreSession(organizationId, enrollment, note.sessionId), `Заниманието отново се брои за ${name}.`)}>
                    Върни<span className={styles.srOnly}> заниманието за {name}</span>
                  </button>
                ) : null}
                {canManage && note.kind === 'pause' ? (
                  <button type="button" className={styles.linkButton} disabled={busy}
                    onClick={() => setConfirm({ kind: 'pause', pauseId: note.pauseId, startsOn: note.startsOn, text: `Паузата ${shortDay(note.startsOn)} – ${shortDay(note.endsOn)} ще бъде отменена и заниманията ще се броят.` })}>
                    Отмени паузата<span className={styles.srOnly}> за {name}</span>
                  </button>
                ) : null}
                {canManage && note.kind === 'paused' && note.endsOn > today && resuming !== note.pauseId ? (
                  <button type="button" className={styles.linkButton} disabled={busy} onClick={() => setResuming(note.pauseId)}>
                    Върни по-рано<span className={styles.srOnly}> {name}</span>
                  </button>
                ) : null}
                {canManage && note.kind === 'ends' ? (
                  <button type="button" className={styles.linkButton} disabled={busy}
                    onClick={() => setConfirm({ kind: 'reopen', text: `Прекратяването ще бъде отменено и ${name} продължава в групата.` })}>
                    Отмени прекратяването<span className={styles.srOnly}> за {name}</span>
                  </button>
                ) : null}
              </li>
            ))}
          </ul>
        ) : null}
        {confirm ? (
          <div className={styles.confirm} role="group" aria-label="Потвърждение">
            <p>{confirm.text}</p>
            <div className={styles.actions}>
              <button type="button" className={styles.primary} disabled={busy}
                onClick={() => void (confirm.kind === 'pause'
                  ? quick(() => endPause(organizationId, enrollment, confirm.pauseId, confirm.startsOn), `Паузата за ${name} е отменена.`)
                  : quick(() => reopenEnrollment(organizationId, enrollment), `Прекратяването за ${name} е отменено.`))}>
                {confirm.kind === 'pause' ? 'Отмени паузата' : 'Отмени прекратяването'}
              </button>
              <button type="button" className={styles.quiet} disabled={busy} onClick={() => setConfirm(undefined)}>Не</button>
            </div>
          </div>
        ) : null}
        {resuming ? (
          <form className={styles.actions} onSubmit={(event) => resume(event, resuming)} noValidate>
            <label className={styles.small} htmlFor={`resume-${enrollment.id}`}>Връща се на</label>
            <input id={`resume-${enrollment.id}`} className={styles.inlineDate} name="resume_on" type="date" min={today}
              max={enrollment.pauses?.find((pause) => pause.id === resuming)?.ends_on} defaultValue={addDays(today, 1)} />
            <button type="submit" className={styles.secondary} disabled={busy}>Върни</button>
            <button type="button" className={styles.quiet} disabled={busy} onClick={() => setResuming(undefined)}>Отказ</button>
          </form>
        ) : null}
        <p ref={noticeRef} tabIndex={-1} className={notice ? styles.rowNotice : styles.srOnly} role="status">{notice?.text ?? ''}</p>
        {error ? <p className={styles.error} role="alert">{error}</p> : null}
      </div>
      {changeable ? (
        <button ref={changeButton} type="button" className={styles.secondary} aria-expanded={open} onClick={open ? closePanel : onOpen}>
          {open ? 'Затвори' : 'Промени'}<span className={styles.srOnly}> за {name}</span>
        </button>
      ) : null}
      {open && changeable ? (
        <ChangePanel organizationId={organizationId} roster={roster} enrollment={enrollment}
          onDone={(message) => onDone(enrollment.id, message)} onFailed={onFailed} onClose={closePanel} />
      ) : null}
    </li>
  );
}
