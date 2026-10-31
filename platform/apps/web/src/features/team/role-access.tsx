'use client';

import { useId } from 'react';
import { accessHeadings, accessSummary, type AccessSummary, defaultScope } from './rights';
import styles from './team.module.css';

function Mark({ kind }: { kind: 'yes' | 'no' }) {
  return (
    <svg className={kind === 'yes' ? styles.markYes : styles.markNo} viewBox="0 0 20 20" width="20" height="20" aria-hidden="true" focusable="false">
      <circle cx="10" cy="10" r="10" />
      <path d={kind === 'yes' ? 'm6 10.2 2.6 2.6L14 7.4' : 'M7 7l6 6M13 7l-6 6'} fill="none" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

function Items({ title, items, kind }: { title: string; items: string[]; kind: 'yes' | 'no' }) {
  const id = useId();
  return (
    <>
      <p id={id} className={styles.accessTitle}>{title}</p>
      <ul className={styles.accessItems} aria-labelledby={id}>
        {items.map((item) => <li key={item}><Mark kind={kind} />{item}</li>)}
      </ul>
    </>
  );
}

/**
 * "Може да / Вижда / Не вижда" (docs/product/team-rights.md, т. 10): "Вижда" for the rights in force, "Ще вижда" for
 * the ones an invitation or a change will give. The words carry the meaning; the marks only add to them.
 */
export function AccessLists({ summary, when }: { summary: AccessSummary; when: 'now' | 'later' }) {
  const titles = accessHeadings(when);
  return (
    <div className={styles.access}>
      <Items title={titles.can} items={summary.can} kind="yes" />
      {summary.sees.length > 0 ? <Items title={titles.sees} items={summary.sees} kind="yes" /> : null}
      {summary.hidden.length > 0 ? <Items title={titles.hidden} items={summary.hidden} kind="no" /> : null}
    </div>
  );
}

/** What a role gives by itself, without additions. */
export function RoleAccessList({ role, when }: { role: string; when: 'now' | 'later' }) {
  return <AccessLists summary={accessSummary({ role, scope: defaultScope(role), grants: {} })} when={when} />;
}
