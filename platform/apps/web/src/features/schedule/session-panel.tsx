'use client';

import { type MouseEvent, type RefObject, useEffect, useRef, useState } from 'react';
import { loadSessionPanel, type ScheduleSession, ScheduleApiError, type SessionChild, type SessionPanel } from './api';
import { ScheduleChangeDialog } from './change-dialog';
import { type ChangeAction, type ChangeResult } from './changes';
import type { ScheduleBoard } from './api';
import { ChildCardDialog } from './child-card';
import {
  activityColor, CHILDREN_SHOWN, childMarks, childrenHeading, dayName, groupTitle, instructorsText, moreChildrenText, type ScheduleNames,
  sessionMark, sessionWhen, sofiaDay, startClock,
} from './presentation';
import { SessionCard, type SessionSelection } from './schedule-views';
import { SessionFacts } from './session-preview';
import { CloseIcon, EyeIcon, PreviousIcon } from './icons';
import styles from './schedule.module.css';

export type PanelTarget = { kind: 'session'; session: ScheduleSession } | { kind: 'more'; sessions: ScheduleSession[] };

type Props = {
  organizationId: string;
  target: PanelTarget;
  names: ScheduleNames;
  now: Date;
  /** "Профил на детето →" in the card of a child for those who see the enrollments. */
  withDetails: boolean;
  onClose: () => void;
  board: ScheduleBoard;
  selection?: SessionSelection;
  onChanged: (result: ChangeResult) => void;
  /** A child's name from their card, for the label of the filter "Дете". */
  onChildNamed?: (id: string, name: string) => void;
};

/**
 * The side panel (т. 4): a session with who leads, the places and the children; or, from "+N още", the sessions at
 * that time, each opening its own. A modal panel — the heading takes the focus, Esc and ✕ close it and the page gives
 * the focus back to where it was opened. Change actions follow the fresh panel permissions.
 */
export function SchedulePanel({ organizationId, target, names, now, withDetails, onClose, onChildNamed, board, onChanged, selection }: Props) {
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
            board={board} onChanged={onChanged} onBack={target.kind === 'more' ? () => setChosen(undefined) : undefined} onChildNamed={onChildNamed} />
        ) : target.kind === 'more' ? (
          <MoreList sessions={target.sessions} names={names} now={now} heading={heading} onClose={close} onChoose={setChosen} selection={selection} />
        ) : null}
      </div>
    </dialog>
  );
}

function CloseButton({ onClose }: { onClose: () => void }) {
  return <button type="button" className={styles.iconButton} aria-label="Затвори" onClick={onClose}><CloseIcon /></button>;
}

/** "+N още" until "Ден по зали" (PR 3): the sessions at that time; each opens its panel. */
function MoreList({ sessions, names, now, heading, onClose, onChoose, selection }: {
  selection?: SessionSelection; sessions: ScheduleSession[]; names: ScheduleNames; now: Date; heading: RefObject<HTMLHeadingElement | null>; onClose: () => void; onChoose: (session: ScheduleSession) => void;
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
          {sessions.map((session) => <li key={session.id}><SessionCard session={session} names={names} now={now} onOpen={(item) => onChoose(item)} selection={selection} /></li>)}
        </ul>
      </div>
    </>
  );
}

type CardChild = SessionChild & { child_id: string };

