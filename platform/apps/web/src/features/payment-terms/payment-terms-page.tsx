'use client';

import { useEffect, useMemo, useRef, useState } from 'react';
import { useDashboardContext } from '@/features/dashboard/dashboard-context';
import {
  createPaymentTerms, loadPaymentTerms, PaymentTermsApiError, updatePaymentTerms,
  type BillingKind, type BillingKindInfo, type Json, type PaymentTermsSet, type PaymentTermsWorkspace, type Preset, type Rules, type SelfService,
} from './api';
import { RuleEditor, RuleIcon, type EditorContext } from './rule-editors';
import { canonical, describeTerms, errorsFor, exampleFor, KIND_HINTS, matchPreset, presetBlurb, READY_PRESETS, selfServiceOptions, withValue } from './rules';
import styles from './payment-terms.module.css';

type Draft = { rules: Rules; self_service: SelfService };

const DEFAULT_NAME = 'Условията на организацията';

/** The organization's default set for a way of paying; without one "Стандартни" apply. */
function savedSet(workspace: PaymentTermsWorkspace, kind: BillingKind): PaymentTermsSet | undefined {
  return workspace.payment_terms.find((set) => set.billing_kind === kind && set.is_default && set.status === 'active');
}

function baseDraft(workspace: PaymentTermsWorkspace, info: BillingKindInfo): Draft {
  const saved = savedSet(workspace, info.value);
  if (saved?.current_version) return { rules: saved.current_version.rules, self_service: saved.self_service };
  return { rules: info.presets.standard.rules, self_service: info.presets.standard.self_service };
}

/** Ways of paying shown first when no activity uses any yet; camps and kindergartens wait for such an activity. */
const COMMON_KINDS: BillingKind[] = ['monthly', 'visit', 'full_period', 'package'];

function usageText(names: string[] | undefined): string {
  if (!names?.length) return 'Още нямате дейност с такава цена.';
  const shown = names.slice(0, 3).join(', ');
  return names.length > 3 ? `${shown} и още ${names.length - 3}` : shown;
}

const sameDraft = (a: Draft, b: Draft) => canonical(a.rules) === canonical(b.rules) && canonical(a.self_service) === canonical(b.self_service);

