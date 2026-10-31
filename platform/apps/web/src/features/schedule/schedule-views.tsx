'use client';

import { type CSSProperties, Fragment, type ReactNode } from 'react';
import type { ScheduleActivity, ScheduleBoard, ScheduleSession } from './api';
import {
  activityColor, clock, dayBlockDetail, type DayColumns, dayLayout, dayName, instructorsText, legend, markLabels, placesText, type ScheduleNames,
  sessionMark, sessionsByDay, sofiaMinutes, startClock, timeRange, waitingText, type Week, type WeekBlock, weekLayout,
} from './presentation';
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

function Mark({ session, now }: { session: ScheduleSession; now: Date }) {
  const mark = sessionMark(session, now);
  if (mark !== 'now' && mark !== 'cancelled' && mark !== 'moved') return null;
  return <span className={`${styles.mark} ${styles[`mark_${mark}`]}`}>{markLabels[mark]}</span>;
}

type GridColumn = { key: string; head: ReactNode; label: string; blocks: WeekBlock[]; today?: boolean };

/**
 * The hours on the left and a column for each day (Седмица) or each place or person (Ден). On a narrow screen the
 * columns scroll inside, the hours stay; the page itself does not scroll sideways.
 */
function TimeGrid({ label, kind, columns, startHour, endHour, now, nowInColumn, block, more }: {
  label: string; kind: 'week' | 'day'; columns: GridColumn[]; startHour: number; endHour: number; now: Date;
  nowInColumn: (column: GridColumn) => boolean;
  block: (session: ScheduleSession, position: CSSProperties) => ReactNode;
  more: (sessions: ScheduleSession[], position: CSSProperties, start: number, column: GridColumn) => ReactNode;
}) {
  const rows = endHour - startHour;
  const offset = startHour * 60;
  const nowMinutes = sofiaMinutes(now);
  return (
    <div className={styles.weekScroll} role="region" aria-label={label} tabIndex={0}>
      <div className={`${styles.weekGrid} ${kind === 'day' ? styles.gridDay : ''}`} style={vars({ rows, columns: columns.length })}>
        <span className={styles.corner} aria-hidden="true" />
        {columns.map((column) => <div key={column.key} className={styles.dayHead} aria-hidden="true">{column.head}</div>)}
        <div className={styles.hours} aria-hidden="true">
          {Array.from({ length: rows }, (_, index) => <span key={index} style={vars({ at: index })}>{clock((startHour + index) * 60)}</span>)}
        </div>
        {columns.map((column) => (
          <div key={column.key} role="group" aria-label={column.label} className={`${styles.column} ${column.today ? styles.columnToday : ''}`}>
            {column.blocks.length === 0 ? <span className="sr-only">Няма занимания</span> : null}
            {column.blocks.map((item) => {
              const position = vars({ top: item.start - offset, length: item.end - item.start, lane: item.lane, lanes: item.lanes });
              return item.kind === 'more'
                ? <Fragment key={`more-${item.start}`}>{more(item.sessions, position, item.start, column)}</Fragment>
                : <Fragment key={item.session.id}>{block(item.session, position)}</Fragment>;
            })}
            {nowInColumn(column) && nowMinutes >= offset && nowMinutes <= endHour * 60 ? <span className={styles.nowLine} style={vars({ top: nowMinutes - offset })} aria-hidden="true" /> : null}
          </div>
        ))}
      </div>
    </div>
  );
}

function eventClass(session: ScheduleSession, names: ScheduleNames, now: Date): string {
  const mark = sessionMark(session, now);
  return `${styles.event} ${colorClass(names.activity(session.activity_id))} ${mark === 'now' ? styles.isNow : ''} ${mark === 'cancelled' ? styles.isCancelled : ''}`;
}

/**
 * Седмица (т. 3.1): Monday to Sunday with the hours of the week's sessions; a block shows the time, the activity, the
 * group and the place. Up to four at the same time stand side by side; with more, "+N още" — the day by places, or
 * for "Моят график" the rest in the panel.
 */