function SessionDetails({ organizationId, session, names, now, withDetails, heading, onClose, onBack, onChildNamed, board, onChanged }: {
  organizationId: string; session: ScheduleSession; names: ScheduleNames; now: Date; withDetails: boolean;
  board: ScheduleBoard; onChanged: (result: ChangeResult) => void;
  heading: RefObject<HTMLHeadingElement | null>; onClose: () => void; onBack?: () => void; onChildNamed?: (id: string, name: string) => void;
}) {
  const [attempt, setAttempt] = useState(0);
  const [change, setChange] = useState<ChangeAction>();
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
  // Read again after a change in a child's card: the last answer stays until then, so the list does not jump.
  const panel = loaded?.panel ?? (loaded ? undefined : result?.panel);
  // The card of a child (т. 4.1), opened from the eye; closing it gives the focus back to the eye.
  const [card, setCard] = useState<CardChild>();
  const eye = useRef<HTMLButtonElement | null>(null);
  function openCard(child: CardChild, element: HTMLButtonElement) {
    eye.current = element;
    setCard(child);
  }
  function closeCard() {
    setCard(undefined);
    requestAnimationFrame(() => eye.current?.focus());
  }
  const cardChild = card ? { ...card, ...(panel?.session.children?.find((child) => child.enrollment_id === card.enrollment_id) ?? {}), child_id: card.child_id } : undefined;
  // The list's data until the panel's arrive; then the panel's, which are fresher.
  const current = panel?.session ?? session;
  const activity = names.activity(current.activity_id);
  const group = panel?.group ?? names.group(current.group_id);
  const place = panel ? panel.location?.name : names.location(current.location_id);
  const mark = sessionMark(current, now);
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
        {/* The same summary as the quick look on a computer: the state, how full with the bar, who waits. */}
        <SessionFacts session={current} now={now} />
        {panel?.last_change ? <p className={styles.reason}><strong>Причина за промяна:</strong> {panel.last_change.reason ?? reason ?? 'Промяна в графика'}{panel.last_change.by ? ` · ${panel.last_change.by}` : ''} · {new Date(panel.last_change.at).toLocaleString('bg-BG', { timeZone: 'Europe/Sofia' })}</p> : reason ? <p className={styles.reason}><strong>Причина:</strong> {reason}</p> : null}
        {panel?.abilities.manage ? <div className={styles.changeActions}>{panel.actions?.map((action) => <button key={action} type="button" className={styles.button} onClick={() => setChange(action)}>{action === 'move' ? 'Премести' : action === 'cancel' ? 'Отмени' : 'Възстанови'}</button>)}</div> : null}
        {loaded?.error ? (
          <div className={styles.problem}>
            <p role="alert">{loaded.error}</p>
            <button type="button" className={styles.button} onClick={() => setAttempt((value) => value + 1)}>Опитай пак</button>
          </div>
        ) : !panel ? (
          <div className={styles.skeletonKids} aria-hidden="true">{[0, 1, 2, 3].map((item) => <span key={item} className={styles.skeleton} />)}</div>
        ) : panel.session.children ? (
          <ChildrenList kids={panel.session.children} cancelled={mark === 'cancelled'} openId={card?.enrollment_id} onOpen={openCard} />
        ) : null}
        {!panel && !loaded?.error ? <span className="sr-only" role="status">Зареждане на децата…</span> : null}
      </div>
      {change && panel?.abilities.manage ? <ScheduleChangeDialog organizationId={organizationId} action={change} sessions={[current]} board={board} day={sofiaDay(current.starts_at)} onClose={() => setChange(undefined)} onApplied={(result) => { setChange(undefined); setAttempt((n) => n + 1); onChanged(result); }} /> : null}
      {cardChild ? (
        <ChildCardDialog organizationId={organizationId} child={cardChild} session={current} withDetails={withDetails} now={now} onClose={closeCard}
          onNamed={onChildNamed} onChanged={() => setAttempt((value) => value + 1)} />
      ) : null}
    </>
  );
}

/**
 * Name and class in alphabetical order (the API's), the first eight and "Още N деца"; the states in words. The eye
 * opens the card of the child (т. 4.1). A child without `child_id` — where the person only stands in for the day — has
 * no card, so no eye either.
 */
function ChildrenList({ kids, cancelled, openId, onOpen }: {
  kids: NonNullable<SessionPanel['session']['children']>; cancelled: boolean;
  openId?: string; onOpen: (child: CardChild, element: HTMLButtonElement) => void;
}) {
  const [all, setAll] = useState(false);
  // The focus goes to the first child shown now — to its eye, or to the row when it has none.
  const firstMore = useRef<HTMLElement | null>(null);
  useEffect(() => { if (all) firstMore.current?.focus(); }, [all]);
  const shown = all ? kids : kids.slice(0, CHILDREN_SHOWN);
  const rest = kids.length - shown.length;

  return (
    <section className={styles.section} aria-labelledby="schedule-panel-children">
      <h3 id="schedule-panel-children">{childrenHeading(kids, cancelled)}</h3>
      {kids.length === 0 ? <p className={styles.small}>Още няма записани деца.</p> : (
        <ul className={styles.kids}>
          {shown.map((child, index) => (
            <li key={child.enrollment_id} className={`${styles.kid} ${openId === child.enrollment_id ? styles.kidOpen : ''}`}
              ref={index === CHILDREN_SHOWN && !child.child_id ? (element: HTMLLIElement | null) => { firstMore.current = element; } : undefined}
              tabIndex={index === CHILDREN_SHOWN && !child.child_id ? -1 : undefined}>
              <span className={styles.kidName}>
                <strong>{child.name}</strong>
                {child.class ? <span className={styles.small}>{child.class}</span> : null}
                {childMarks(child).map((mark) => (
                  <span key={mark} className={`${styles.tag} ${mark === 'пробно' || mark === 'еднократно' ? styles.tagQuiet : ''}`}>{mark}</span>
                ))}
              </span>
              {child.child_id ? (
                <button ref={index === CHILDREN_SHOWN ? (element: HTMLButtonElement | null) => { firstMore.current = element; } : undefined} type="button" className={styles.eye}
                  aria-label={`Виж ${child.name}`} aria-haspopup="dialog" aria-expanded={openId === child.enrollment_id}
                  onClick={(event) => onOpen({ ...child, child_id: child.child_id ?? '' }, event.currentTarget)}>
                  <EyeIcon />
                </button>
              ) : null}
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
