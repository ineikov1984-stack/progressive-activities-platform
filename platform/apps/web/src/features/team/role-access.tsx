'use client';

import { useId } from 'react';
import { accessHeadings, roleAccess } from './presentation';
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
 * What a role sees, does not see and cannot do (DECISIONS 31.10.2026 „Екип — полиран списък и профил“): "Вижда" for
 * the role in force, "Ще вижда" for the role an invitation or a change will give. The words carry the meaning; the
 * marks only add to them.
 */
export function RoleAccessList({ role, when }: { role: string; when: 'now' | 'later' }) {
  const access = roleAccess(role);
  const titles = accessHeadings(when);
  return (
    <div className={styles.access}>
      <Items title={titles.sees} items={access.sees} kind="yes" />
      {access.hidden.length > 0 ? <Items title={titles.hidden} items={access.hidden} kind="no" /> : null}
      {access.cannot.length > 0 ? <Items title={titles.cannot} items={access.cannot} kind="no" /> : null}
    </div>
  );
}
