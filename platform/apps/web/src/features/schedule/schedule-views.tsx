'use client';

import { type CSSProperties, Fragment, type ReactNode, useEffect, useRef, useState } from 'react';
import type { ScheduleActivity, ScheduleBoard, ScheduleSession } from './api';
import {
  activityColor, blockLines, cardDays, clock, type DayColumns, dayLayout, dayName, fillText, instructorsText, legend, markLabels, openingPoint,
  type ScheduleNames, sessionMark, startClock, timeRange, waitingText, type Week, type WeekBlock, weekLayout, sofiaMinutes,
} from './presentation';
import { FillBar, type PreviewHandlers } from './session-preview';
import styles from './schedule.module.css';

export type OpenSession = (session: ScheduleSession, opener: HTMLElement) => void;
export type OpenMore = (sessions: ScheduleSession[], opener: HTMLElement) => void;

type ViewProps = { week: Week; sessions: ScheduleSession[]; names: ScheduleNames; today: string; now: Date; onOpen: OpenSession };

export const vars = (values: Record<string, number>) => Object.fromEntries(Object.entries(values).map(([key, value]) => [`--${key}`, value])) as CSSProperties;
export const colorClass = (activity: ScheduleActivity | undefined) => styles[`cat_${activityColor(activity?.color)}`];

/** The colours of the activities shown, with their names (т. 3.6). */
export function Legend({ activities }: { activities: ScheduleActivity[] }) {
  if (activities.length < 2) return null;
  return (
    <ul className={styles.legend} aria-label="Цветове на дейностите">
      {activities.map((activity) => (
        <li key={activity.id}><span className={`${styles.swatch} ${colorClass(activity)}`} aria-hidden="true" />{activity.title}</li>
      ))}
    </ul>
  );
}

type GridColumn = { key: string; head: ReactNode; label: string; blocks: WeekBlock[]; today?: boolean };

/**
 * The hours on the left and a column for each day (Седмица) or each place or person (Ден). The grid scrolls inside,
 * with the heads on top and the hours on the left; it opens at today's column and the session going on now, else the
 * first one of the day (UX review). The page itself does not scroll sideways.
 */
function TimeGrid({ label, kind, columns, startHour, endHour, now, block, more }: {
  label: string; kind: 'week' | 'day'; columns: GridColumn[]; startHour: number; endHour: number; now: Date;
  block: (session: ScheduleSession, position: CSSProperties) => ReactNode;
  more: (sessions: ScheduleSession[], position: CSSProperties, start: number, column: GridColumn) => ReactNode;
}) {
  const rows = endHour - startHour;
  const offset = startHour * 60;
  const nowMinutes = sofiaMinutes(now);
  const scroller = useRef<HTMLDivElement>(null);
  // Where to open is decided once, when the grid comes; later minutes do not move what the person scrolled to.
  const [opening] = useState(() => openingPoint(kind, columns, now));

  useEffect(() => {
    const box = scroller.current;
    if (!box || !opening) return;
    const column = box.querySelectorAll<HTMLElement>('[data-column]')[opening.column];
    if (!column) return;
    const frame = box.getBoundingClientRect();
    const rect = column.getBoundingClientRect();
    const hour = rect.height / rows;
    const head = box.querySelector<HTMLElement>('[data-head]')?.offsetHeight ?? 0;
    const gutter = box.querySelector<HTMLElement>('[data-hours]')?.offsetWidth ?? 0;
    const top = rect.top - frame.top + box.scrollTop + ((opening.minute - startHour * 60) / 60) * hour - head - hour / 2;
    const outside = rect.left < frame.left + gutter || rect.right > frame.right;
    const left = outside ? rect.left - frame.left + box.scrollLeft - gutter : box.scrollLeft;
    box.scrollTo({ top: Math.max(0, top), left: Math.max(0, left) });
  }, [opening, rows, startHour]);

  return (
    <div ref={scroller} className={styles.weekScroll} role="region" aria-label={label} tabIndex={0}>
      <div className={`${styles.weekGrid} ${kind === 'day' ? styles.gridDay : ''}`} style={vars({ rows, columns: columns.length })}>
        <span className={styles.corner} aria-hidden="true" />
        {columns.map((column, index) => <div key={column.key} className={styles.dayHead} data-head={index === 0 ? '' : undefined} aria-hidden="true">{column.head}</div>)}
        <div className={styles.hours} data-hours="" aria-hidden="true">
          {Array.from({ length: rows }, (_, index) => <span key={index} style={vars({ at: index })}>{clock((startHour + index) * 60)}</span>)}
        </div>
        {columns.map((column) => (
          <div key={column.key} data-column="" role="group" aria-label={column.label} className={`${styles.column} ${column.today && kind === 'week' ? styles.columnToday : ''}`}>
            {column.blocks.length === 0 ? <span className="sr-only">Няма занимания</span> : null}
            {column.blocks.map((item) => {
              const position = vars({ top: item.start - offset, length: item.end - item.start, lane: item.lane, lanes: item.lanes });
              return item.kind === 'more'
                ? <Fragment key={`more-${item.start}`}>{more(item.sessions, position, item.start, column)}</Fragment>
                : <Fragment key={item.session.id}>{block(item.session, position)}</Fragment>;
            })}
            {column.today && nowMinutes >= offset && nowMinutes <= endHour * 60 ? <span className={styles.nowLine} style={vars({ top: nowMinutes - offset })} aria-hidden="true" /> : null}
          </div>
        ))}
      </div>
    </div>
  );
}

