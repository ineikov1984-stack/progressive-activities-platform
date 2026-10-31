'use client';

import { useState } from 'react';
import { sofiaDay } from '@/features/enrollment/presentation';
import {
  activityTarget, areaLabels, areaSummary, endLimits, groupPlaces, groupTarget, longDay, type PickerGroup, type RightArea, type RightRow, scopeLabels,
} from './rights';
import type { AccessScope } from './api';
import styles from './team.module.css';

const scopeHints: Record<AccessScope, string> = {
  own: 'Където води, помага или замества.',
  groups: 'Групи или цели дейности, които изберете.',
  all: 'Всички групи и дейности.',
};

/** "За кои групи": the scopes the role allows; one scope is said in words, without a choice. */
export function ScopeField({ idPrefix, allowed, value, onChange, lock, error }: {
  idPrefix: string; allowed: AccessScope[]; value: AccessScope; onChange: (scope: AccessScope) => void;
  /** Why a narrower scope cannot be chosen here (finance would be lost). */
  lock?: string | null; error?: string;
}) {
  if (allowed.length <= 1) {
    return <p className={styles.muted}>За кои групи: <strong className={styles.strong}>{scopeLabels[allowed[0] ?? 'all']}</strong> — така е за тази роля.</p>;
  }
  const hint = `${idPrefix}-scope-hint`;
  return (
    <fieldset className={styles.scopes} aria-describedby={error ? `${hint} ${idPrefix}-scope-error` : hint}>
      <legend>За кои групи</legend>
      {allowed.map((scope) => (
        <label className={styles.choice} key={scope}>
          <input type="radio" name={`${idPrefix}-scope`} value={scope} checked={value === scope} disabled={Boolean(lock) && scope !== 'all' && value === 'all'}
            onChange={() => onChange(scope)} />
          <span className={styles.choiceText}>{scopeLabels[scope]}<span>{scopeHints[scope]}</span></span>
        </label>
      ))}
      <p id={hint} className={styles.small}>{lock ?? 'Правата важат само тук. Групите, в които води или помага, са винаги включени.'}</p>
      {error ? <p id={`${idPrefix}-scope-error`} className={styles.fieldError}>{error}</p> : null}
    </fieldset>
  );
}

/**
 * "Промени групите": by activity — a group, or the whole activity with its future groups — and a quick choice by place
 * ("Всички в СУ „Христо Ботев“").
 */
export function GroupPicker({ idPrefix, groups, selected, onChange }: {
  idPrefix: string; groups: PickerGroup[]; selected: string[]; onChange: (targets: string[]) => void;
}) {
  const chosen = new Set(selected);
  const places = groupPlaces(groups);
  const activities = new Map<string, { title: string; groups: PickerGroup[] }>();
  for (const group of groups) {
    const id = group.activity_id ?? `none-${group.id}`;
    const entry = activities.get(id) ?? { title: group.activity_title ?? 'Без дейност', groups: [] };
    entry.groups.push(group);
    activities.set(id, entry);
  }
  const ordered = [...activities].sort(([, a], [, b]) => a.title.localeCompare(b.title, 'bg'));

  function toggle(key: string, on: boolean) {
    const next = new Set(chosen);
    if (on) next.add(key);
    else next.delete(key);
    onChange([...next]);
  }

  function addPlace(ids: string[]) {
    const next = new Set(chosen);
    for (const id of ids) next.add(groupTarget(id));
    onChange([...next]);
  }

  if (groups.length === 0) return <p className={styles.muted}>Още няма групи, от които да изберете.</p>;

  return (
    <div className={styles.picker}>
      {places.length > 0 && groups.length > 1 ? (
        <div className={styles.pickerPlaces} role="group" aria-label="Бърз избор по място">
          <p className={styles.small}>Бърз избор по място</p>
          <div className={styles.actions}>
            {places.map((item) => (
              <button key={item.place} type="button" className={styles.secondary} onClick={() => addPlace(item.ids)}>Всички в {item.place}</button>
            ))}
          </div>
        </div>
      ) : null}
      {ordered.map(([activityId, entry]) => {
        const whole = entry.groups[0]?.activity_id ? activityTarget(activityId) : null;
        const wholeOn = whole !== null && chosen.has(whole);
        return (
          <fieldset key={activityId} className={styles.pickerActivity}>
            <legend>{entry.title}</legend>
            {whole ? (
              <label className={styles.pickerItem}>
                <input type="checkbox" checked={wholeOn} onChange={(event) => toggle(whole, event.target.checked)} />
                <span>Цялата дейност<span className={styles.small}> — и бъдещите ѝ групи</span></span>
              </label>
            ) : null}
            {entry.groups.map((group) => (
              <label key={group.id} className={styles.pickerItem}>
                <input type="checkbox" id={`${idPrefix}-group-${group.id}`} checked={wholeOn || chosen.has(groupTarget(group.id))} disabled={wholeOn}
                  onChange={(event) => toggle(groupTarget(group.id), event.target.checked)} />
                <span>{group.name}{group.location_name ? <span className={styles.small}> · {group.location_name}</span> : null}</span>
              </label>
            ))}
          </fieldset>
        );
      })}
    </div>
  );
}

