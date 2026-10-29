'use client';

import type { ReactNode } from 'react';
import styles from './wizard.module.css';

export const icons = {
  back: 'M19 12H5M11 6l-6 6 6 6',
  next: 'M5 12h14M13 6l6 6-6 6',
  eye: 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z',
  check: 'M5 12l5 5 9-10',
  plus: 'M12 5v14M5 12h14',
  trash: 'M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3',
  info: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z M12 11v5M12 8v.01',
  warn: 'M12 3l10 18H2z M12 10v4M12 17v.01',
  pin: 'M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12z M12 7a2 2 0 1 0 0 4 2 2 0 0 0 0-4z',
  person: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z M4 21c0-4 3.6-6 8-6s8 2 8 6',
  calendar: 'M4 6h16v15H4z M4 10h16M8 3v4M16 3v4',
  edit: 'M4 20h4L19 9l-4-4L4 16v4z',
};

export function Icon({ path }: { path: string }) {
  return <svg className={styles.icon} viewBox="0 0 24 24" aria-hidden="true">{path.split(' M').map((part, index) => <path key={index} d={index === 0 ? part : `M${part}`} />)}</svg>;
}

export function Field({ id, label, hint, children, aside }: { id: string; label: string; hint?: ReactNode; children: ReactNode; aside?: ReactNode }) {
  return (
    <div className={styles.field}>
      <div className={styles.fieldHead}><label className={styles.label} htmlFor={id}>{label}</label>{aside}</div>
      {children}
      {hint ? <span className={styles.hint} id={`${id}-hint`}>{hint}</span> : null}
    </div>
  );
}

export function ListEditor({ id, label, items, placeholder, onChange, disabled, addLabel }: { id: string; label: string; items: string[]; placeholder: string; onChange: (items: string[]) => void; disabled: boolean; addLabel: string }) {
  return (
    <fieldset className={styles.fieldset} disabled={disabled}>
      <legend className={styles.label}>{label}</legend>
      {items.map((item, index) => (
        <div className={styles.listRow} key={index}>
          <input id={`${id}-${index}`} className={styles.input} value={item} placeholder={placeholder} aria-label={`${label} ${index + 1}`} onChange={(event) => onChange(items.map((value, at) => at === index ? event.target.value : value))} />
          <button type="button" className={styles.iconButton} aria-label={`Премахни ред ${index + 1}`} onClick={() => onChange(items.filter((_, at) => at !== index))}><Icon path={icons.trash} /></button>
        </div>
      ))}
      <button type="button" className={styles.quiet} onClick={() => onChange([...items, ''])}><Icon path={icons.plus} />{addLabel}</button>
    </fieldset>
  );
}

