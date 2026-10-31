'use client';

import type { CSSProperties } from 'react';
import type { ScheduleActivity, ScheduleSession } from './api';
import {
  activityColor, clock, dayName, instructorsText, legend, markLabels, placesText, type ScheduleNames, sessionMark, sessionsByDay, sofiaMinutes,
  startClock, timeRange, waitingText, type Week, weekLayout,
} from './presentation';
import styles from './schedule.module.css';

export type OpenSession = (session: ScheduleSession, opener: HTMLElement) => void;
export type OpenMore = (sessions: ScheduleSession[], opener: HTMLElement) => void;

type ViewProps = { week: Week; sessions: ScheduleSession[]; names: ScheduleNames; today: string; now: Date; onOpen: OpenSession };

const vars = (values: Record<string, number>) => Object.fromEntries(Object.entries(values).map(([key, value]) => [`--${key}`, value])) as CSSProperties;
const colorClass = (activity: ScheduleActivity | undefined) => styles[`cat_${activityColor(activity?.color)}`];

/** The colours of the activities shown, with their names (т. 3.6). */
function Legend({ activities }: { activities: ScheduleActivity[] }) {
  if (activities.length < 2) return null;
  return (
    <ul className={styles.legend} aria-label="Цветове на дейностите">
      {activities.map((activity) => (
        <li key={activity.id}><span className={`${styles.swatch} ${colorClass(activity)}`} aria-hidden="true" />{activity.title}</li>
      ))}
    </ul>
  );
}

/**
 * Седмица (т. 3.1): Monday to Sunday with the hours of the week's sessions; a block shows the time, the activity, the
 * group and the place. Up to four at the same time stand side by side; with more, "+N още" opens the rest.
 */
export function WeekView({ week, sessions, activities, names, today, now, onOpen, onMore }: ViewProps & { activities: ScheduleActivity[]; onMore: OpenMore }) {
  const layout = weekLayout(sessions, week.days);
  const rows = layout.endHour - layout.startHour;
  const offset = layout.startHour * 60;
  const nowMinutes = sofiaMinutes(now);

  return (
    <section className={styles.week} aria-label="Седмица">
      <Legend activities={legend(sessions, activities)} />
      <div className={styles.weekScroll}>
        <div className={styles.weekGrid} style={vars({ rows })}>
          <span aria-hidden="true" />
          {week.days.map((day) => {
            const name = dayName(day);
            return (
              <div key={day} className={styles.dayHead} aria-hidden="true">
                {day === today ? <span className={styles.today}>{name.short} {name.number} · днес</span> : <>{name.short} <strong>{name.number}</strong></>}
              </div>
            );
          })}
          <div className={styles.hours} aria-hidden="true">
            {Array.from({ length: rows }, (_, index) => <span key={index} style={vars({ at: index })}>{clock((layout.startHour + index) * 60)}</span>)}
          </div>
          {layout.days.map(({ day, blocks }) => {
            const name = dayName(day);
            const isToday = day === today;
            return (
              <div key={day} role="group" aria-label={`${name.weekday}, ${name.date}${isToday ? ', днес' : ''}`} className={`${styles.column} ${isToday ? styles.columnToday : ''}`}>
                {blocks.length === 0 ? <span className="sr-only">Няма занимания</span> : null}
                {blocks.map((block) => {
                  const position = vars({ top: block.start - offset, length: block.end - block.start, lane: block.lane, lanes: block.lanes });
                  if (block.kind === 'more') {
                    const count = block.sessions.length;
                    return (
                      <button key={`more-${block.start}`} type="button" className={styles.more} style={position} aria-haspopup="dialog"
                        aria-label={`Още ${count} занимания от ${clock(block.start)}`} onClick={(event) => onMore(block.sessions, event.currentTarget)}>
                        +{count} още
                      </button>
                    );
                  }
                  const { session } = block;
                  const activity = names.activity(session.activity_id);
                  const mark = sessionMark(session, now);
                  const place = names.location(session.location_id);
                  return (
                    <button key={session.id} type="button" style={position} aria-haspopup="dialog" onClick={(event) => onOpen(session, event.currentTarget)}
                      className={`${styles.event} ${colorClass(activity)} ${mark === 'now' ? styles.isNow : ''} ${mark === 'cancelled' ? styles.isCancelled : ''}`}>
                      <span className={styles.eventBody}>
                        <strong><span className={styles.eventTime}>{startClock(session)} </span>{activity?.title ?? 'Занимание'}</strong>
                        <span className="sr-only">до {clock(sofiaMinutes(session.ends_at))},</span>
                        <span className={styles.eventGroup}>{names.group(session.group_id)?.name}</span>
                        {place ? <span className={styles.eventPlace}>{place}</span> : null}
                        {mark === 'now' || mark === 'cancelled' || mark === 'moved' ? <span className={`${styles.mark} ${styles[`mark_${mark}`]}`}>{markLabels[mark]}</span> : null}
                      </span>
                    </button>
                  );
                })}
                {isToday && nowMinutes >= offset && nowMinutes <= layout.endHour * 60 ? <span className={styles.nowLine} style={vars({ top: nowMinutes - offset })} aria-hidden="true" /> : null}
              </div>
            );
          })}
        </div>
      </div>
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
export function ScheduleSkeleton({ view }: { view: 'week' | 'cards' }) {
  return (
    <div aria-hidden="true">
      {view === 'week' ? <span className={`${styles.skeleton} ${styles.skeletonWeek}`} /> : (
        <div className={styles.skeletonCards}>{[0, 1, 2].map((item) => <span key={item} className={styles.skeleton} />)}</div>
      )}
    </div>
  );
}
