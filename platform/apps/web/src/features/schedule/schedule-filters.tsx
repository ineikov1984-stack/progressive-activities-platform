'use client';

import { type FocusEvent, type MouseEvent, useEffect, useId, useRef, useState } from 'react';
import { type FilterChip, type FilterKey, filterLabels, type FilterOption, type ScheduleFilters } from './presentation';
import { ChevronIcon, CloseIcon } from './icons';
import styles from './schedule.module.css';

type Props = {
  /** The filters shown: "Моят график" has no "Треньор" (т. 2). */
  keys: FilterKey[];
  options: Record<FilterKey, FilterOption[]>;
  filters: ScheduleFilters;
  chips: FilterChip[];
  onToggle: (key: FilterKey, id: string) => void;
  onClear: () => void;
};

/**
 * Дейност, група, треньор, място — several in each (т. 3.5). A button with a list for each when there is room; on a
 * phone "Филтри ▾" opens them in a panel. The chosen ones are labels with ✕, and "Изчисти филтрите".
 */
export function ScheduleFilterBar({ keys, options, filters, chips, onToggle, onClear }: Props) {
  const shown = keys.filter((key) => options[key].length > 0 || filters[key].length > 0);
  const [panelOpen, setPanelOpen] = useState(false);
  const opener = useRef<HTMLButtonElement>(null);
  const count = chips.length;

  function closePanel() {
    setPanelOpen(false);
    requestAnimationFrame(() => opener.current?.focus());
  }

  if (shown.length === 0 && count === 0) return null;
  return (
    <div className={styles.filters}>
      <div className={styles.filterBar}>
        {shown.map((key) => (
          <FilterMenu key={key} label={filterLabels[key]} options={options[key]} chosen={filters[key]} onToggle={(id) => onToggle(key, id)} />
        ))}
      </div>
      <button ref={opener} type="button" className={`${count ? styles.filterOn : styles.filter} ${styles.filtersButton}`} aria-haspopup="dialog" onClick={() => setPanelOpen(true)}>
        Филтри{count ? ` · ${count}` : ''}<ChevronIcon className={styles.chevron} />
      </button>
      {count > 0 ? (
        <ul className={styles.chips} aria-label="Избрани филтри">
          {chips.map((chip) => (
            <li key={`${chip.key}-${chip.id}`}>
              <button type="button" className={styles.chip} aria-label={`Премахни филтъра „${chip.label}“`} onClick={() => onToggle(chip.key, chip.id)}>
                <span>{chip.label}</span><CloseIcon size={16} />
              </button>
            </li>
          ))}
          <li><button type="button" className={styles.clear} onClick={onClear}>Изчисти филтрите</button></li>
        </ul>
      ) : null}
      {panelOpen ? <FiltersPanel keys={shown} options={options} filters={filters} count={count} onToggle={onToggle} onClear={onClear} onClose={closePanel} /> : null}
    </div>
  );
}

function Options({ legend, options, chosen, onToggle }: { legend: string; options: FilterOption[]; chosen: string[]; onToggle: (id: string) => void }) {
  return (
    <fieldset className={styles.options}>
      <legend className="sr-only">{legend}</legend>
      {options.map((option, index) => (
        <div key={option.id}>
          {option.section && option.section !== options[index - 1]?.section ? <p className={styles.optionsSection} aria-hidden="true">{option.section}</p> : null}
          <label className={styles.option}>
            <input type="checkbox" checked={chosen.includes(option.id)} onChange={() => onToggle(option.id)} />
            <span>{option.label}{option.section ? <span className="sr-only">, {option.section}</span> : null}</span>
          </label>
        </div>
      ))}
    </fieldset>
  );
}

/** One filter: the list opens under its button; Esc, a click outside or leaving it with Tab closes it. */
function FilterMenu({ label, options, chosen, onToggle }: { label: string; options: FilterOption[]; chosen: string[]; onToggle: (id: string) => void }) {
  const [open, setOpen] = useState(false);
  const root = useRef<HTMLDivElement>(null);
  const button = useRef<HTMLButtonElement>(null);
  const id = useId();

  useEffect(() => {
    if (!open) return;
    const away = (event: globalThis.MouseEvent) => { if (!root.current?.contains(event.target as Node)) setOpen(false); };
    const escape = (event: KeyboardEvent) => {
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
      <button ref={button} type="button" className={chosen.length ? styles.filterOn : styles.filter} aria-expanded={open} aria-controls={id} onClick={() => setOpen((value) => !value)}>
        {label}{chosen.length ? ` · ${chosen.length}` : ''}<ChevronIcon className={`${styles.chevron} ${open ? styles.chevronOpen : ''}`} />
      </button>
      {open ? (
        <div id={id} className={styles.menuPanel}>
          {options.length ? <Options legend={label} options={options} chosen={chosen} onToggle={onToggle} /> : <p className={styles.small}>Няма от какво да изберете.</p>}
        </div>
      ) : null}
    </div>
  );
}

/** On a phone: all the filters in a panel over the page. The choices apply at once; "Готово" closes it. */
function FiltersPanel({ keys, options, filters, count, onToggle, onClear, onClose }: {
  keys: FilterKey[]; options: Record<FilterKey, FilterOption[]>; filters: ScheduleFilters; count: number;
  onToggle: (key: FilterKey, id: string) => void; onClear: () => void; onClose: () => void;
}) {
  const dialog = useRef<HTMLDialogElement>(null);
  const heading = useRef<HTMLHeadingElement>(null);

  useEffect(() => {
    const element = dialog.current;
    if (element && !element.open) element.showModal();
    heading.current?.focus();
  }, []);

  function outside(event: MouseEvent<HTMLDialogElement>) {
    if (event.target === dialog.current) dialog.current?.close();
  }

  return (
    <dialog ref={dialog} className={styles.drawer} aria-labelledby="schedule-filters-title" onClose={onClose} onClick={outside}>
      <div className={styles.drawerInner}>
        <header className={styles.drawerHead}>
          <div className={styles.drawerTitle}>
            <h2 id="schedule-filters-title" ref={heading} tabIndex={-1}>Филтри</h2>
            <p className={styles.meta}>{count ? `Избрани: ${count}` : 'Изберете какво да се вижда в графика.'}</p>
          </div>
          <button type="button" className={styles.iconButton} aria-label="Затвори" onClick={() => dialog.current?.close()}><CloseIcon /></button>
        </header>
        <div className={styles.drawerBody}>
          {keys.map((key) => (
            <section key={key} className={styles.section} aria-labelledby={`schedule-filter-${key}`}>
              <h3 id={`schedule-filter-${key}`}>{filterLabels[key]}</h3>
              <Options legend={filterLabels[key]} options={options[key]} chosen={filters[key]} onToggle={(id) => onToggle(key, id)} />
            </section>
          ))}
          <div className={styles.drawerFoot}>
            <button type="button" className={styles.button} onClick={() => dialog.current?.close()}>Готово</button>
            {count ? <button type="button" className={styles.clear} onClick={onClear}>Изчисти филтрите</button> : null}
          </div>
        </div>
      </div>
    </dialog>
  );
}
