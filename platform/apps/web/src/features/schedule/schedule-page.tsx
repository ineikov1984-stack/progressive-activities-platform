'use client';

import { useRouter, useSearchParams } from 'next/navigation';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useDashboardContext } from '../dashboard/dashboard-context';
import { ownScheduleOnly } from '../dashboard/navigation';
import { loadChildCard, loadSchedule, ScheduleApiError, type ScheduleBoard, type ScheduleSession } from './api';
import {
  childChipLabel, childFilterAllowed, chooseChild, dayColumnChoices, defaultView, emptyText, type FilterKey, filterChips, filterKeys, filterOptions, hasFilters,
  noFilters, parseScheduleAddress, parseStoredView, periodOf, type ScheduleAddress, scheduleAddress, scheduleNames, scheduleQuery, type ScheduleView,
  scheduleViewStorageKey, sofiaDay, stepDay, stepsOf, toggleFilter, viewsFor,
} from './presentation';
import type { ChildFilter } from './child-filter';
import { ScheduleFilterBar } from './schedule-filters';
import { MonthView } from './month-view';
import { CardsView, DayView, ScheduleSkeleton, WeekView } from './schedule-views';
import { SchedulePreview, usePreview } from './session-preview';
import { type PanelTarget, SchedulePanel } from './session-panel';
import { AssignmentDialog } from '../substitute/assignment-dialog';
import { ScheduleChangeDialog } from './change-dialog';
import { changeSuccess, selectSessions, selectable, type ChangeAction, type ChangeResult, type Selection } from './changes';
import { NextIcon, PreviousIcon } from './icons';
import styles from './schedule.module.css';

const emptyBoard: ScheduleBoard = { sessions: [], activities: [], groups: [], locations: [], instructors: [], abilities: {} };
/** "Моят график" has no filter by teacher and none by child (т. 2). */
const ownFilterKeys: FilterKey[] = ['activity', 'group', 'location'];

/** The view the person chose before, in this browser; without one — by the width of the screen. */
function rememberedView(userId: string, own: boolean): ScheduleView {
  if (typeof window === 'undefined') return 'week';
  let stored: string | null = null;
  try {
    stored = window.localStorage.getItem(scheduleViewStorageKey(userId));
  } catch {
    stored = null;
  }
  return parseStoredView(stored, own) ?? defaultView(window.innerWidth);
}

/**
 * "График" and, for a teacher, "Моят график" (docs/product/schedule.md, т. 2–4): the month, the week as a calendar,
 * the day by places or by people, and the cards ("Моят график" — the week and the cards), the filters and the panel of
 * a session. The view, the day, the columns of the day and the filters are in the address, so "Назад" works and a link
 * can be sent to a colleague; the hours are by Sofia.
 */
export function SchedulePage() {
  const { organization } = useDashboardContext();
  return <ScheduleWorkspace key={organization.id} />;
}

