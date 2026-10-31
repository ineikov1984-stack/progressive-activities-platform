'use client';

import { type FocusEvent, type KeyboardEvent, useEffect, useId, useRef, useState } from 'react';
import { type FoundChild, ScheduleApiError, searchChildren } from './api';
import {
  CHILD_FILTER_LABEL, CHILD_SEARCH_DELAY_MS, CHILD_SEARCH_HINT, CHILD_SEARCH_MAX, childResultsText, childSearchText, moveActive,
} from './presentation';
import { ChevronIcon, CloseIcon } from './icons';
import styles from './schedule.module.css';

/** What the filter bar needs for "Дете" (docs/product/schedule.md, т. 3.5). */
export type ChildFilter = {
  organizationId: string;
  /** The chosen child's identifier — the address holds nothing else. */
  chosen: string | null;
  /** "Дете: Ана Иванова", or "Избрано дете" when the name is not in this page's memory. */
  chosenLabel: string;
  onChoose: (child: FoundChild) => void;
  onRemove: () => void;
};

type Search = { query: string | null; loading: boolean; children: FoundChild[]; error?: string; retry: () => void };

const searchFailed = 'Търсенето не стана. Проверете връзката и опитайте пак.';

/**
 * The enrolled children by name (`GET /enrollment/children`, т. 6.5): from two letters, about 250 ms after the last
 * one; a request still running is dropped when the text changes, so an older answer never shows.
 */
function useChildSearch(organizationId: string, text: string): Search {
  const query = childSearchText(text);
  const [attempt, setAttempt] = useState(0);
  const key = query === null ? null : `${organizationId}|${query}#${attempt}`;
  const [result, setResult] = useState<{ key: string; children?: FoundChild[]; error?: string }>();

  useEffect(() => {
    if (query === null || key === null) return;
    const controller = new AbortController();
    const timer = window.setTimeout(() => {
      searchChildren(organizationId, query, controller.signal)
        .then((children) => { if (!controller.signal.aborted) setResult({ key, children }); })
        .catch((reason: unknown) => {
          if (controller.signal.aborted) return;
          setResult({ key, error: reason instanceof ScheduleApiError ? reason.message : searchFailed });
        });
    }, CHILD_SEARCH_DELAY_MS);
    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [organizationId, query, key]);

  const current = key !== null && result?.key === key ? result : undefined;
  return { query, loading: key !== null && !current, children: current?.children ?? [], error: current?.error, retry: () => setAttempt((value) => value + 1) };
}

/**
 * The search for a child as an editable combobox with a list (ARIA APG, "list autocomplete" without automatic
 * selection): the focus stays in the field; ↓ and ↑ move through the names, Enter chooses, Esc closes the list and then
 * empties the field. Name and class only.
 */
function ChildSearch({ organizationId, autoFocus = false, onChoose }: { organizationId: string; autoFocus?: boolean; onChoose: (child: FoundChild) => void }) {
  const id = useId();
  const field = useRef<HTMLInputElement>(null);
  const list = useRef<HTMLUListElement>(null);
  const [text, setText] = useState('');
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(-1);
  const search = useChildSearch(organizationId, text);
  const options = search.children;
  const ready = search.query !== null && !search.loading && !search.error;
  const shown = open && ready && options.length > 0;
  const current = shown && active >= 0 && active < options.length ? active : -1;
  const activeId = current >= 0 ? `${id}-option-${current}` : undefined;

  useEffect(() => { if (autoFocus) field.current?.focus(); }, [autoFocus]);
  // In the filters panel on a phone the names come under the field: they scroll into sight.
  useEffect(() => { if (shown) list.current?.scrollIntoView({ block: 'nearest' }); }, [shown]);
  // The name the arrows reach stays in sight in a long list.
  useEffect(() => { if (activeId) document.getElementById(activeId)?.scrollIntoView({ block: 'nearest' }); }, [activeId]);

  function choose(child: FoundChild) {
    setText('');
    setOpen(false);
    setActive(-1);
    onChoose(child);
  }

  function keyDown(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      if (!ready || options.length === 0) return;
      setOpen(true);
      // Alt+↓ opens the list without choosing a name.
      setActive(event.altKey ? -1 : moveActive(shown ? current : -1, options.length, event.key));
      return;
    }
    if (event.key === 'Enter') {
      if (current >= 0) {
        event.preventDefault();
        choose(options[current]);
      }
      return;
    }
    if (event.key === 'Escape' && (shown || text)) {
      // The list first, then the text; only an empty field lets Esc close the menu or the panel around it.
      event.preventDefault();
      event.stopPropagation();
      if (shown) {
        setOpen(false);
        setActive(-1);
      } else {
        setText('');
      }
    }
  }

  let status: string;
  if (search.query === null) status = CHILD_SEARCH_HINT;
  else if (search.loading) status = 'Търсене…';
  else if (search.error) status = search.error;
  else status = childResultsText(options.length);

  return (
    <div className={styles.childSearch}>
      <label className={styles.childLabel} htmlFor={`${id}-field`}>Име на детето</label>
      <input
        ref={field} id={`${id}-field`} className={styles.childField} type="text" role="combobox" autoComplete="off" spellCheck={false}
        maxLength={CHILD_SEARCH_MAX} enterKeyHint="search" aria-autocomplete="list" aria-expanded={shown} aria-controls={`${id}-list`}
        aria-activedescendant={activeId} aria-describedby={`${id}-status`} value={text}
        onChange={(event) => { setText(event.target.value); setOpen(true); setActive(-1); }}
        onKeyDown={keyDown} onBlur={() => { setOpen(false); setActive(-1); }}
      />
      <ul ref={list} id={`${id}-list`} className={styles.childOptions} role="listbox" aria-label="Намерени деца" hidden={!shown}>
        {options.map((child, index) => (
          // The focus stays in the field (mousedown), so a click chooses the name without closing the menu first.
          <li
            key={child.id} id={`${id}-option-${index}`} className={styles.childOption} role="option" aria-selected={index === current}
            onMouseDown={(event) => event.preventDefault()} onClick={() => choose(child)}
          >
            <span className={styles.childName}>{child.name}</span>
            {child.class ? <span className={styles.small}>{child.class}</span> : null}
          </li>
        ))}
      </ul>
      <p id={`${id}-status`} className={search.error ? styles.childProblem : styles.small} role="status">{status}</p>
      {search.error ? <button type="button" className={styles.clear} onClick={search.retry}>Опитай пак</button> : null}
    </div>
  );
}