/** ✓ "от ролята" / "добавено" / "само с роля": the words, not the colour, say where a right comes from. */
export function RightsLegend() {
  return (
    <p className={styles.legend}>
      <span className={`${styles.rightTag} ${styles.rightTag_role}`}>✓ от ролята</span>
      <span className={`${styles.rightTag} ${styles.rightTag_added}`}>добавено</span>
      <span className={`${styles.rightTag} ${styles.rightTag_locked}`}>само с роля</span>
    </p>
  );
}

function RightItem({ idPrefix, row, today, error, onToggle, onEnd }: {
  idPrefix: string; row: RightRow; today: string; error?: string;
  onToggle: (key: string, on: boolean) => void; onEnd: (key: string, lastDay: string | null) => void;
}) {
  const id = `${idPrefix}-${row.key.replace('.', '-')}`;
  const limits = endLimits(today);
  const described = [row.tag ? `${id}-tag` : '', `${id}-hint`, error ? `${id}-error` : ''].filter(Boolean).join(' ');
  return (
    <li className={styles.right}>
      <input id={id} type="checkbox" className={styles.rightBox} checked={row.checked} disabled={!row.editable}
        aria-describedby={described} onChange={(event) => onToggle(row.key, event.target.checked)} />
      <label htmlFor={id} className={styles.rightName}>{row.label}</label>
      {row.tag ? <span id={`${id}-tag`} className={`${styles.rightTag} ${styles[`rightTag_${row.tone}`]}`}>{row.tag}</span> : null}
      <p id={`${id}-hint`} className={styles.rightHint}>{row.hint}{row.lock ? <strong className={styles.rightLock}> {row.lock}</strong> : null}</p>
      {row.added && row.editable ? (
        <div className={styles.rightEnd}>
          <label htmlFor={`${id}-end`}>До (по избор)</label>
          <input id={`${id}-end`} className={styles.input} type="date" min={limits.min} max={limits.max} value={row.lastDay ?? ''}
            aria-invalid={error ? true : undefined} onChange={(event) => onEnd(row.key, event.target.value || null)} />
        </div>
      ) : row.lastDay ? <p className={styles.rightHint}>до {longDay(row.lastDay)}</p> : null}
      {error ? <p id={`${id}-error`} className={styles.fieldError}>{error}</p> : null}
    </li>
  );
}

/** The rights of one area, each a checkbox with its sentence and label. */
export function RightItems({ idPrefix, rows, errors, onToggle, onEnd }: {
  idPrefix: string; rows: RightRow[]; errors: Record<string, string>;
  onToggle: (key: string, on: boolean) => void; onEnd: (key: string, lastDay: string | null) => void;
}) {
  const [today] = useState(() => sofiaDay());
  return (
    <ul className={styles.rights}>
      {rows.map((row) => <RightItem key={row.key} idPrefix={idPrefix} row={row} today={today} error={errors[row.key]} onToggle={onToggle} onEnd={onEnd} />)}
    </ul>
  );
}

/**
 * The areas fold and count what is in them ("1 добавено · 1 от ролята", "нищо добавено", "заключено"). On a wide
 * screen they start open, on a phone folded — except an area with an error.
 */
export function RightAreas({ idPrefix, areas, errors, startOpen, onToggle, onEnd }: {
  idPrefix: string; areas: { area: RightArea; rows: RightRow[] }[]; errors: Record<string, string>; startOpen: boolean;
  onToggle: (key: string, on: boolean) => void; onEnd: (key: string, lastDay: string | null) => void;
}) {
  return (
    <div className={styles.areas}>
      {areas.filter((item) => item.rows.length > 0).map(({ area, rows }) => {
        const summary = areaSummary(rows);
        const failed = rows.some((row) => errors[row.key]);
        return (
          <details key={area} className={styles.area} open={failed || (startOpen && summary !== 'заключено')}>
            <summary>
              <span className={styles.areaName}>{areaLabels[area]}</span>
              <span className={styles.areaCount}>{summary}</span>
            </summary>
            <RightItems idPrefix={`${idPrefix}-${area}`} rows={rows} errors={errors} onToggle={onToggle} onEnd={onEnd} />
          </details>
        );
      })}
    </div>
  );
}