/**
 * The lines of a block that do not fit its height are hidden whole, never cut in the middle (UX review); the first
 * line — the time and the state — always stays. Checked again when the block changes size.
 */
function useFittedLines(text: string) {
  const ref = useRef<HTMLButtonElement>(null);
  useEffect(() => {
    const box = ref.current;
    if (!box) return;
    const fit = () => {
      const lines = Array.from(box.querySelectorAll<HTMLElement>('[data-line]'));
      for (const line of lines) line.hidden = false;
      const limit = box.clientHeight - parseFloat(getComputedStyle(box).paddingBottom || '0');
      let over = false;
      for (const line of lines) {
        over = over || line.offsetTop + line.offsetHeight > limit + 0.5;
        line.hidden = over;
      }
    };
    fit();
    if (typeof ResizeObserver === 'undefined') return;
    const observer = new ResizeObserver(fit);
    observer.observe(box);
    return () => observer.disconnect();
  }, [text]);
  return ref;
}

/**
 * A session in the week or the day: the time with "Сега" or "Отменено" on the first line, then the activity, how full
 * and the rest — the lines that fit whole — and the bar of how full at the bottom. On a computer, the quick look.
 */
function Block({ session, kind, by, names, now, position, onOpen, preview }: {
  session: ScheduleSession; kind: 'week' | 'day'; by: DayColumns; names: ScheduleNames; now: Date; position: CSSProperties; onOpen: OpenSession; preview: PreviewHandlers;
}) {
  const view = blockLines(session, kind, by, names, now);
  const target = { kind: 'session' as const, session };
  const ref = useFittedLines(`${view.mark}|${view.lines.join('|')}`);
  return (
    <button ref={ref} type="button" style={position} aria-haspopup="dialog" aria-describedby={preview.describedBy(session.id)}
      onClick={(event) => { preview.close(); onOpen(session, event.currentTarget); }}
      onMouseEnter={(event) => preview.enter(target, event.currentTarget)} onMouseLeave={preview.leave}
      onFocus={(event) => preview.focus(target, event)} onBlur={preview.blur}
      className={`${styles.event} ${colorClass(names.activity(session.activity_id))} ${view.mark === 'now' ? styles.isNow : ''} ${view.mark === 'cancelled' ? styles.isCancelled : ''}`}>
      <span className="sr-only">{view.label}</span>
      <span className={styles.lines} aria-hidden="true">
        <span className={styles.lineFirst}>
          <strong>{view.time}</strong>
          {view.mark ? <span className={`${styles.mark} ${styles[`mark_${view.mark}`]}`}>{markLabels[view.mark]}</span> : null}
        </span>
        {view.lines.map((line, index) => <span key={index} data-line="" className={index === 0 ? styles.lineMain : undefined}>{line}</span>)}
      </span>
      {view.mark === 'cancelled' ? null : <FillBar session={session} className={styles.blockBar} />}
    </button>
  );
}

/**
 * Седмица (т. 3.1): Monday to Sunday with the hours of the week's sessions. Up to two at the same time stand side by
 * side; with more, the first and "+N още" — the day by places, or for "Моят график" the rest in the panel.
 */
export function WeekView({ week, sessions, activities, names, today, now, onOpen, onMore, moreOpensDay, preview }: ViewProps & {
  activities: ScheduleActivity[]; onMore: (sessions: ScheduleSession[], opener: HTMLElement, day: string) => void; moreOpensDay: boolean; preview: PreviewHandlers;
}) {
  const layout = weekLayout(sessions, week.days);
  const columns = layout.days.map(({ day, blocks }) => {
    const name = dayName(day);
    const isToday = day === today;
    return {
      key: day, blocks, today: isToday, label: `${name.weekday}, ${name.date}${isToday ? ', днес' : ''}`,
      head: isToday ? <span className={styles.today}>{name.short} {name.number} · днес</span> : <>{name.short} <strong>{name.number}</strong></>,
    };
  });
  return (
    <section className={styles.week} aria-label="Седмица">
      <Legend activities={legend(sessions, activities)} />
      <TimeGrid label="Седмица по часове" kind="week" columns={columns} startHour={layout.startHour} endHour={layout.endHour} now={now}
        block={(session, position) => <Block session={session} kind="week" by="rooms" names={names} now={now} position={position} onOpen={onOpen} preview={preview} />}
        more={(hidden, position, start, column) => (
          <button type="button" className={styles.more} style={position} aria-haspopup={moreOpensDay ? undefined : 'dialog'}
            onClick={(event) => onMore(hidden, event.currentTarget, column.key)}>
            <span>+{hidden.length} още</span>
            <span className="sr-only"> занимания от {clock(start)}, {column.label}</span>
            {moreOpensDay ? <span className={styles.moreHint}>виж деня</span> : null}
          </button>
        )} />
    </section>
  );
}