function ScheduleWorkspace() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const { user, organization } = useDashboardContext();
  const own = ownScheduleOnly(organization);
  const withDetails = organization.permissions.includes('enrollment.view');
  const childFilter = childFilterAllowed(own, organization);
  const search = searchParams.toString();
  const address = useMemo(() => parseScheduleAddress(search, { own, children: childFilter }), [search, own, childFilter]);
  // The names of the children chosen on this page, by identifier: the address holds only the identifier (т. 3.5).
  const [childNames, setChildNames] = useState<Record<string, string>>({});
  const rememberChild = useCallback((id: string, name: string) => {
    const key = id.toLowerCase();
    setChildNames((known) => (known[key] === name ? known : { ...known, [key]: name }));
  }, []);
  // A child from the address whose name this page does not know: the name from the child's card (т. 4.1), once.
  const asked = useRef(new Set<string>());
  const unnamedChild = childFilter && address.filters.child && !childNames[address.filters.child] ? address.filters.child : null;
  useEffect(() => {
    if (!unnamedChild || asked.current.has(unnamedChild)) return;
    asked.current.add(unnamedChild);
    loadChildCard(organization.id, unnamedChild).then((card) => rememberChild(unnamedChild, card.name)).catch(() => {
      // Not seen any more, or no connection: the label stays "Избрано дете".
    });
  }, [organization.id, unnamedChild, rememberChild]);

  // "Сега" and today follow the clock.
  const [now, setNow] = useState(() => new Date());
  useEffect(() => {
    const timer = window.setInterval(() => setNow(new Date()), 60_000);
    return () => window.clearInterval(timer);
  }, []);
  const today = sofiaDay(now);
  const [remembered, setRemembered] = useState<ScheduleView>(() => rememberedView(user.id, own));
  const view = address.view ?? remembered;
  const day = address.date ?? today;
  const period = useMemo(() => periodOf(view, day), [view, day]);
  const steps = stepsOf(view);
  const query = scheduleQuery(period, address.filters);

  const [attempt, setAttempt] = useState(0);
  const key = `${organization.id}?${query}#${attempt}`;
  const [result, setResult] = useState<{ key: string; board?: ScheduleBoard; error?: string }>();
  // The names and the filters' choices stay while the next week loads: they are the same week after week (т. 6.1).
  const [dictionary, setDictionary] = useState<ScheduleBoard>();
  useEffect(() => {
    let active = true;
    loadSchedule(organization.id, query).then((board) => {
      if (!active) return;
      setResult({ key, board });
      setDictionary(board);
    }).catch((reason: unknown) => {
      if (!active) return;
      if (reason instanceof ScheduleApiError && reason.status === 401) {
        router.replace('/login');
        return;
      }
      setResult({ key, error: reason instanceof ScheduleApiError ? reason.message : 'Графикът не се зареди. Проверете връзката и опитайте пак.' });
    });
    return () => { active = false; };
  }, [organization.id, query, key, router]);
  const current = result?.key === key ? result : undefined;
  // An attendance refresh keeps the same graph mounted, including scroll and the focused opener.
  const board = current?.board ?? (result?.key.startsWith(`${organization.id}?${query}#`) ? result.board : undefined);
  const names = useMemo(() => scheduleNames(dictionary ?? emptyBoard), [dictionary]);

  // The quick look on a computer; it goes when the view, the period or the filters change.
  const preview = usePreview();
  const closePreview = preview.handlers.close;
  const go = useCallback((next: Partial<ScheduleAddress>) => {
    closePreview();
    const target: ScheduleAddress = { view, date: day, by: address.by, filters: address.filters, ...next };
    // "По треньори" holds within the day; any way into the day from another view is "Ден по зали" (т. 3.2, 3.3).
    if (target.view !== 'day') target.by = 'rooms';
    window.history.pushState(null, '', `${window.location.pathname}${scheduleAddress(target)}`);
  }, [view, day, address.by, address.filters, closePreview]);

  function chooseView(next: ScheduleView) {
    setRemembered(next);
    try {
      window.localStorage.setItem(scheduleViewStorageKey(user.id), next);
    } catch {
      // A private window or blocked storage: the choice holds in the address for this visit.
    }
    go({ view: next });
  }

  // One panel at a time; closing it gives the focus back to the block or card that opened it.
  const [panel, setPanel] = useState<PanelTarget>();
  const opener = useRef<HTMLElement | null>(null);
  const openSession = useCallback((session: ScheduleSession, element: HTMLElement) => {
    opener.current = element;
    setPanel({ kind: 'session', session });
  }, []);
  const openMore = useCallback((sessions: ScheduleSession[], element: HTMLElement) => {
    opener.current = element;
    setPanel({ kind: 'more', sessions });
  }, []);
  const closePanel = useCallback(() => {
    setPanel(undefined);
    requestAnimationFrame(() => opener.current?.focus());
  }, []);
  // "+N още" in the week: the day by places (т. 3.1); "Моят график" has no day view, so the panel lists them.
  const weekMore = useCallback((sessions: ScheduleSession[], element: HTMLElement, moreDay: string) => {
    if (own) openMore(sessions, element);
    else go({ view: 'day', date: moreDay });
  }, [own, openMore, go]);

  const manage = Object.values(dictionary?.abilities ?? {}).some((ability) => ability.manage);
  const [selecting, setSelecting] = useState(false);
  const [selected, setSelected] = useState<Selection>({});
  const [announcement, setAnnouncement] = useState('');
  const [assigning, setAssigning] = useState(false);
  const [change, setChange] = useState<ChangeAction>();
  const changeOpener = useRef<HTMLElement | null>(null);
  const title = useRef<HTMLHeadingElement>(null);
  const closeChange = () => {
    setChange(undefined);
    requestAnimationFrame(() => (changeOpener.current?.isConnected ? changeOpener.current : title.current)?.focus());
  };
  const beginChange = (action: ChangeAction, element: HTMLElement) => { closePreview(); changeOpener.current = element; setChange(action); };
  function changed(result: ChangeResult) {
    setAnnouncement(changeSuccess(result)); setSelected({}); setSelecting(false); if (change) closeChange(); setAttempt((value) => value + 1);
  }
  function chooseSessions(sessions: ScheduleSession[], remove = false) {
    const allowed = sessions.filter((session) => dictionary?.abilities[session.group_id]?.manage && selectable(session, now));
    const next = selectSessions(selected, allowed, now, remove);
    setSelected(next.selected);
    setAnnouncement(next.limit ? 'Изберете най-много 300 занимания.' : '');
  }
  const selection = selecting && manage ? { selected, toggle: (session: ScheduleSession) => chooseSessions([session], !!selected[session.id]),
    day: (sessions: ScheduleSession[]) => { const eligible = sessions.filter((session) => selectable(session, now)); chooseSessions(eligible, eligible.length > 0 && eligible.every((session) => !!selected[session.id])); } } : undefined;
  useEffect(() => {
    if (!selecting) return;
    const escape = (event: KeyboardEvent) => { if (event.key === 'Escape' && !document.querySelector('dialog[open]')) { setSelecting(false); setSelected({}); } };
    document.addEventListener('keydown', escape);
    return () => document.removeEventListener('keydown', escape);
  }, [selecting]);

  const keys = own ? ownFilterKeys : filterKeys;
  const filtered = hasFilters(address.filters);
  const clearFilters = () => go({ filters: noFilters });
  const chosenChild = address.filters.child;
  const chosenChildName = chosenChild ? childNames[chosenChild] : undefined;
  const child: ChildFilter | undefined = childFilter ? {
    organizationId: organization.id,
    chosen: chosenChild,
    chosenLabel: childChipLabel(chosenChildName),
    onChoose: (found) => {
      rememberChild(found.id, found.name);
      go({ filters: chooseChild(address.filters, found.id.toLowerCase()) });
    },
    onRemove: () => go({ filters: chooseChild(address.filters, null) }),
  } : undefined;

  let body;
  if (current?.error) {
    body = (
      <div className={styles.problem}>
        <p role="alert">{current.error}</p>
        <button type="button" className={styles.button} onClick={() => setAttempt((value) => value + 1)}>Опитай пак</button>
      </div>
    );
  } else if (!board) {
    body = <><span className="sr-only" role="status">Зареждане на графика…</span><ScheduleSkeleton cards={view === 'cards'} /></>;
  } else if (board.sessions.length === 0) {
    body = (
      <div className={styles.empty}>
        <p role="status">{emptyText(view, filtered)}</p>
        {filtered ? <button type="button" className={styles.button} onClick={clearFilters}>Изчисти филтрите</button> : null}
      </div>
    );
  } else if (view === 'month') {
    body = <MonthView day={day} sessions={board.sessions} activities={board.activities} names={names} today={today} now={now} onOpen={openSession}
      onDay={(chosen) => go({ view: 'day', date: chosen })} onPick={(chosen) => go({ date: chosen })} preview={preview.handlers} />;
  } else if (view === 'day') {
    // A new day or columns open the grid anew at today's session (the key).
    body = <DayView key={`${query}|${address.by}`} day={day} by={address.by} sessions={board.sessions} dictionary={board} names={names} today={today} now={now}
      onOpen={openSession} onMore={openMore} preview={preview.handlers} selection={selection} />;
  } else if (view === 'week') {
    body = <WeekView key={query} week={period} sessions={board.sessions} activities={board.activities} names={names} today={today} now={now} onOpen={openSession}
      onMore={weekMore} moreOpensDay={!own} preview={preview.handlers} selection={selection} />;
  } else {
    body = <CardsView week={period} sessions={board.sessions} names={names} today={today} now={now} onOpen={openSession} selection={selection} />;
  }

  return (
    <div className={`${styles.page} ${selecting ? styles.selectingPage : ''}`}>
      <header className={styles.header}>
        <div className={styles.titleRow}>
          <h1 ref={title} tabIndex={-1}>{own ? 'Моят график' : 'График'}</h1>
          <div className={styles.period}>
            <button type="button" className={styles.button} onClick={() => go({ date: today })}>Днес</button>
            <button type="button" className={styles.iconButton} aria-label={steps.previous} onClick={() => go({ date: stepDay(view, day, -1) })}><PreviousIcon /></button>
            <button type="button" className={styles.iconButton} aria-label={steps.next} onClick={() => go({ date: stepDay(view, day, 1) })}><NextIcon /></button>
            <p className={styles.periodLabel} aria-live="polite">{period.label}</p>
          </div>
        </div>
        {manage ? <div className={styles.changeActions}>
          <button type="button" className={styles.button} onClick={(e) => beginChange('holiday', e.currentTarget)}>Почивен ден</button>
          <button type="button" className={styles.button} aria-pressed={selecting} onClick={() => { setSelecting(!selecting); if (selecting) setSelected({}); closePreview(); }}>Избери{selecting ? ' ✓' : ''}</button>
          <button type="button" className={styles.button} onClick={(e) => beginChange('add', e.currentTarget)}>+ Допълнително занимание</button>
        </div> : null}
        <div className={styles.switches}>
          {view === 'day' ? (
            <div className={styles.views} role="group" aria-label="Колони">
              {dayColumnChoices.map((item) => (
                <button key={item.id} type="button" aria-pressed={address.by === item.id} onClick={() => go({ by: item.id })}>{item.label}</button>
              ))}
            </div>
          ) : null}
          <div className={styles.views} role="group" aria-label="Изглед">
            {viewsFor(own).map((item) => (
              <button key={item.id} type="button" aria-pressed={view === item.id} onClick={() => chooseView(item.id)}>{item.label}</button>
            ))}
          </div>
        </div>
      </header>

      <ScheduleFilterBar keys={keys} options={filterOptions(dictionary ?? emptyBoard)} filters={address.filters}
        chips={dictionary ? filterChips(address.filters, dictionary, chosenChildName) : []} child={child}
        onToggle={(filter, id) => go({ filters: toggleFilter(address.filters, filter, id) })} onClear={clearFilters} />

      <p role="status" aria-live="polite">{announcement}</p>
      {selecting && manage ? <p className={styles.small}>Изберете заниманията, които искате да промените заедно. Може да изберете и цял ден от заглавието му.{view === 'month' ? ' За избор отворете Ден или Седмица.' : ''}</p> : null}
      {body}
      {selection && Object.keys(selected).length > 0 ? <section className={styles.selectionBar} role="region" aria-label="Избрани занимания">
        {announcement === 'Изберете занимания на един и същ преподавател.' ? <p role="alert">{announcement}</p> : null}
        <p><strong>Избрани {Object.keys(selected).length} занимания · {Object.values(selected).reduce((sum, session) => sum + session.taken, 0)} деца</strong> · <button type="button" onClick={() => setSelected({})}>Изчисти</button></p>
        <div><button type="button" onClick={(e) => beginChange('change_location', e.currentTarget)}>Смени залата</button>{Object.values(selected).every(s => dictionary?.abilities[s.group_id]?.substitute) ? <button type="button" onClick={e => { if(Object.keys(selected).length > 50) { setAnnouncement('Изберете най-много 50 занимания за заместник.'); return; } changeOpener.current = e.currentTarget; setAssigning(true); }}>Назначи заместник</button> : null}<button type="button" onClick={(e) => beginChange('cancel', e.currentTarget)}>Отмени избраните</button></div>
      </section> : null}

      {preview.shown && !panel && !selecting ? <SchedulePreview shown={preview.shown} names={names} now={now} onEnter={preview.keep} onLeave={preview.hide} /> : null}
      {panel ? <SchedulePanel organizationId={organization.id} target={panel} names={names} now={now} withDetails={withDetails} onClose={closePanel} onChildNamed={rememberChild} board={dictionary ?? emptyBoard} onChanged={changed} onAttendanceChanged={()=>setAttempt((n)=>n+1)} selection={selection} /> : null}
      {assigning ? <AssignmentDialog organizationId={organization.id} sessions={Object.values(selected)} board={dictionary ?? emptyBoard} onInvalid={setAnnouncement} onClose={() => { setAssigning(false); requestAnimationFrame(() => (changeOpener.current?.isConnected ? changeOpener.current : title.current)?.focus()); }} onApplied={result => { if(result) changed(result); }} onNotice={setAnnouncement} /> : null}
      {change && manage ? <ScheduleChangeDialog organizationId={organization.id} action={change} sessions={Object.values(selected)} board={dictionary ?? emptyBoard} day={day} onClose={closeChange} onApplied={changed} onSubstitute={Object.values(selected).length > 0 && Object.values(selected).every(s => dictionary?.abilities[s.group_id]?.substitute) ? () => { setChange(undefined); setAssigning(true); } : undefined} /> : null}
    </div>
  );
}
