'use client';

import Link from 'next/link';
import { type MouseEvent, type RefObject, useEffect, useRef, useState } from 'react';
import { loadSessionPanel, type ScheduleSession, ScheduleApiError, type SessionPanel } from './api';
import {
  activityColor, CHILDREN_SHOWN, childLink, childMarks, dayName, groupTitle, instructorsText, markLabels, moreChildrenText, placesText,
  type ScheduleNames, sessionMark, sessionWhen, sofiaDay, startClock, waitingText,
} from './presentation';
import { SessionCard } from './schedule-views';
import { CloseIcon, EyeIcon, PreviousIcon } from './icons';
import styles from './schedule.module.css';

export type PanelTarget = { kind: 'session'; session: ScheduleSession } | { kind: 'more'; sessions: ScheduleSession[] };

type Props = {
  organizationId: string;
  target: PanelTarget;
  names: ScheduleNames;
  now: Date;
  /** "Подробности" of a child open from the eye for those who see the enrollments. */
  withDetails: boolean;
  onClose: () => void;
};

/**
 * The side panel (т. 4): a session with who leads, the places and the children; or, from "+N още", the sessions at
 * that time, each opening its own. A modal panel — the heading takes the focus, Esc and ✕ close it and the page gives
 * the focus back to where it was opened. No actions yet: "Премести", "Отмени" and "Присъствия" come later.
 */
export function SchedulePanel({ organizationId, target, names, now, withDetails, onClose }: Props) {
  const dialog = useRef<HTMLDialogElement>(null);
  const heading = useRef<HTMLHeadingElement>(null);
  const [chosen, setChosen] = useState<ScheduleSession | undefined>(target.kind === 'session' ? target.session : undefined);

  useEffect(() => {
    const element = dialog.current;
    if (element && !element.open) element.showModal();
  }, []);
  // The heading of what is shown takes the focus: on opening, and between the list and a session.
  useEffect(() => { heading.current?.focus(); }, [chosen]);

  function outside(event: MouseEvent<HTMLDialogElement>) {
    if (event.target === dialog.current) dialog.current?.close();
  }
  const close = () => dialog.current?.close();

  return (
    <dialog ref={dialog} className={styles.drawer} aria-labelledby="schedule-panel-title" onClose={onClose} onClick={outside}>
      <div className={styles.drawerInner}>
        {chosen ? (
          <SessionDetails key={chosen.id} organizationId={organizationId} session={chosen} names={names} now={now} withDetails={withDetails} heading={heading} onClose={close}
            onBack={target.kind === 'more' ? () => setChosen(undefined) : undefined} />
        ) : target.kind === 'more' ? (
          <MoreList sessions={target.sessions} names={names} now={now} heading={heading} onClose={close} onChoose={setChosen} />
        ) : null}
      </div>
    </dialog>
  );
}

function CloseButton({ onClose }: { onClose: () => void }) {
  return <button type="button" className={styles.iconButton} aria-label="Затвори" onClick={onClose}><CloseIcon /></button>;
}

/** "+N още" until "Ден по зали" (PR 3): the sessions at that time; each opens its panel. */
function MoreList({ sessions, names, now, heading, onClose, onChoose }: {
  sessions: ScheduleSession[]; names: ScheduleNames; now: Date; heading: RefObject<HTMLHeadingElement | null>; onClose: () => void; onChoose: (session: ScheduleSession) => void;
}) {
  const first = sessions[0];
  const day = first ? dayName(sofiaDay(first.starts_at)) : undefined;
  const from = first ? startClock(first) : '';
  return (
    <>
      <header className={styles.drawerHead}>
        <div className={styles.drawerTitle}>
          <p className={styles.eyebrow}>Още занимания</p>
          <h2 id="schedule-panel-title" ref={heading} tabIndex={-1}>{day ? `${day.weekday}, ${day.date}` : 'Занимания'}{from ? ` · от ${from}` : ''}</h2>
          <p className={styles.meta}>{sessions.length} занимания в този час</p>
        </div>
        <CloseButton onClose={onClose} />
      </header>
      <div className={styles.drawerBody}>
        <ul className={styles.cardList}>
          {sessions.map((session) => <li key={session.id}><SessionCard session={session} names={names} now={now} onOpen={(item) => onChoose(item)} /></li>)}
        </ul>
      </div>
    </>
  );
}