/**
 * Ден (т. 3.3): a column for each place of the day ("Без зала" last), or for each person who leads ("Без
 * преподавател" last). A block shows the time from–to, the activity and group, how full, who leads (or the place and
 * "замества …", by people).
 */
export function DayView({ day, by, sessions, dictionary, names, today, now, onOpen, onMore, preview }: {
  day: string; by: DayColumns; sessions: ScheduleSession[]; dictionary: Pick<ScheduleBoard, 'activities' | 'locations' | 'instructors'>;
  names: ScheduleNames; today: string; now: Date; onOpen: OpenSession; onMore: OpenMore; preview: PreviewHandlers;
}) {
  const layout = dayLayout(sessions, day, by, dictionary);
  const name = dayName(day);
  const columns = layout.columns.map((column) => ({ key: column.id, head: column.title, label: column.title, blocks: column.blocks, today: day === today }));
  return (
    <section className={styles.week} aria-label={`${name.weekday}, ${name.date} — ${by === 'rooms' ? 'по зали' : 'по треньори'}`}>
      <Legend activities={legend(sessions, dictionary.activities)} />
      <TimeGrid label={`${name.weekday} по часове`} kind="day" columns={columns} startHour={layout.startHour} endHour={layout.endHour} now={now}
        block={(session, position) => <Block session={session} kind="day" by={by} names={names} now={now} position={position} onOpen={onOpen} preview={preview} />}
        more={(hidden, position, start) => (
          <button type="button" className={styles.more} style={position} aria-haspopup="dialog" onClick={(event) => onMore(hidden, event.currentTarget)}>
            +{hidden.length} още<span className="sr-only"> занимания от {clock(start)}</span>
          </button>
        )} />
    </section>
  );
}

/** A session as a card (т. 3.4): its state, time, activity and group, place and who leads, how full with the bar. */
export function SessionCard({ session, names, now, onOpen }: { session: ScheduleSession; names: ScheduleNames; now: Date; onOpen: OpenSession }) {
  const activity = names.activity(session.activity_id);
  const mark = sessionMark(session, now);
  const cancelled = mark === 'cancelled';
  const people = instructorsText(session.instructors);
  return (
    <button type="button" aria-haspopup="dialog" onClick={(event) => onOpen(session, event.currentTarget)}
      className={`${styles.card} ${colorClass(activity)} ${mark === 'now' ? styles.cardNow : ''} ${cancelled ? styles.cardCancelled : ''}`}>
      {mark ? <span className={`${styles.badge} ${styles[`mark_${mark}`]}`}>{markLabels[mark]}</span> : null}
      <strong>{startClock(session)} · {activity?.title ?? 'Занимание'} · {names.group(session.group_id)?.name ?? 'Група'}</strong>
      <span className="sr-only">{timeRange(session)},</span>
      <span className={styles.small}>{[names.location(session.location_id) ?? 'Без зала', people || 'Без преподавател'].join(' · ')}</span>
      {cancelled ? null : (
        <span className={styles.cardFill}>
          <span className={styles.small}>{[fillText(session, 'short'), waitingText(session.waiting)].filter(Boolean).join(' · ')}</span>
          <FillBar session={session} />
        </span>
      )}
    </button>
  );
}

/**
 * Карти (т. 3.4): the days with sessions, today marked; one column on a phone. When there is room, Monday to Friday
 * in a row, each in its column, Saturday and Sunday after them when they have sessions (UX review).
 */
export function CardsView({ week, sessions, names, today, now, onOpen }: ViewProps) {
  return (
    <div className={styles.cards}>
      {cardDays(sessions, week.days).map(({ day, sessions: daySessions }) => {
        const name = dayName(day);
        const isToday = day === today;
        return (
          <section key={day} className={`${styles.cardDay} ${daySessions.length ? '' : styles.cardDayEmpty}`} aria-labelledby={`schedule-day-${day}`}>
            <h2 id={`schedule-day-${day}`} className={`${styles.dayTitle} ${isToday ? styles.dayTitleToday : ''}`}>
              {name.weekday}{isToday ? ' · днес' : ''} <span>{name.date}</span>
            </h2>
            {daySessions.length ? (
              <ul className={styles.cardList}>
                {daySessions.map((session) => <li key={session.id}><SessionCard session={session} names={names} now={now} onOpen={onOpen} /></li>)}
              </ul>
            ) : <p className={styles.small}>Няма занимания</p>}
          </section>
        );
      })}
    </div>
  );
}

/** The loading state: the shape of the view, not an empty page. */
export function ScheduleSkeleton({ cards }: { cards: boolean }) {
  return (
    <div aria-hidden="true">
      {cards ? <div className={styles.skeletonCards}>{[0, 1, 2].map((item) => <span key={item} className={styles.skeleton} />)}</div>
        : <span className={`${styles.skeleton} ${styles.skeletonWeek}`} />}
    </div>
  );
}
