'use client';

import { type FormEvent, type MouseEvent, useCallback, useEffect, useRef, useState } from 'react';
import {
  changeErrorMessage, type DetailsTarget, endPause, type GroupRoster, loadRoster, reopenEnrollment, restoreSession, reviewApplication,
  type RosterEnrollment, type StaffApplication,
} from './api';
import { ApprovalSetting } from './approval-setting';
import { ChangePanel } from './change-panel';
import { DetailsName } from './child-details';
import { addDays, dateText, needsGroupPlace, placesText, type RosterNote, rosterNotes, scopeLabels, shortDay, sofiaDay } from './presentation';
import styles from './enrollments.module.css';

type Props = { organizationId: string; groupId: string; canManage: boolean; onChanged?: () => void; onDetails?: (target: DetailsTarget, opener: HTMLElement) => void };
type Notice = { enrollmentId: string; text: string; token: number };
/** More children than this and the group gets a search by name. */
const SEARCH_FROM = 10;

const noteStyle: Record<RosterNote['kind'], string> = {
  starts: styles.noteInfo,
  moves: styles.noteInfo,
  ends: styles.noteEnd,
  paused: styles.notePause,
  pause: styles.notePause,
  skips: styles.note,
};

/** Children in a group, what is special about each, and the team's changes (docs/product/staff-enrollment.md, т. 9.7). */
export function GroupRosterPanel({ organizationId, groupId, canManage, onChanged, onDetails }: Props) {
  const [roster, setRoster] = useState<GroupRoster>();
  const [error, setError] = useState('');
  const [notice, setNotice] = useState<Notice>();
  const [openId, setOpenId] = useState<string>();
  const [reloadKey, setReloadKey] = useState(0);
  const [approving, setApproving] = useState('');
  const [search, setSearch] = useState('');
  const topNotice = useRef<HTMLParagraphElement>(null);

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

  // A message without a row (the enrollment was cancelled, or a waiting child was approved) is shown and focused above the list.
  const topText = notice && roster && !roster.enrollments.some((item) => item.id === notice.enrollmentId) ? notice.text : '';
  const topToken = topText ? notice?.token : undefined;
  useEffect(() => {
    if (topToken === undefined) return;
    topNotice.current?.scrollIntoView({ block: 'nearest' });
    topNotice.current?.focus();
  }, [topToken]);

  async function approve(application: StaffApplication) {
    setApproving(application.id); setError('');
    const name = application.child.name ?? 'Детето';
    try {
      const result = await reviewApplication(organizationId, application, 'approve');
      done(`waitlist-${application.id}`, result.status === 'approved'
        ? `${name} е записано в групата. Родителят ще види решението в профила си.`
        : `${name} остава в чакащите — все още няма свободно място.`);
    } catch (reason) {
      setError(changeErrorMessage(reason, 'Решението не беше запазено.'));
      reload();
    } finally { setApproving(''); }
  }

  const retry = <button type="button" className={styles.secondary} onClick={reload}>Опитай отново</button>;
  if (error && !roster) return <div className={styles.roster}><p className={styles.error} role="alert">{error}</p>{retry}</div>;
  if (!roster) return <span className={styles.skeleton} aria-busy="true" />;
  const today = sofiaDay();
  const fullPeriodCount = roster.enrollments.filter((item) => item.scope === 'full_period').length;
  const groupFull = roster.group.remaining !== null && roster.group.remaining <= 0;
  // Only a place for the whole period waits for the group; a trial or one-off visit has its own session.
  const blockedByGroup = (item: StaffApplication) => groupFull && needsGroupPlace(item);
  // A long group gets a search by name; the row with an open change or a fresh message always stays.
  const searchable = roster.enrollments.length > SEARCH_FROM;
  const needle = search.trim().toLocaleLowerCase('bg');
  const shown = !searchable || !needle ? roster.enrollments : roster.enrollments.filter((item) =>
    item.id === openId || item.id === notice?.enrollmentId || (item.child.name ?? '').toLocaleLowerCase('bg').includes(needle));

  return (
    <div className={styles.roster}>
      <p className={styles.places}>
        Заети места: <strong>{roster.group.enrolled_for_period}</strong>{roster.group.capacity_max ? ` от ${roster.group.capacity_max}` : ''} · {placesText(roster.group.remaining)}
        {roster.enrollments.length > fullPeriodCount ? ` · ${roster.enrollments.length - fullPeriodCount} с еднократно или пробно посещение` : ''}
      </p>
      <ApprovalSetting key={roster.group.enrollment_approval_mode} organizationId={organizationId} groupId={groupId} canManage={canManage}
        mode={roster.group.enrollment_approval_mode === 'automatic' ? 'automatic' : 'manual'} onSaved={() => onChanged?.()} />
      <p ref={topNotice} tabIndex={-1} className={topText ? styles.rowNotice : styles.srOnly}>{topText}</p>
      {error ? <div className={styles.actions}><p className={styles.error} role="alert">{error}</p>{retry}</div> : null}
      {searchable ? (
        <div className={styles.rosterSearch}>
          <label htmlFor={`roster-search-${groupId}`}>Търси дете в групата</label>
          <input id={`roster-search-${groupId}`} type="search" value={search} maxLength={100} onChange={(event) => setSearch(event.target.value)} />
          <span className={styles.small} role="status">{needle ? `Показани: ${shown.length} от ${roster.enrollments.length}` : ''}</span>
        </div>
      ) : null}
      <h5 className={styles.subTitle}>Записани деца ({roster.enrollments.length})</h5>
      {roster.enrollments.length === 0 ? <p className={styles.muted}>Все още няма записани деца.</p> : shown.length === 0 ? <p className={styles.muted}>Няма дете с това име в групата.</p> : (
        <ul className={styles.rows}>
          {shown.map((item) => (
            <RosterRow
              key={item.id}
              organizationId={organizationId}
              roster={roster}
              enrollment={item}
              today={today}
              canManage={canManage}
              open={openId === item.id}
              onActivity={() => setNotice(undefined)}
              notice={notice?.enrollmentId === item.id ? notice : undefined}
              onOpen={() => setOpenId(item.id)}
              onClose={() => setOpenId(undefined)}
              onDone={done}
              onFailed={failed}
              onDetails={onDetails ? (opener) => onDetails({ kind: 'enrollment', id: item.id }, opener) : undefined}
            />
          ))}
        </ul>
      )}
      {roster.waitlist.length > 0 ? (
        <>
          <h5 className={styles.subTitle}>Чакащи ({roster.waitlist.length})</h5>
          {canManage && roster.waitlist.some(blockedByGroup) ? <p id={`roster-full-${groupId}`} className={styles.smallWarning}>Няма свободно място — записване за периода ще е възможно, когато място се освободи.</p> : null}
          <ol className={styles.waitList}>
            {roster.waitlist.map((item) => (
              <li key={item.id}>
                <span className={styles.waitWho}>
                  {onDetails ? <span className={styles.listName}><DetailsName name={item.child.name ?? 'Дете'} onOpen={(opener) => onDetails({ kind: 'application', id: item.id }, opener)} /></span>
                    : <span className={styles.listName}>{item.child.name}</span>}
                  <span className={styles.small}>от {dateText(item.submitted_at)}{item.parent_name ? ` · родител: ${item.parent_name}` : ''}</span>
                </span>
                {canManage ? (
                  <span className={styles.actions}>
                    <button type="button" className={blockedByGroup(item) ? styles.secondary : styles.primary} disabled={approving !== '' || blockedByGroup(item)} aria-describedby={blockedByGroup(item) ? `roster-full-${groupId}` : undefined} onClick={() => void approve(item)}>
                      {approving === item.id ? 'Запазване…' : 'Одобри'}<span className={styles.srOnly}> {item.child.name}</span>
                    </button>
                  </span>
                ) : null}
              </li>
            ))}
          </ol>
        </>
      ) : null}
    </div>
  );
}