function savedOn(value: string): string {
  return new Intl.DateTimeFormat('bg-BG', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Europe/Sofia' }).format(new Date(value));
}

/**
 * "Настройки → Условия за плащане" (docs/product/payment-policies.md, т. 7.1; step 2c): one set of terms for
 * each way of paying, chosen from the ready-made ones or changed number by number, with the words parents read.
 */
export function PaymentTermsPage() {
  const { organization } = useDashboardContext();
  const [workspace, setWorkspace] = useState<PaymentTermsWorkspace>();
  const [loadError, setLoadError] = useState('');
  const [kind, setKind] = useState<BillingKind>('monthly');
  const [drafts, setDrafts] = useState<Partial<Record<BillingKind, Draft>>>({});
  const [customPicked, setCustomPicked] = useState<Partial<Record<BillingKind, boolean>>>({});
  // The organization's own numbers, kept when a ready-made set is tried, so "Собствени" brings them back.
  const [customDrafts, setCustomDrafts] = useState<Partial<Record<BillingKind, Draft>>>({});
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [notice, setNotice] = useState<{ tone: 'ok' | 'error'; text: string }>();
  const [saving, setSaving] = useState(false);
  const [allKinds, setAllKinds] = useState(false);
  const [allLines, setAllLines] = useState(false);
  const noticeRef = useRef<HTMLParagraphElement>(null);
  const editorRef = useRef<HTMLElement>(null);

  const [attempt, setAttempt] = useState(0);
  useEffect(() => {
    let active = true;
    loadPaymentTerms(organization.id)
      .then((loaded) => { if (active) setWorkspace(loaded); })
      .catch((reason: unknown) => {
        if (active) setLoadError(reason instanceof PaymentTermsApiError ? reason.message : 'Условията не се заредиха. Проверете връзката и опитайте отново.');
      });
    return () => { active = false; };
  }, [organization.id, attempt]);
  const retry = () => {
    setLoadError('');
    setAttempt((current) => current + 1);
  };

  // After a refused save the first rule with a problem takes the focus; otherwise the message does.
  useEffect(() => {
    if (!notice) return;
    const invalid = notice.tone === 'error'
      ? editorRef.current?.querySelector<HTMLElement>('input[aria-invalid="true"], fieldset[data-invalid="true"] input:not([disabled]), fieldset[data-invalid="true"] select:not([disabled])')
      : null;
    if (invalid) {
      invalid.scrollIntoView({ block: 'center' });
      invalid.focus();
      return;
    }
    noticeRef.current?.scrollIntoView({ block: 'nearest' });
    noticeRef.current?.focus();
  }, [notice]);

  const info = workspace?.billing_kinds.find((item) => item.value === kind);
  const base = useMemo(() => (workspace && info ? baseDraft(workspace, info) : undefined), [workspace, info]);
  const draft = drafts[kind] ?? base;
  const saved = workspace ? savedSet(workspace, kind) : undefined;
  const dirtyKinds = useMemo(() => new Set((workspace?.billing_kinds ?? [])
    .filter((item) => drafts[item.value] && !sameDraft(drafts[item.value] as Draft, baseDraft(workspace as PaymentTermsWorkspace, item)))
    .map((item) => item.value)), [drafts, workspace]);

  // Unsaved numbers are not lost silently when the page is closed or reloaded.
  const anyDirty = dirtyKinds.size > 0;
  useEffect(() => {
    if (!anyDirty) return;
    const warn = (event: BeforeUnloadEvent) => { event.preventDefault(); };
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [anyDirty]);

  if (loadError) {
    return (
      <div className={styles.page}>
        <Header />
        <div className={styles.problem} role="alert">
          <p>{loadError}</p>
          <button type="button" className={styles.secondary} onClick={retry}>Опитайте отново</button>
        </div>
      </div>
    );
  }
  if (!workspace || !info || !draft || !base) {
    return (
      <div className={styles.page} aria-busy="true">
        <Header />
        <div className={styles.skeleton} aria-hidden="true"><span /><span /><span /></div>
        <p className="sr-only">Зареждане на условията…</p>
      </div>
    );
  }

  const canManage = workspace.can_manage;
  const preset: Preset = customPicked[kind] ? 'custom' : matchPreset(info, draft.rules, draft.self_service);
  const dirty = dirtyKinds.has(kind);

  const changeDraft = (next: Draft) => {
    setDrafts((current) => ({ ...current, [kind]: next }));
    setNotice(undefined);
  };
  const pickKind = (next: BillingKind) => {
    setKind(next);
    setErrors({});
    setNotice(undefined);
  };
  const pickPreset = (next: Preset) => {
    if (next === 'custom') {
      setCustomPicked((current) => ({ ...current, [kind]: true }));
      const own = customDrafts[kind];
      if (own) changeDraft(own);
      return;
    }
    if (preset === 'custom') setCustomDrafts((current) => ({ ...current, [kind]: draft }));
    setCustomPicked((current) => ({ ...current, [kind]: false }));
    changeDraft({ rules: info.presets[next].rules, self_service: info.presets[next].self_service });
  };
  const context: EditorContext = {
    rules: draft.rules,
    disabled: !canManage || saving,
    errors,
    inMarketplace: workspace.in_marketplace,
    change: (path: string, value: Json) => changeDraft({ ...draft, rules: withValue(draft.rules, path, value) }),
  };

  const save = async () => {
    setSaving(true);
    setErrors({});
    setNotice(undefined);
    try {
      const result = saved
        ? await updatePaymentTerms(organization.id, saved.id, { version: saved.version, rules: draft.rules, self_service: draft.self_service })
        : await createPaymentTerms(organization.id, { billing_kind: kind, name: DEFAULT_NAME, is_default: true, rules: draft.rules, self_service: draft.self_service });
      setWorkspace((current) => current && ({
        ...current,
        payment_terms: [...current.payment_terms.filter((set) => set.id !== result.id), result],
      }));
      setDrafts((current) => ({ ...current, [kind]: undefined }));
      setCustomPicked((current) => ({ ...current, [kind]: false }));
      setCustomDrafts((current) => ({ ...current, [kind]: undefined }));
      const others = workspace.billing_kinds.filter((item) => item.value !== kind && dirtyKinds.has(item.value)).map((item) => `„${item.label}“`);
      setNotice({ tone: 'ok', text: `Условията за „${info.label}“ са запазени.${others.length ? ` Още не са запазени: ${others.join(', ')}.` : ''}` });
    } catch (reason) {
      if (reason instanceof PaymentTermsApiError) {
        setErrors(reason.fieldErrors);
        // Messages that belong to no rule on the screen are said in the message itself.
        const loose = Object.entries(reason.fieldErrors).filter(([key]) => !key.startsWith('rules.') && !key.startsWith('self_service.')).flatMap(([, list]) => list);
        setNotice({ tone: 'error', text: [reason.message, ...loose].join(' ') });
      } else {
        setNotice({ tone: 'error', text: 'Условията не бяха запазени. Проверете връзката и опитайте отново.' });
      }
    } finally {
      setSaving(false);
    }
  };

  const lines = describeTerms(kind, draft.rules);
  // Monthly fees carry their examples under each rule; the others keep one example beside the words.
  const example = kind === 'monthly' ? null : exampleFor(kind, draft.rules);
  const usage = workspace.usage ?? {};
  const used = workspace.billing_kinds.filter((item) => (usage[item.value] ?? []).length > 0).map((item) => item.value);
  const primary = new Set<BillingKind>([...(used.length ? used : COMMON_KINDS), kind, ...dirtyKinds,
    ...workspace.payment_terms.filter((set) => set.status === 'active').map((set) => set.billing_kind)]);
  const shownKinds = workspace.billing_kinds.filter((item) => allKinds || primary.has(item.value));
  const hiddenKinds = workspace.billing_kinds.filter((item) => !primary.has(item.value));
  const violations = saved ? Object.values(saved.marketplace_violations) : [];
  const unsaved = workspace.billing_kinds.filter((item) => dirtyKinds.has(item.value)).map((item) => `„${item.label}“`);
  const selfServiceErrors = errorsFor(errors, ['self_service']);

  return (
    <div className={styles.page}>
      <Header />

      {!canManage ? <p className={styles.info}>Само собственикът и администраторът могат да променят условията. Вие ги виждате така, както са запазени.</p> : null}

      <fieldset className={styles.part}>
        <legend className={styles.partTitle}>1. За кой начин на плащане настройвате?</legend>
        <div className={styles.kindGrid}>
          {shownKinds.map((item) => (
            <label key={item.value} className={item.value === kind ? `${styles.option} ${styles.optionOn}` : styles.option}>
              <span className={styles.optionHead}>
                <input type="radio" name="billing-kind" value={item.value} checked={item.value === kind} onChange={() => pickKind(item.value)} />
                <span className={styles.optionName}>{item.label}</span>
              </span>
              <span className={styles.hint}>{KIND_HINTS[item.value]}</span>
              <span className={(usage[item.value] ?? []).length ? styles.usage : styles.hint}>
                {(usage[item.value] ?? []).length ? <strong>Ползва се при: </strong> : null}{usageText(usage[item.value])}
              </span>
              {dirtyKinds.has(item.value) ? <span className={styles.unsaved}><span className={styles.dot} aria-hidden="true" />Незапазени промени</span> : null}
            </label>
          ))}
        </div>
        {hiddenKinds.length && !allKinds ? (
          <button type="button" className={styles.linkButton} onClick={() => setAllKinds(true)}>
            Още {hiddenKinds.length} {hiddenKinds.length === 1 ? 'начин' : 'начина'}: {hiddenKinds.map((item) => item.label.toLowerCase()).join(', ')} — ще ги видите, щом добавите такава дейност
          </button>
        ) : null}
      </fieldset>

      <fieldset className={styles.part} disabled={!canManage || saving}>
        <legend className={styles.partTitle}>2. Изберете готови условия</legend>
        <div className={styles.presets}>
          {[...READY_PRESETS, 'custom' as const].map((item) => (
            <label key={item} className={[styles.option, item === preset ? styles.optionOn : '', item === 'custom' ? styles.optionCustom : ''].join(' ')}>
              <span className={styles.optionHead}>
                <input type="radio" name={`preset-${kind}`} value={item} checked={item === preset} onChange={() => pickPreset(item)} />
                <span className={styles.optionName}>{item === 'custom' ? 'Собствени' : info.presets[item].label}</span>
                {item === 'standard' ? <span className={styles.recommended}>Препоръчани</span> : null}
              </span>
              <span className={styles.hint}>{presetBlurb(kind, item)}</span>
            </label>
          ))}
        </div>
      </fieldset>

      <div className={styles.layout}>
        <section className={styles.editor} aria-labelledby="terms-title" ref={editorRef}>
          <div className={styles.editorHead}>
            <h2 id="terms-title">3. Проверете условията за {info.label.toLowerCase()}</h2>
            <p className={styles.muted}>
              {saved?.current_version
                ? `Запазени на ${savedOn(saved.current_version.created_at)} като „${preset === 'custom' ? 'Собствени' : info.presets[preset].label}“.${dirty ? ' Имате незапазени промени.' : ''}`
                : 'Организацията още не е запазила свои условия за този начин на плащане — важат „Стандартни“.'}
            </p>
            {kind === 'monthly' ? <p className={styles.muted}>Пример навсякъде: такса 60 € на месец, 4 занимания в ноември — по 15 € всяко.</p> : null}
          </div>
          {violations.length ? (
            <div className={styles.warning} role="note">
              <strong>Тези условия не отговарят на правилата за записване през платформата.</strong>
              {violations.map((text) => <p key={text}>{text}</p>)}
              <p>Поправете ги и ги запазете.</p>
            </div>
          ) : null}

          <RuleEditor kind={kind} context={context} />

          <fieldset className={styles.rule}>
            <legend className={styles.ruleTitle}><RuleIcon name="person" />Какво може родителят сам, без да ви пита</legend>
            <div className={styles.ruleBody}>
              <p className={styles.hint}>Ще работи, когато родителите имат профил в платформата. Дотогава ви пишат или се обаждат.</p>
              {selfServiceOptions(kind).map((option) => (
                <SelfServiceOption key={option} option={option} value={draft.self_service} disabled={!canManage || saving}
                  onChange={(self_service) => changeDraft({ ...draft, self_service })} />
              ))}
              {selfServiceErrors.map((message) => <p key={message} className={styles.fieldError}>{message}</p>)}
            </div>
          </fieldset>
        </section>

        <aside className={styles.aside} aria-label="Как го четат родителите">
          <section className={allLines ? styles.card : `${styles.card} ${styles.cardFolded}`}>
            <h2>Така го четат родителите</h2>
            {lines.map((line) => <p key={line.title}><strong>{line.title}.</strong> {line.text}</p>)}
            {lines.length > 1 && !allLines ? <button type="button" className={styles.unfold} onClick={() => setAllLines(true)}>Виж всички условия</button> : null}
          </section>
          {example ? (
            <section className={`${styles.card} ${styles.cardExample}`}>
              <h2>Пример</h2>
              <p>{example.intro}</p>
              <ul>{example.lines.map((line) => <li key={line}>{line}</li>)}</ul>
              <p className={styles.small}>Сумите са само за пример.</p>
            </section>
          ) : null}
          <section className={`${styles.card} ${styles.cardAlways}`}>
            <h2><RuleIcon name="document" />Винаги важи, каквото и да изберете</h2>
            <p>Занимание, отменено от вас, не се плаща.</p>
            <p>Записаните досега деца остават с условията, които са приели.</p>
            <p>Отказ по имейл, по телефона или от профила винаги се приема.</p>
            <p>Бележка от лекар не се качва — екипът само отбелязва, че я е видял.</p>
          </section>
        </aside>
      </div>

      {canManage ? (
        <div className={dirty ? `${styles.saveBar} ${styles.saveBarSticky}` : styles.saveBar}>
          <button type="button" className={styles.primary} onClick={save} disabled={saving || (!!saved && !dirty)}>
            {saving ? 'Запазване…' : `Запази условията за ${info.label.toLowerCase()}`}
          </button>
          {dirty ? (
            <button type="button" className={styles.secondary} disabled={saving} onClick={() => {
              setDrafts((current) => ({ ...current, [kind]: undefined }));
              setCustomPicked((current) => ({ ...current, [kind]: false }));
              setErrors({});
              setNotice(undefined);
            }}>Върни запазените</button>
          ) : null}
          <span className={styles.muted}>Записаните досега деца остават с условията, които са приели.</span>
          {notice ? (
            <p ref={noticeRef} tabIndex={-1} className={notice.tone === 'ok' ? styles.ok : styles.error} role={notice.tone === 'ok' ? 'status' : 'alert'}>{notice.text}</p>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}

function Header() {
  return (
    <header className={styles.header}>
      <p className={styles.eyebrow}>Настройки на организацията</p>
      <h1>Условия за плащане</h1>
      <p className={styles.lead}>Решете какво плаща родителят, когато детето спре, започне по средата на месеца, вземе пауза или отсъства. Задавате го веднъж — важи за всички дейности с този начин на плащане. Родителите виждат условията, преди да се запишат.</p>
    </header>
  );
}

const SELF_SERVICE_TEXT: Record<keyof SelfService, string> = {
  absence: 'Да отбележи, че детето няма да дойде',
  pause_weeks: 'Да пусне пауза до',
  end: 'Да спре записването (иначе ви изпраща молба)',
  cancel_visit: 'Да откаже посещение',
};

function SelfServiceOption({ option, value, disabled, onChange }: { option: keyof SelfService; value: SelfService; disabled: boolean; onChange: (next: SelfService) => void }) {
  if (option === 'pause_weeks') return <PauseWeeks value={value} disabled={disabled} onChange={onChange} />;
  return (
    <label className={styles.check}>
      <input type="checkbox" checked={value[option]} disabled={disabled} onChange={(event) => {
        const next: SelfService = { ...value };
        next[option] = event.target.checked;
        onChange(next);
      }} />
      <span>{SELF_SERVICE_TEXT[option]}</span>
    </label>
  );
}

/** Typed freely and read when the field is left; only the checkbox turns the option off. */
function PauseWeeks({ value, disabled, onChange }: { value: SelfService; disabled: boolean; onChange: (next: SelfService) => void }) {
  const [text, setText] = useState<string>();
  const on = value.pause_weeks > 0;
  const commit = () => {
    if (text === undefined) return;
    const weeks = Math.round(Number(text));
    if (text.trim() !== '' && Number.isFinite(weeks)) onChange({ ...value, pause_weeks: Math.max(1, Math.min(12, weeks)) });
    setText(undefined);
  };
  return (
    <div className={styles.line}>
      <label className={styles.check}>
        <input type="checkbox" checked={on} disabled={disabled} onChange={(event) => { setText(undefined); onChange({ ...value, pause_weeks: event.target.checked ? 2 : 0 }); }} />
        <span>{SELF_SERVICE_TEXT.pause_weeks}</span>
      </label>
      <input className={styles.number} type="number" inputMode="numeric" min={1} max={12} step={1} aria-label="Седмици пауза без одобрение"
        value={text ?? (on ? String(value.pause_weeks) : '')} disabled={disabled || !on}
        onChange={(event) => setText(event.target.value)} onBlur={commit}
        onKeyDown={(event) => { if (event.key === 'Enter') commit(); }} />
      <span>седмици без одобрение</span>
    </div>
  );
}
