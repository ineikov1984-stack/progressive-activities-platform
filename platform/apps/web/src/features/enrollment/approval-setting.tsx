'use client';

import { useState } from 'react';
import { type ApprovalMode, changeErrorMessage, saveGroupApproval } from './api';
import styles from './enrollments.module.css';

type Props = { organizationId: string; groupId: string; mode: ApprovalMode; canManage: boolean; onSaved: () => void };

const options: { value: ApprovalMode; title: string; text: string }[] = [
  { value: 'manual', title: 'Ръчно от екипа', text: 'Всяка заявка чака вашето решение.' },
  { value: 'automatic', title: 'Автоматично, ако има място', text: 'Детето се записва веднага. При пълна група заявката отива в чакащите.' },
];

/** How the group's applications are decided. Changes apply to new applications; the ones waiting stay with the team. */
export function ApprovalSetting({ organizationId, groupId, mode, canManage, onSaved }: Props) {
  const [current, setCurrent] = useState<ApprovalMode>(mode);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  if (!canManage) {
    return <p className={styles.small}>Одобрение на заявките: {options.find((item) => item.value === current)?.title.toLocaleLowerCase('bg')}.</p>;
  }

  async function choose(next: ApprovalMode) {
    if (next === current || busy) return;
    const previous = current;
    setCurrent(next); setBusy(true); setMessage(''); setError('');
    try {
      await saveGroupApproval(organizationId, groupId, next);
      setMessage(next === 'automatic'
        ? 'Записано. Новите заявки се одобряват сами, докато има място. Заявките, които вече чакат, решавате вие.'
        : 'Записано. Новите заявки ще чакат вашето решение.');
      onSaved();
    } catch (reason) {
      setCurrent(previous);
      setError(changeErrorMessage(reason, 'Настройката не беше запазена. Опитайте отново.'));
    } finally { setBusy(false); }
  }

  return (
    <fieldset className={styles.approval} disabled={busy}>
      <legend>Одобрение на заявките</legend>
      <div className={styles.approvalOptions}>
        {options.map((item) => (
          <label key={item.value} className={`${styles.option} ${current === item.value ? styles.optionOn : ''}`}>
            <input type="radio" name={`approval-${groupId}`} value={item.value} checked={current === item.value} onChange={() => void choose(item.value)} />
            <span><strong>{item.title}</strong><span className={styles.small}>{item.text}</span></span>
          </label>
        ))}
      </div>
      <p className={message ? styles.rowNotice : styles.srOnly} role="status">{message}</p>
      {error ? <p className={styles.error} role="alert">{error}</p> : null}
    </fieldset>
  );
}