type RowProps = {
  onActivity: () => void;
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
  onDetails?: (opener: HTMLElement) => void;
};

type Confirm = { kind: 'pause'; pauseId: string; startsOn: string; text: string } | { kind: 'reopen'; text: string };

function RosterRow({ onActivity, organizationId, roster, enrollment, today, canManage, open, notice, onOpen, onClose, onDone, onFailed, onDetails }: RowProps) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [resuming, setResuming] = useState<string>();
  const [confirm, setConfirm] = useState<Confirm>();
  const row = useRef<HTMLLIElement>(null);
  const noticeRef = useRef<HTMLParagraphElement>(null);
  const confirmText = useRef<HTMLParagraphElement>(null);
  const resumeInput = useRef<HTMLInputElement>(null);
  const changeButton = useRef<HTMLButtonElement>(null);
  const opener = useRef<HTMLButtonElement | null>(null);
  const name = enrollment.child.name ?? 'Детето';
  const notes = rosterNotes(enrollment, today);
  const fullPeriod = enrollment.scope === 'full_period';
  // A transfer is undone by moving back, so a child who moves has nothing more to change here.
  const changeable = canManage && fullPeriod && !enrollment.moves_to;
  const todayAhead = (roster.upcoming_sessions ?? []).some((session) => sofiaDay(session.starts_at) === today);

  // After a change the confirmation is in this row: bring it into view and focus it (it is read once, on focus).
  useEffect(() => {
    if (!notice) return;
    row.current?.scrollIntoView({ block: 'nearest' });
    noticeRef.current?.focus();
  }, [notice]);
  useEffect(() => { if (confirm) confirmText.current?.focus(); }, [confirm]);
  useEffect(() => { if (resuming) resumeInput.current?.focus(); }, [resuming]);

  // Starting something new hides the previous confirmation, so two messages never stand side by side.
  function remember(event: MouseEvent<HTMLButtonElement>) { opener.current = event.currentTarget; onActivity(); }
  function back(close: () => void) {
    close();
    requestAnimationFrame(() => opener.current?.focus());
  }

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

  const resumingPause = enrollment.pauses?.find((pause) => pause.id === resuming);

  return (
    <li className={styles.row} ref={row}>
      <div className={styles.rowMain}>
        <span className={styles.listName}>{onDetails ? <DetailsName name={name} onOpen={onDetails} /> : name}</span>
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
                    onClick={() => { onActivity(); void quick(() => restoreSession(organizationId, enrollment, note.sessionId), `Заниманието отново се брои за ${name}.`); }}>
                    Отмени пропуска<span className={styles.srOnly}> за {name}</span>
                  </button>
                ) : null}
                {canManage && note.kind === 'pause' ? (
                  <button type="button" className={styles.linkButton} disabled={busy} aria-expanded={confirm?.kind === 'pause' && confirm.pauseId === note.pauseId}
                    onClick={(event) => { remember(event); setConfirm({ kind: 'pause', pauseId: note.pauseId, startsOn: note.startsOn, text: `Паузата ${shortDay(note.startsOn)} – ${shortDay(note.endsOn)} ще бъде отменена и заниманията ще се броят.` }); }}>
                    Отмени паузата<span className={styles.srOnly}> за {name}</span>
                  </button>
                ) : null}
                {canManage && note.kind === 'paused' && note.endsOn >= today ? (
                  <button type="button" className={styles.linkButton} disabled={busy} aria-expanded={resuming === note.pauseId}
                    onClick={(event) => { remember(event); setResuming(note.pauseId); }}>
                    Върни по-рано<span className={styles.srOnly}> {name}</span>
                  </button>
                ) : null}
                {canManage && note.kind === 'ends' ? (
                  <button type="button" className={styles.linkButton} disabled={busy} aria-expanded={confirm?.kind === 'reopen'}
                    onClick={(event) => { remember(event); setConfirm({ kind: 'reopen', text: `Прекратяването ще бъде отменено и ${name} продължава в групата.` }); }}>
                    Отмени прекратяването<span className={styles.srOnly}> за {name}</span>
                  </button>
                ) : null}
              </li>
            ))}
          </ul>
        ) : null}
        {confirm ? (
          <div className={styles.confirm}>
            <p ref={confirmText} tabIndex={-1}>{confirm.text}</p>
            <div className={styles.actions}>
              <button type="button" className={styles.primary} disabled={busy}
                onClick={() => void (confirm.kind === 'pause'
                  ? quick(() => endPause(organizationId, enrollment, confirm.pauseId, confirm.startsOn), `Паузата за ${name} е отменена.`)
                  : quick(() => reopenEnrollment(organizationId, enrollment), `Прекратяването за ${name} е отменено.`))}>
                {confirm.kind === 'pause' ? 'Отмени паузата' : 'Отмени прекратяването'}
              </button>
              <button type="button" className={styles.quiet} disabled={busy} onClick={() => back(() => setConfirm(undefined))}>Назад</button>
            </div>
          </div>
        ) : null}
        {resuming ? (
          <form className={styles.actions} onSubmit={(event) => resume(event, resuming)} noValidate>
            <label className={styles.small} htmlFor={`resume-${enrollment.id}`}>Връща се на</label>
            <input ref={resumeInput} id={`resume-${enrollment.id}`} className={styles.inlineDate} name="resume_on" type="date" min={today}
              max={resumingPause?.ends_on} defaultValue={resumingPause?.ends_on === today || todayAhead ? today : addDays(today, 1)} />
            <button type="submit" className={styles.secondary} disabled={busy}>Върни</button>
            <button type="button" className={styles.quiet} disabled={busy} onClick={() => back(() => setResuming(undefined))}>Отказ</button>
          </form>
        ) : null}
        <p ref={noticeRef} tabIndex={-1} className={notice ? styles.rowNotice : styles.srOnly}>{notice?.text ?? ''}</p>
        {error ? <p className={styles.error} role="alert">{error}</p> : null}
      </div>
      {changeable ? (
        <button ref={changeButton} type="button" className={styles.secondary} aria-expanded={open} onClick={open ? closePanel : () => { onActivity(); onOpen(); }}>
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
