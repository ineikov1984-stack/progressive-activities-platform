'use client';

import { useEffect, useState } from 'react';
import { createPaymentTerms, errorMessage, loadPaymentTerms, PaymentTermsApiError, type BillingKind, type PaymentTermsSet, type PaymentTermsWorkspace } from '../../../payment-terms/api';
import { freeName, optionSummary, organizationTerms, otherOptions, type TermsSummary } from './terms-choice';
import { Icon, icons } from './ui';
import styles from './wizard.module.css';

export type TermsWorkspace = { workspace?: PaymentTermsWorkspace; failed: boolean; retry: () => void; added: (set: PaymentTermsSet) => void };

/** The organization's "Такси, отсъствия и отказ", loaded once for step 6. */
export function useTermsWorkspace(organizationId: string): TermsWorkspace {
  const [workspace, setWorkspace] = useState<PaymentTermsWorkspace>();
  const [failed, setFailed] = useState(false);
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let current = true;
    loadPaymentTerms(organizationId)
      .then((loaded) => { if (current) setWorkspace(loaded); })
      .catch(() => { if (current) setFailed(true); });
    return () => { current = false; };
  }, [organizationId, attempt]);

  return {
    workspace,
    failed,
    retry: () => { setFailed(false); setAttempt((value) => value + 1); },
    added: (set) => setWorkspace((loaded) => loaded ? { ...loaded, payment_terms: [...loaded.payment_terms, set] } : loaded),
  };
}

function Lines({ summary }: { summary: TermsSummary }) {
  return (
    <>
      <ul className={styles.termsLines}>
        {summary.lines.map((line) => <li key={line.title}><strong>{line.title}:</strong> {line.text}</li>)}
      </ul>
      {summary.violations.length > 0 ? (
        <p className={styles.conflictWarning}><Icon path={icons.warn} />{summary.violations.join(' ')} С тези условия дейността няма да може да се публикува.</p>
      ) : null}
    </>
  );
}

type Props = {
  id: string;
  organizationId: string;
  terms: TermsWorkspace;
  kind: BillingKind;
  chosenId: string | undefined;
  /** The other price that shares this way of paying (a trial and a single visit), named for the team. */
  sharedWith?: string;
  locked: boolean;
  onChoose: (id: string | null) => void;
};

/** "Такси, отсъствия и отказ" for one price: the organization's terms or a set chosen for this way of paying. */
export function PriceTerms({ id, organizationId, terms, kind, chosenId, sharedWith, locked, onChoose }: Props) {
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const { workspace } = terms;

  if (terms.failed) {
    return (
      <div className={styles.priceTerms}>
        <p className={styles.fieldError}>Правилата за такси, отсъствия и отказ не се заредиха.</p>
        <button type="button" className={styles.quiet} onClick={terms.retry}>Опитай отново</button>
      </div>
    );
  }
  if (!workspace) return <div className={styles.priceTerms} aria-busy="true"><div className={styles.termsSkeleton} /></div>;

  const options = otherOptions(workspace, kind);
  const chosen = chosenId ? options.find((option) => 'set' in option && option.set.id === chosenId) : undefined;
  const own = organizationTerms(workspace, kind);

  const choose = async (value: string) => {
    setMessage('');
    if (value === '') { onChoose(null); return; }
    const option = options.find((item) => item.value === value);
    if (!option) return;
    if ('set' in option) { onChoose(option.set.id); return; }
    // A ready-made set is saved once, as one of the organization's sets, then chosen.
    setBusy(true);
    try {
      const saved = await createPaymentTerms(organizationId, {
        billing_kind: option.kind, name: freeName(workspace, option.kind, option.label), is_default: false,
        rules: option.rules, self_service: option.selfService,
      });
      terms.added(saved);
      onChoose(saved.id);
    } catch (error) {
      setMessage(error instanceof PaymentTermsApiError ? Object.values(error.fieldErrors).flat()[0] ?? error.message : errorMessage(0, undefined));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className={styles.priceTerms} aria-busy={busy || undefined}>
      <label className={styles.label} htmlFor={id}>Такси, отсъствия и отказ</label>
      <select id={id} className={`${styles.input} ${styles.termsSelect}`} value={chosen?.value ?? ''} disabled={locked || busy} aria-describedby={`${id}-hint`} onChange={(event) => { void choose(event.target.value); }}>
        <option value="">Условията на организацията — {own.presetLabel}</option>
        {options.map((option) => (
          <option key={option.value} value={option.value} disabled={!('set' in option) && !workspace.can_manage}>{option.label}</option>
        ))}
      </select>
      <span className={styles.hint} id={`${id}-hint`}>
        {sharedWith ? `Важат и за „${sharedWith}“ — пробното и еднократното посещение са с едни и същи правила.` : `Важат за тази цена (${own.kindLabel.toLocaleLowerCase('bg')}).`}
        {!workspace.can_manage && options.some((option) => !('set' in option)) ? ' Готовите условия, които организацията още не ползва, се добавят от собственика или администратора.' : ''}
      </span>
      <Lines summary={chosen ? optionSummary(workspace, chosen) : own} />
      {busy ? <p className={styles.muted} role="status">Запазване на условията…</p> : null}
      {message ? <p className={styles.fieldError} role="alert">{message}</p> : null}
    </div>
  );
}
