'use client';

import type { ScheduleActivity, ScheduleSession } from './api';
import { dayName, legend, type MonthDay, monthLayout, monthLine, type ScheduleNames, sessionsCount } from './presentation';
import { colorClass, Legend, type OpenSession, SessionCard } from './schedule-views';
import styles from './schedule.module.css';

type Props = {
  /** A day of the month shown; on a phone also the chosen day, whose sessions are listed under the grid. */
  day: string;
  sessions: ScheduleSession[];
  activities: ScheduleActivity[];
  names: ScheduleNames;
  today: string;
  now: Date;
  /** A day of the grid opens "Ден по зали" for it (т. 3.2). */
  onDay: (day: string) => void;
  /** On a phone a day is chosen first, and its sessions are listed under the grid. */
  onPick: (day: string) => void;
  onOpen: OpenSession;
};

const headings = ['пн', 'вт', 'ср', 'чт', 'пт', 'сб', 'нд'];

function dayWords(item: MonthDay, today: string): string {
  const name = dayName(item.day);
  return `${name.weekday}, ${name.date}${item.day === today ? ', днес' : ''}: ${sessionsCount(item.sessions.length)}.`;
}

/**
 * Месец (т. 3.2): six weeks from Monday. When there is room, a day shows up to three lines ("16:00 Шахмат · Гр. 1")
 * and "+N още", and opens "Ден по зали". On a phone the grid is compact — the number of sessions in each day — and
 * the chosen day's sessions are listed under it. The days of the next and the previous month are paler, still
 * readable.
 */
export function MonthView({ day, sessions, activities, names, today, now, onDay, onPick, onOpen }: Props) {
  const layout = monthLayout(sessions, day);
  const chosen = layout.weeks.flat().find((item) => item.day === day);
  const chosenName = dayName(day);
  const cellClass = (item: MonthDay) => [
    item.inMonth ? '' : styles.monthOut,
    item.day === today ? styles.monthToday : '',
  ].join(' ');

  return (
    <section className={styles.month} aria-label="Месец">
      <Legend activities={legend(sessions, activities)} />
      <table className={`${styles.monthTable} ${styles.monthWide}`}>
        <thead><tr>{headings.map((heading) => <th key={heading} scope="col">{heading}</th>)}</tr></thead>
        <tbody>
          {layout.weeks.map((week) => (
            <tr key={week[0].day}>
              {week.map((item) => (
                <td key={item.day} className={cellClass(item)}>
                  <button type="button" className={styles.monthDay} onClick={() => onDay(item.day)}>
                    <span className={styles.monthNumber} aria-hidden="true">{item.number}</span>
                    <span className="sr-only">{dayWords(item, today)}</span>
                    {item.shown.map((session) => (
                      <span key={session.id} className={`${styles.monthLine} ${colorClass(names.activity(session.activity_id))} ${session.status === 'cancelled' ? styles.monthCancelled : ''}`}>
                        {monthLine(session, names)}{session.status === 'cancelled' ? <span className="sr-only"> (отменено)</span> : null}
                      </span>
                    ))}
                    {item.more ? <span className={styles.monthMore}>+{item.more} още</span> : null}
                    <span className="sr-only"> Виж деня.</span>
                  </button>
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>

      <div className={styles.monthCompact}>
        <table className={styles.monthTable}>
          <thead><tr>{headings.map((heading) => <th key={heading} scope="col">{heading}</th>)}</tr></thead>
          <tbody>
            {layout.weeks.map((week) => (
              <tr key={week[0].day}>
                {week.map((item) => (
                  <td key={item.day} className={cellClass(item)}>
                    <button type="button" className={styles.monthPick} aria-pressed={item.day === day} onClick={() => onPick(item.day)}>
                      <span className={styles.monthNumber} aria-hidden="true">{item.number}</span>
                      {item.sessions.length ? <span className={styles.monthCount} aria-hidden="true">{item.sessions.length}</span> : null}
                      <span className="sr-only">{dayWords(item, today)}</span>
                    </button>
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
        <section className={styles.cardDay} aria-labelledby="schedule-month-day">
          <h2 id="schedule-month-day" className={`${styles.dayTitle} ${day === today ? styles.dayTitleToday : ''}`}>
            {chosenName.weekday}{day === today ? ' · днес' : ''} <span>{chosenName.date}</span>
          </h2>
          {chosen && chosen.sessions.length ? (
            <>
              <ul className={styles.cardList}>
                {chosen.sessions.map((session) => <li key={session.id}><SessionCard session={session} names={names} now={now} onOpen={onOpen} /></li>)}
              </ul>
              <button type="button" className={styles.button} onClick={() => onDay(day)}>Виж деня по часове</button>
            </>
          ) : <p className={styles.small}>Няма занимания в този ден.</p>}
        </section>
      </div>
    </section>
  );
}