export function WeekView({ week, sessions, activities, names, today, now, onOpen, onMore, moreOpensDay }: ViewProps & {
  activities: ScheduleActivity[]; onMore: (sessions: ScheduleSession[], opener: HTMLElement, day: string) => void; moreOpensDay: boolean;
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
        nowInColumn={(column) => Boolean(column.today)}
        block={(session, position) => {
          const place = names.location(session.location_id);
          return (
            <button type="button" style={position} aria-haspopup="dialog" onClick={(event) => onOpen(session, event.currentTarget)} className={eventClass(session, names, now)}>
              <span className={styles.eventBody}>
                <strong><span className={styles.eventTime}>{startClock(session)} </span>{names.activity(session.activity_id)?.title ?? 'Занимание'}</strong>
                <span className="sr-only">до {clock(sofiaMinutes(session.ends_at))},</span>
                <span className={styles.eventGroup}>{names.group(session.group_id)?.name}</span>
                {place ? <span className={styles.eventPlace}>{place}</span> : null}
                <Mark session={session} now={now} />
              </span>
            </button>
          );
        }}
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
 * преподавател" last). A block shows the time from–to, the activity and group, who leads (or the place, by people)
 * and the places.
 */
export function DayView({ day, by, sessions, dictionary, names, today, now, onOpen, onMore }: {
  day: string; by: DayColumns; sessions: ScheduleSession[]; dictionary: Pick<ScheduleBoard, 'activities' | 'locations' | 'instructors'>;
  names: ScheduleNames; today: string; now: Date; onOpen: OpenSession; onMore: OpenMore;
}) {
  const layout = dayLayout(sessions, day, by, dictionary);
  const name = dayName(day);
  const columns = layout.columns.map((column) => ({ key: column.id, head: column.title, label: column.title, blocks: column.blocks, today: day === today }));
  return (
    <section className={styles.week} aria-label={`${name.weekday}, ${name.date} — ${by === 'rooms' ? 'по зали' : 'по треньори'}`}>
      <Legend activities={legend(sessions, dictionary.activities)} />
      <TimeGrid label={`${name.weekday} по часове`} kind="day" columns={columns} startHour={layout.startHour} endHour={layout.endHour} now={now}
        nowInColumn={(column) => Boolean(column.today)}
        block={(session, position) => {
          const group = names.group(session.group_id)?.name;
          return (
            <button type="button" style={position} aria-haspopup="dialog" onClick={(event) => onOpen(session, event.currentTarget)} className={eventClass(session, names, now)}>
              <span className={styles.eventBody}>
                <span className={styles.eventTime}><strong>{timeRange(session)}</strong></span>
                <strong>{names.activity(session.activity_id)?.title ?? 'Занимание'}{group ? ` · ${group}` : ''}</strong>
                <span className={styles.eventGroup}>{dayBlockDetail(session, by, names)}</span>
                {session.status === 'cancelled' ? null : <span className={styles.eventPlace}>{placesText(session, 'short')}</span>}
                <Mark session={session} now={now} />
              </span>
            </button>
          );
        }}
        more={(hidden, position, start) => (
          <button type="button" className={styles.more} style={position} aria-haspopup="dialog" onClick={(event) => onMore(hidden, event.currentTarget)}>
            +{hidden.length} още<span className="sr-only"> занимания от {clock(start)}</span>
          </button>
        )} />
    </section>
  );
}

/** A session as a card (т. 3.4): its state, time, activity and group, place and who leads, the places. */
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
      {cancelled ? null : <span className={styles.small}>{[placesText(session, 'short'), waitingText(session.waiting)].filter(Boolean).join(' · ')}</span>}
    </button>
  );
}

/** Карти (т. 3.4): the days with sessions, today marked; one column on a phone, the days side by side when there is room. */
export function CardsView({ week, sessions, names, today, now, onOpen }: ViewProps) {
  return (
    <div className={styles.cards}>
      {sessionsByDay(sessions, week.days).map(({ day, sessions: daySessions }) => {
        const name = dayName(day);
        const isToday = day === today;
        return (
          <section key={day} className={styles.cardDay} aria-labelledby={`schedule-day-${day}`}>
            <h2 id={`schedule-day-${day}`} className={`${styles.dayTitle} ${isToday ? styles.dayTitleToday : ''}`}>
              {name.weekday}{isToday ? ' · днес' : ''} <span>{name.date}</span>
            </h2>
            <ul className={styles.cardList}>
              {daySessions.map((session) => <li key={session.id}><SessionCard session={session} names={names} now={now} onOpen={onOpen} /></li>)}
            </ul>
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
