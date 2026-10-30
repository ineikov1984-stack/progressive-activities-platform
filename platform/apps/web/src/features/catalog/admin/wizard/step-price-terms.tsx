'use client';

import Link from 'next/link';
import { useEffect, useMemo, useState } from 'react';
import { createPaymentTerms, errorMessage, loadPaymentTerms, PaymentTermsApiError, type PaymentTermsWorkspace } from '../../../payment-terms/api';
import { freeName, kindsOf, optionSummary, organizationTerms, otherOptions, stillFits, type TermsSummary } from './terms-choice';
import { Icon, icons } from './ui';
import styles from './wizard.module.css';

type Props = {
  organizationId: string;
  /** The enrollment modes of all the activity's prices (step 6 and the groups). */
  modes: string[];
  chosenId: string | null;
  locked: boolean;
  onChoose: (id: string | null) => void;
};

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

/** "Какви условия важат за тази дейност?" — board "Дейност · Условия" (approved 30.10.2026). */
export function StepPriceTerms({ organizationId, modes, chosenId, locked, onChoose }: Props) {
  const [workspace, setWorkspace] = useState<PaymentTermsWorkspace>();
  const [loadFailed, setLoadFailed] = useState(false);
  const [attempt, setAttempt] = useState(0);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const kinds = useMemo(() => kindsOf(modes), [modes]);

  useEffect(() => {
    let current = true;
    loadPaymentTerms(organizationId)
      .then((loaded) => { if (current) setWorkspace(loaded); })
      .catch(() => { if (current) setLoadFailed(true); });
    return () => { current = false; };
  }, [organizationId, attempt]);

  // A choice that no longer fits (archived, or for a price the activity no longer has) would make every save
  // fail: the activity falls back to the organization's terms, which the radio buttons then show.
  useEffect(() => {
    if (workspace && chosenId && !locked && !stillFits(workspace, chosenId, kinds)) onChoose(null);
  }, [workspace, chosenId, kinds, locked, onChoose]);

  if (kinds.length === 0) return null;
  if (loadFailed) {
    return (
      <div className={styles.termsBlock}>
        <p className={styles.fieldError}>Правилата за такси, отсъствия и отказ не се заредиха.</p>
        <button type="button" className={styles.quiet} onClick={() => { setLoadFailed(false); setAttempt((value) => value + 1); }}>Опитай отново</button>
      </div>
    );
  }
  if (!workspace) return <div className={styles.termsBlock} aria-busy="true"><div className={styles.termsSkeleton} /><p className={styles.muted}>Зареждане на правилата за такси, отсъствия и отказ…</p></div>;

  const options = otherOptions(workspace, kinds);
  const chosen = chosenId ? options.find((option) => 'set' in option && option.set.id === chosenId) : undefined;
  const own = chosenId !== null && chosen !== undefined;
  const kindLabels = kinds.map((kind) => organizationTerms(workspace, kind).kindLabel);

  const choose = async (value: string) => {
    const option = options.find((item) => item.value === value);
    if (!option) return;
    setMessage('');
    if ('set' in option) { onChoose(option.set.id); return; }
    // A ready-made set is saved once, as one of the organization's sets, then chosen.
    setBusy(true);
    try {
      const saved = await createPaymentTerms(organizationId, {
        billing_kind: option.kind, name: freeName(workspace, option.kind, option.label), is_default: false,
        rules: option.rules, self_service: option.selfService,
      });
      setWorkspace({ ...workspace, payment_terms: [...workspace.payment_terms, saved] });
      onChoose(saved.id);
    } catch (error) {
      setMessage(error instanceof PaymentTermsApiError ? Object.values(error.fieldErrors).flat()[0] ?? error.message : errorMessage(0, undefined));
    } finally {
      setBusy(false);
    }
  };
  const firstUsable = options.find((option) => 'set' in option || workspace.can_manage);

  return (
    <fieldset className={styles.termsBlock} disabled={locked || busy} aria-busy={busy || undefined}>
      <legend className={styles.termsLegend}>
        <h3 className={styles.termsTitle}>Какви условия важат за тази дейност?</h3>
        <span className={styles.hint}>Какво се плаща при прекратяване, пауза и отсъствия и какво се връща при отказ. Родителят ги вижда до цената и ги приема при записване.</span>
      </legend>

      <div className={`${styles.termsCard} ${own ? '' : styles.termsCardOn}`}>
        <label className={styles.termsHead}>
          <input type="radio" name="activity-terms" checked={!own} onChange={() => { setMessage(''); onChoose(null); }} />
          <span>Условията на организацията{kinds.length === 1 ? ` — ${organizationTerms(workspace, kinds[0]).presetLabel}` : ''}</span>
        </label>
        <div className={styles.termsBody}>
          {kinds.map((kind) => {
            const terms = organizationTerms(workspace, kind);
            return (
              <div key={kind} className={styles.termsKind}>
                {kinds.length > 1 ? <strong>{terms.kindLabel} — {terms.presetLabel}</strong> : null}
                <Lines summary={terms} />
              </div>
            );
          })}
          <Link className={styles.termsLink} href="/dashboard/settings/payment-terms">Промени в „Такси, отсъствия и отказ“</Link>
        </div>
      </div>

      <div className={`${styles.termsCard} ${own ? styles.termsCardOn : ''}`}>
        <label className={styles.termsHead}>
          <input type="radio" name="activity-terms" checked={own} disabled={!firstUsable} onChange={() => { if (firstUsable) void choose(firstUsable.value); }} />
          <span>Други условия само за тази дейност</span>
        </label>
        <div className={styles.termsBody}>
          {options.length > 0 ? (
            <select
              className={`${styles.input} ${styles.termsSelect}`}
              aria-label="Условия за тази дейност"
              value={chosen?.value ?? ''}
              onChange={(event) => { if (event.target.value) void choose(event.target.value); }}
            >
              <option value="" disabled>Изберете условия</option>
              {kinds.map((kind) => {
                const items = options.filter((option) => option.kind === kind);
                if (items.length === 0) return null;
                const list = items.map((option) => (
                  <option key={option.value} value={option.value} disabled={!('set' in option) && !workspace.can_manage}>{option.label}</option>
                ));
                return kinds.length > 1 ? <optgroup key={kind} label={organizationTerms(workspace, kind).kindLabel}>{list}</optgroup> : list;
              })}
            </select>
          ) : <p className={styles.muted}>Няма други условия за {kindLabels.join(' и ').toLocaleLowerCase('bg')}.</p>}
          {!workspace.can_manage && options.some((option) => !('set' in option)) ? (
            <span className={styles.hint}>Готовите условия, които организацията още не ползва, се добавят от собственика или администратора.</span>
          ) : null}
          {chosen ? <Lines summary={optionSummary(workspace, chosen)} /> : null}
          {chosen && kinds.length > 1 ? (
            <span className={styles.hint}>Важат за цените „{organizationTerms(workspace, chosen.kind).kindLabel}“. За другите цени — условията на организацията.</span>
          ) : null}
        </div>
      </div>
      {busy ? <p className={styles.muted} role="status">Запазване на условията…</p> : null}
      {message ? <p className={styles.fieldError} role="alert">{message}</p> : null}
    </fieldset>
  );
}
