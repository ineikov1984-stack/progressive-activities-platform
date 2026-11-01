import type { ReactNode } from 'react';
import styles from './inline-help.module.css';

/** Optional explanation, available by touch and keyboard without hiding labels or validation. */
export function InlineHelp({ label, children }: { label: string; children: ReactNode }) {
  return <details className={styles.help}>
    <summary><span className={styles.icon} aria-hidden="true">i</span><span>{label}</span><span className={styles.sign} aria-hidden="true" /></summary>
    <div className={styles.body}>{children}</div>
  </details>;
}
