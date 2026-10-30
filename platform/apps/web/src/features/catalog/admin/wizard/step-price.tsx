'use client';

import { formatEur, parseEuroCents } from '../presentation';
import { blankPrice, defaultUnit, enrollmentModes, modeHints, type OfferingDraft, type PriceDraft, priceUnits } from './offering';
import { StepPriceTerms } from './step-price-terms';
import { Field, Icon, icons } from './ui';
import styles from './wizard.module.css';

type Props = {
  organizationId: string;
  offering: OfferingDraft;
  /** Enrollment modes of the groups' own prices (step 7). */
  groupModes: string[];
  problems: Record<string, string>;
  locked: boolean;
  onOffering: (changes: Partial<OfferingDraft>) => void;
};

function preview(amount: string): string | undefined {
  try { return formatEur(parseEuroCents(amount)); } catch { return undefined; }
}

export function StepPrice({ organizationId, offering, groupModes, problems, locked, onOffering }: Props) {
  const prices = offering.prices;
  const setPrice = (index: number, changes: Partial<PriceDraft>) => onOffering({ prices: prices.map((price, at) => at === index ? { ...price, ...changes } : price) });
  const unused = enrollmentModes.find((mode) => !prices.some((price) => price.enrollment_mode === mode.value));

  return (
    <fieldset className={styles.fieldsetPlain} disabled={locked}>
      <div className={styles.field}>
        <span className={styles.label}>Начини на записване и цени</span>
        <span className={styles.hint}>Добавете всеки начин, по който родител може да запише детето. Цените са в евро.</span>
      </div>
      {prices.length === 0 ? <p className={styles.muted}>Все още няма цена. Без цена дейността не може да бъде публикувана.</p> : null}
      {prices.map((price, index) => {
        const shown = preview(price.amount);
        const problem = problems[`price_${index}`];
        return (
          <div key={index} className={styles.priceCard}>
            <div className={styles.twoFields}>
              <Field id={`price-mode-${index}`} label="Начин на записване" hint={modeHints[price.enrollment_mode]}>
                <select id={`price-mode-${index}`} className={styles.input} value={price.enrollment_mode} onChange={(event) => setPrice(index, { enrollment_mode: event.target.value, unit: defaultUnit[event.target.value] ?? price.unit })}>
                  {enrollmentModes.map((mode) => <option key={mode.value} value={mode.value}>{mode.label}</option>)}
                </select>
              </Field>
              <div className={styles.amountRow}>
                <Field id={`price-amount-${index}`} label="Цена">
                  <span className={styles.amountInput}>
                    <input id={`price-amount-${index}`} className={styles.input} inputMode="decimal" value={price.amount} placeholder="30,00" aria-invalid={problem ? true : undefined} aria-describedby={problem ? `price-problem-${index}` : undefined} onChange={(event) => setPrice(index, { amount: event.target.value })} />
                    <span aria-hidden="true">€</span>
                  </span>
                </Field>
                <Field id={`price-unit-${index}`} label="За">
                  <select id={`price-unit-${index}`} className={styles.input} value={price.unit} onChange={(event) => setPrice(index, { unit: event.target.value })}>
                    {priceUnits.map((unit) => <option key={unit.value} value={unit.value}>{unit.label}</option>)}
                  </select>
                </Field>
              </div>
            </div>
            {problem ? <p className={styles.fieldError} id={`price-problem-${index}`}>{problem}</p> : shown ? <p className={styles.pricePreview}>Родителите виждат: <strong>{shown} {priceUnits.find((unit) => unit.value === price.unit)?.label}</strong></p> : null}
            <div className={styles.priceActions}>
              <label className={styles.toggle}>
                <input type="checkbox" checked={price.is_available !== false} onChange={(event) => setPrice(index, { is_available: event.target.checked })} />
                Достъпна за записване
              </label>
              <button type="button" className={styles.quiet} onClick={() => onOffering({ prices: prices.filter((_, at) => at !== index) })}><Icon path={icons.trash} />Премахни</button>
            </div>
          </div>
        );
      })}
      {unused ? (
        <button type="button" className={styles.quiet} onClick={() => onOffering({ prices: [...prices, { ...blankPrice(), enrollment_mode: unused.value, unit: defaultUnit[unused.value] ?? 'month' }] })}>
          <Icon path={icons.plus} />Добави начин на записване
        </button>
      ) : null}

      <StepPriceTerms
        organizationId={organizationId}
        modes={[...prices.map((price) => price.enrollment_mode), ...groupModes]}
        chosenId={offering.payment_terms_id}
        locked={locked}
        onChoose={(id) => onOffering({ payment_terms_id: id })}
      />

      <Field id="w-enroll" label="Как се записва детето (по избор)" hint="Какво трябва да направи родителят след заявката.">
        <textarea id="w-enroll" className={`${styles.input} ${styles.textareaSmall}`} value={offering.enrollment_instructions} placeholder="Например: след заявката ще ви се обадим до 2 работни дни." onChange={(event) => onOffering({ enrollment_instructions: event.target.value })} />
      </Field>
      <Field id="w-email" label="Имейл за въпроси" hint="Родителите пишат на него. Попълнен е с имейла на организацията — сменете го, ако за тази дейност отговаря друг.">
        <input id="w-email" type="email" required className={styles.input} value={offering.contact_email} aria-invalid={problems.contact_email ? true : undefined} aria-describedby={problems.contact_email ? 'w-email-problem w-email-hint' : 'w-email-hint'} onChange={(event) => onOffering({ contact_email: event.target.value })} />
      </Field>
      {problems.contact_email ? <p className={styles.fieldError} id="w-email-problem">{problems.contact_email}</p> : null}
    </fieldset>
  );
}
