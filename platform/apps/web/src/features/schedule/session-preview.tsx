'use client';

import { type CSSProperties, type FocusEvent, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { ScheduleSession } from './api';
import {
  dayName, fillOf, fillText, groupTitle, instructorsText, markLabels, monthLine, type ScheduleNames, sessionMark, sessionWhen, waitingText,
} from './presentation';
import styles from './schedule.module.css';

/** The thin bar of how full the group is; the words are always next to it. Without a limit — no bar. */
export function FillBar({ session, className }: { session: Pick<ScheduleSession, 'capacity' | 'taken'>; className?: string }) {
  const fill = fillOf(session);
  if (fill.level === 'none') return null;
  return (
    <span className={`${styles.fillBar} ${styles[`fill_${fill.level}`]} ${className ?? ''}`} aria-hidden="true">
      <span style={{ '--fill': fill.percent } as CSSProperties} />
    </span>
  );
}

/** The state, "8 от 10 места" with the bar and who waits: at the top of the panel and in the preview. */
export function SessionFacts({ session, now }: { session: ScheduleSession; now: Date }) {
  const mark = sessionMark(session, now);
  const cancelled = mark === 'cancelled';
  const waiting = cancelled ? null : waitingText(session.waiting);
  return (
    <div className={styles.factsBox}>
      {mark ? <span className={`${styles.badge} ${styles[`mark_${mark}`]}`}>{markLabels[mark]}</span> : null}
      {cancelled ? null : (
        <span className={styles.fillFact}>
          <span>{fillText(session, 'long')}</span>
          <FillBar session={session} />
        </span>
      )}
      {waiting ? <span className={styles.small}>{waiting}</span> : null}
    </div>
  );
}

export type PreviewTarget = { kind: 'session'; session: ScheduleSession } | { kind: 'day'; day: string; sessions: ScheduleSession[] };
type Shown = { target: PreviewTarget; anchor: HTMLElement };

export type PreviewHandlers = {
  /** The pointer is on a block: the preview comes after a moment. */
  enter: (target: PreviewTarget, anchor: HTMLElement) => void;
  leave: () => void;
  /** Focus from the keyboard shows it too (WCAG 1.4.13). */
  focus: (target: PreviewTarget, event: FocusEvent<HTMLElement>) => void;
  blur: () => void;
  close: () => void;
  /** `aria-describedby` for the block whose preview is shown. */
  describedBy: (key: string) => string | undefined;
};

const PREVIEW_ID = 'schedule-preview';
const keyOf = (target: PreviewTarget) => (target.kind === 'session' ? target.session.id : `day-${target.day}`);

/**
 * The quick look on a computer (решение на собственика, 31.10.2026): after about 300 ms on a block, at once on focus
 * from the keyboard; Esc hides it; the pointer may move onto it; it does not cover the block. Not on touch screens —
 * there a tap opens the panel. Only the list's data, no request.
 */
export function usePreview(): { handlers: PreviewHandlers; shown: Shown | undefined; keep: () => void; hide: () => void } {
  const [canHover] = useState(() => typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia('(hover: hover)').matches);
  const [shown, setShown] = useState<Shown>();
  const timer = useRef<number | undefined>(undefined);
  const clear = useCallback(() => window.clearTimeout(timer.current), []);
  const hide = useCallback((delay = 250) => {
    window.clearTimeout(timer.current);
    timer.current = window.setTimeout(() => setShown(undefined), delay);
  }, []);
  const close = useCallback(() => {
    window.clearTimeout(timer.current);
    setShown(undefined);
  }, []);

  useEffect(() => {
    if (!shown) return;
    const escape = (event: KeyboardEvent) => { if (event.key === 'Escape') close(); };
    // The preview stands by its block; when the page or the grid moves, it goes.
    const scrolled = () => close();
    document.addEventListener('keydown', escape);
    window.addEventListener('scroll', scrolled, true);
    return () => { document.removeEventListener('keydown', escape); window.removeEventListener('scroll', scrolled, true); };
  }, [shown, close]);
  useEffect(() => () => window.clearTimeout(timer.current), []);

  const handlers = useMemo<PreviewHandlers>(() => ({
    enter: (target, anchor) => {
      if (!canHover) return;
      window.clearTimeout(timer.current);
      timer.current = window.setTimeout(() => setShown({ target, anchor }), 300);
    },
    leave: () => hide(),
    focus: (target, event) => {
      if (!canHover || !event.currentTarget.matches(':focus-visible')) return;
      window.clearTimeout(timer.current);
      setShown({ target, anchor: event.currentTarget });
    },
    blur: () => hide(0),
    close,
    describedBy: (key) => (shown && keyOf(shown.target) === key ? PREVIEW_ID : undefined),
  }), [canHover, hide, close, shown]);

  return { handlers, shown, keep: clear, hide: () => hide() };
}

/** The card itself, beside its block: to the right, else to the left, else under it; always inside the window. */
export function SchedulePreview({ shown, names, now, onEnter, onLeave }: { shown: Shown; names: ScheduleNames; now: Date; onEnter: () => void; onLeave: () => void }) {
  const card = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const element = card.current;
    if (!element) return;
    const gap = 8;
    const rect = shown.anchor.getBoundingClientRect();
    const width = element.offsetWidth;
    const height = element.offsetHeight;
    let left = rect.right + gap;
    let top = Math.min(Math.max(gap, rect.top), window.innerHeight - height - gap);
    if (left + width > window.innerWidth - gap) left = rect.left - gap - width;
    if (left < gap) {
      left = Math.min(Math.max(gap, rect.left), window.innerWidth - width - gap);
      top = rect.bottom + gap + height > window.innerHeight ? rect.top - gap - height : rect.bottom + gap;
    }
    element.style.left = `${Math.round(left)}px`;
    element.style.top = `${Math.round(Math.max(gap, top))}px`;
    element.style.visibility = 'visible';
  }, [shown]);

  const { target } = shown;
  return (
    <div ref={card} id={PREVIEW_ID} role="tooltip" className={styles.preview} onMouseEnter={onEnter} onMouseLeave={onLeave}>
      {target.kind === 'session' ? <SessionPreview session={target.session} names={names} now={now} /> : (
        <>
          <strong className={styles.previewTitle}>{dayName(target.day).weekday}, {dayName(target.day).date}</strong>
          {target.sessions.length === 0 ? <span className={styles.small}>Няма занимания</span> : (
            <ul className={styles.previewList}>
              {target.sessions.map((session) => (
                <li key={session.id}>
                  <span>{session.status === 'cancelled' ? 'Отменено · ' : ''}{monthLine(session, names)}</span>
                  {session.status === 'cancelled' ? null : <span className={styles.small}>{fillText(session, 'short')}</span>}
                </li>
              ))}
            </ul>
          )}
        </>
      )}
    </div>
  );
}

function SessionPreview({ session, names, now }: { session: ScheduleSession; names: ScheduleNames; now: Date }) {
  const activity = names.activity(session.activity_id)?.title ?? 'Занимание';
  return (
    <>
      <strong className={styles.previewTitle}>{activity} · {groupTitle(names.group(session.group_id))}</strong>
      <span className={styles.meta}>{sessionWhen(session)}</span>
      <span className={styles.meta}>{names.location(session.location_id) ?? 'Без зала'}</span>
      <span className={styles.meta}>{instructorsText(session.instructors) || 'Без преподавател'}</span>
      <SessionFacts session={session} now={now} />
    </>
  );
}