function SessionDetails({ organizationId, session, names, now, withDetails, heading, onClose, onBack }: {
  organizationId: string; session: ScheduleSession; names: ScheduleNames; now: Date; withDetails: boolean;
  heading: RefObject<HTMLHeadingElement | null>; onClose: () => void; onBack?: () => void;
}) {
  const [attempt, setAttempt] = useState(0);
  const key = `${session.id}:${attempt}`;
  const [result, setResult] = useState<{ key: string; panel?: SessionPanel; error?: string }>();

  useEffect(() => {
    let active = true;
    loadSessionPanel(organizationId, session.id)
      .then((panel) => { if (active) setResult({ key, panel }); })
      .catch((reason: unknown) => {
        if (active) setResult({ key, error: reason instanceof ScheduleApiError ? reason.message : 'Подробностите не се заредиха. Проверете връзката и опитайте пак.' });
      });
    return () => { active = false; };
  }, [organizationId, session.id, key]);

  const loaded = result?.key === key ? result : undefined;
  const panel = loaded?.panel;
  // The list's data until the panel's arrive; then the panel's, which are fresher.
  const current = panel?.session ?? session;
  const activity = names.activity(current.activity_id);
  const group = panel?.group ?? names.group(current.group_id);
  const place = panel ? panel.location?.name : names.location(current.location_id);
  const mark = sessionMark(current, now);
  const waiting = waitingText(current.waiting);
  const reason = panel?.session.change_reason;

  return (
    <>
      <header className={styles.drawerHead}>
        <div className={styles.drawerTitle}>
          {onBack ? <button type="button" className={styles.back} onClick={onBack}><PreviousIcon size={18} />Всички в този час</button> : null}
          <span className={`${styles.activityChip} ${styles[`cat_${activityColor(activity?.color)}`]}`}>{panel?.activity.title ?? activity?.title ?? 'Занимание'}</span>
          <h2 id="schedule-panel-title" ref={heading} tabIndex={-1}>{groupTitle(group)}</h2>
          <p className={styles.meta}>{sessionWhen(current)}</p>
          <p className={styles.meta}>{[place ?? 'Без зала', instructorsText(current.instructors) || 'Без преподавател'].join(' · ')}</p>
        </div>
        <CloseButton onClose={onClose} />
      </header>
      <div className={styles.drawerBody}>
        {mark ? <p className={styles.small}><span className={`${styles.badge} ${styles[`mark_${mark}`]}`}>{markLabels[mark]}</span></p> : null}
        {reason ? <p className={styles.reason}><strong>Причина:</strong> {reason}</p> : null}
        {mark === 'cancelled' ? null : (
          <ul className={styles.facts} aria-label="Места">
            <li>{placesText(current, 'long')}</li>
            {waiting ? <li>{waiting}</li> : null}
          </ul>
        )}
        {loaded?.error ? (
          <div className={styles.problem}>
            <p role="alert">{loaded.error}</p>
            <button type="button" className={styles.button} onClick={() => setAttempt((value) => value + 1)}>Опитай пак</button>
          </div>
        ) : !panel ? (
          <div className={styles.skeletonKids} aria-hidden="true">{[0, 1, 2, 3].map((item) => <span key={item} className={styles.skeleton} />)}</div>
        ) : panel.session.children ? (
          <ChildrenList kids={panel.session.children} groupId={current.group_id} withDetails={withDetails} cancelled={mark === 'cancelled'} />
        ) : null}
        {!panel && !loaded?.error ? <span className="sr-only" role="status">Зареждане на децата…</span> : null}
      </div>
    </>
  );
}

/** Name and class in alphabetical order (the API's), the first eight and "Още N деца"; the states in words. */
function ChildrenList({ kids, groupId, withDetails, cancelled }: { kids: NonNullable<SessionPanel['session']['children']>; groupId: string; withDetails: boolean; cancelled: boolean }) {
  const [all, setAll] = useState(false);
  const firstMore = useRef<HTMLAnchorElement>(null);
  useEffect(() => { if (all) firstMore.current?.focus(); }, [all]);
  const shown = all ? kids : kids.slice(0, CHILDREN_SHOWN);
  const rest = kids.length - shown.length;

  return (
    <section className={styles.section} aria-labelledby="schedule-panel-children">
      <h3 id="schedule-panel-children">{cancelled ? 'Децата, които щяха да дойдат' : 'Децата'} <span className={styles.small}>({kids.length})</span></h3>
      {kids.length === 0 ? <p className={styles.small}>Още няма записани деца.</p> : (
        <ul className={styles.kids}>
          {shown.map((child, index) => (
            <li key={child.enrollment_id} className={styles.kid}>
              <span className={styles.kidName}>
                <strong>{child.name}</strong>
                {child.class ? <span className={styles.small}>{child.class}</span> : null}
                {childMarks(child).map((mark) => (
                  <span key={mark} className={`${styles.tag} ${mark === 'пробно' || mark === 'еднократно' ? styles.tagQuiet : ''}`}>{mark}</span>
                ))}
              </span>
              <Link ref={index === CHILDREN_SHOWN ? firstMore : undefined} className={styles.eye} href={childLink(groupId, child.enrollment_id, withDetails)} aria-label={`Виж ${child.name}`}>
                <EyeIcon />
              </Link>
            </li>
          ))}
        </ul>
      )}
      {rest > 0 ? (
        <button type="button" className={styles.moreKids} onClick={() => setAll(true)}>{moreChildrenText(rest)} <span aria-hidden="true">→</span></button>
      ) : null}
    </section>
  );
}