/** "Дете ▾" in the filter bar: the search opens under the button; a chosen name closes it and the focus goes back. */
export function ChildFilterMenu({ filter }: { filter: ChildFilter }) {
  const [open, setOpen] = useState(false);
  const root = useRef<HTMLDivElement>(null);
  const button = useRef<HTMLButtonElement>(null);
  const id = useId();

  useEffect(() => {
    if (!open) return;
    const away = (event: globalThis.MouseEvent) => { if (!root.current?.contains(event.target as Node)) setOpen(false); };
    const escape = (event: globalThis.KeyboardEvent) => {
      if (event.key !== 'Escape') return;
      setOpen(false);
      button.current?.focus();
    };
    document.addEventListener('mousedown', away);
    document.addEventListener('keydown', escape);
    return () => { document.removeEventListener('mousedown', away); document.removeEventListener('keydown', escape); };
  }, [open]);

  function leave(event: FocusEvent<HTMLDivElement>) {
    if (!root.current?.contains(event.relatedTarget as Node | null)) setOpen(false);
  }

  return (
    <div ref={root} className={styles.menu} onBlur={leave}>
      <button ref={button} type="button" className={filter.chosen ? styles.filterOn : styles.filter} aria-expanded={open} aria-controls={id} onClick={() => setOpen((value) => !value)}>
        {CHILD_FILTER_LABEL}{filter.chosen ? ' · 1' : ''}<ChevronIcon className={`${styles.chevron} ${open ? styles.chevronOpen : ''}`} />
      </button>
      {open ? (
        <div id={id} className={`${styles.menuPanel} ${styles.childMenu}`}>
          <ChildSearch organizationId={filter.organizationId} autoFocus onChoose={(child) => {
            filter.onChoose(child);
            setOpen(false);
            button.current?.focus();
          }} />
        </div>
      ) : null}
    </div>
  );
}

/** The same in the filters panel on a phone: the chosen child with ✕ above the search. */
export function ChildFilterSection({ filter }: { filter: ChildFilter }) {
  return (
    <section className={styles.section} aria-labelledby="schedule-filter-child">
      <h3 id="schedule-filter-child">{CHILD_FILTER_LABEL}</h3>
      {filter.chosen ? (
        <button type="button" className={`${styles.chip} ${styles.childChosen}`} aria-label={`Премахни филтъра „${filter.chosenLabel}“`} onClick={filter.onRemove}>
          <span>{filter.chosenLabel}</span><CloseIcon size={16} />
        </button>
      ) : null}
      <ChildSearch organizationId={filter.organizationId} onChoose={filter.onChoose} />
    </section>
  );
}
