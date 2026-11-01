'use client';

import { useEffect, useMemo, useRef, useState } from 'react';
import { InlineHelp } from '@/features/help/inline-help';
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
      ? (editorRef.current?.querySelector<HTMLElement>('input[aria-invalid="true"]')
        ?? editorRef.current?.querySelector<HTMLElement>('fieldset[data-invalid="true"] input:not([disabled]), fieldset[data-invalid="true"] select:not([disabled])'))
      : null;
    if (invalid) {
      // Open both the topic and any nested optional settings before moving focus.
      for (let ancestor = invalid.parentElement; ancestor; ancestor = ancestor.parentElement) {
        if (ancestor instanceof HTMLDetailsElement) ancestor.open = true;
      }
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
    // A link to another page of the administration asks first (the menu, the header).
    const guard = (event: MouseEvent) => {
      const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
      if (!(link instanceof HTMLAnchorElement) || link.target === '_blank' || link.origin !== window.location.origin) return;
      if (link.pathname === window.location.pathname) return;
      if (!window.confirm('Имате незапазени промени в условията. Да ги изоставите ли?')) {
        event.preventDefault();
        event.stopPropagation();
      }
    };
    window.addEventListener('beforeunload', warn);
    document.addEventListener('click', guard, true);
    // The sticky save bar never covers the field being typed in.
    document.documentElement.style.scrollPaddingBottom = '9rem';
    return () => {
      window.removeEventListener('beforeunload', warn);
      document.removeEventListener('click', guard, true);
      document.documentElement.style.scrollPaddingBottom = '';
    };
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
  const violations = saved ? Object.values(saved.marketplace_violations) : [];
  const unsavedElsewhere = workspace.billing_kinds.filter((item) => item.value !== kind && dirtyKinds.has(item.value)).map((item) => `„${item.label}“`);
  const selfServiceErrors = errorsFor(errors, ['self_service']);

  return (
    <div className={styles.page}>
      <Header />

      {!canManage ? <p className={styles.info}>Само собственикът и администраторът могат да променят условията. Вие ги виждате така, както са запазени.</p> : null}

      <section className={styles.kindPanel} aria-labelledby="kind-title">
        <div className={styles.kindField}>
          <label id="kind-title" htmlFor="billing-kind">Начин на плащане</label>
          <select id="billing-kind" className={styles.kindSelect} value={kind} onChange={(event) => pickKind(event.target.value as BillingKind)}>
            {workspace.billing_kinds.map((item) => <option key={item.value} value={item.value}>{item.label}{dirtyKinds.has(item.value) ? ' · незапазени промени' : ''}</option>)}
          </select>
        </div>
        <div className={styles.kindContext}>
          <p className={styles.hint}>{KIND_HINTS[kind]}</p>
          <p className={(usage[kind] ?? []).length ? styles.usage : styles.hint}>
            {(usage[kind] ?? []).length ? <strong>Ползва се при: </strong> : null}{usageText(usage[kind])}
          </p>
        </div>
      </section>

      <fieldset className={styles.part} disabled={!canManage || saving}>
        <legend className={styles.partTitle}><h2>Изберете основа за условията</h2></legend>
        <div className={styles.presets}>
          {[...READY_PRESETS, 'custom' as const].map((item) => (
            <label key={item} className={[styles.option, item === preset ? styles.optionOn : '', item === 'custom' ? styles.optionCustom : ''].join(' ')}>
              <span className={styles.optionHead}>
                <input type="radio" name={`preset-${kind}`} value={item} checked={item === preset} onChange={() => pickPreset(item)} />
                <span className={styles.optionName}>{item === 'custom' ? 'Собствени' : info.presets[item].label}</span>
              </span>
            </label>
          ))}
        </div>
        <p className={styles.presetDescription} role="status">
          {preset === 'standard' ? <span className={styles.recommended}>Препоръчани</span> : null}
          {presetBlurb(kind, preset)}
        </p>
      </fieldset>

      <div className={styles.layout}>
        <section className={styles.editor} aria-labelledby="terms-title" ref={editorRef}>
          <div className={styles.editorHead}>
            <h2 id="terms-title">Условия за „{info.label}“</h2>
            <p className={styles.muted}>
              {saved?.current_version
                ? `Запазени на ${savedOn(saved.current_version.created_at)} като „${saved.preset_label}“.${dirty ? ' Имате незапазени промени.' : ''}`
                : 'Организацията още не е запазила свои условия за този начин на плащане — важат „Стандартни“.'}
            </p>
            {kind === 'monthly' ? <InlineHelp label="Как са сметнати примерите?">Всички примери са за месечна такса 60,00 € и 4 занимания през ноември, по 15,00 € всяко.</InlineHelp> : null}
          </div>
          {violations.length ? (
            <div className={styles.warning} role="note">
              <strong>Тези условия не отговарят на правилата за записване през платформата.</strong>
              {violations.map((text) => <p key={text}>{text}</p>)}
              <p>Поправете ги и ги запазете.</p>
            </div>
          ) : null}

          <div className={styles.ruleToolbar}>
            <p className={styles.hint}>Отворете темата, която искате да прегледате или промените.</p>
            <div className={styles.toolbarActions}>
              <button type="button" className={styles.linkButton} onClick={() => editorRef.current?.querySelectorAll<HTMLDetailsElement>('details[data-rule-section]').forEach((section) => { section.open = true; })}>Разгъни всички</button>
              <button type="button" className={styles.linkButton} onClick={() => editorRef.current?.querySelectorAll<HTMLDetailsElement>('details[data-rule-section]').forEach((section) => { section.open = false; })}>Прибери всички</button>
            </div>
          </div>
          <RuleEditor key={kind} kind={kind} context={context} />

          <details className={styles.ruleSection} data-rule-section open={selfServiceErrors.length ? true : undefined}>
            <summary className={styles.sectionSummary}><RuleIcon name="person" /><span>Какво може родителят сам, без да ви пита</span><span className={styles.sectionSign} aria-hidden="true" /></summary>
            <fieldset className={styles.rule} data-invalid={selfServiceErrors.length ? 'true' : undefined}>
            <legend className="sr-only">Какво може родителят сам, без да ви пита</legend>
            <div className={styles.ruleBody}>
              <p className={styles.hint}>Ще работи, когато родителите имат профил в платформата. Дотогава ви пишат или се обаждат.</p>
              {selfServiceOptions(kind).map((option) => (
                <SelfServiceOption key={option} option={option} value={draft.self_service} disabled={!canManage || saving}
                  onChange={(self_service) => changeDraft({ ...draft, self_service })} />
              ))}
              {selfServiceErrors.map((message) => <p key={message} className={styles.fieldError}>{message}</p>)}
            </div>
          </fieldset>
          </details>
        </section>

        <aside className={styles.aside} aria-label="Преглед и помощ">
          <details className={styles.preview}>
            <summary>Преглед за родителя<span className={styles.sectionSign} aria-hidden="true" /></summary>
            <section className={styles.card}>
            <h2>Така го четат родителите</h2>
            <p className={styles.hint}>{dirty ? 'Преглед на незапазените промени.' : 'Преглед на текущите условия.'}</p>
            {lines.map((line) => <p key={line.title} tabIndex={-1}><strong>{line.title}:</strong> {line.text}</p>)}
          </section>
          {example ? (
            <section className={`${styles.card} ${styles.cardExample}`}>
              <h2>Пример</h2>
              <p>{example.intro}</p>
              <ul>{example.lines.map((line) => <li key={line}>{line}</li>)}</ul>
              <p className={styles.small}>Сумите са само за пример.</p>
            </section>
          ) : null}
          </details>
          <details className={styles.preview}>
            <summary>Какво важи винаги<span className={styles.sectionSign} aria-hidden="true" /></summary>
            <section className={`${styles.card} ${styles.cardAlways}`}>
            <h2><RuleIcon name="document" />Винаги важи, каквото и да изберете</h2>
            <p>Занимание, отменено от вас, не се плаща.</p>
            <p>Записаните досега деца остават с условията, които са приели.</p>
            <p>Отказ по имейл, по телефона или от профила винаги се приема.</p>
            <p>Бележка от лекар не се качва — екипът само отбелязва, че я е видял.</p>
          </section>
          </details>
        </aside>
      </div>

      {canManage ? (
        <div className={dirty ? `${styles.saveBar} ${styles.saveBarSticky}` : styles.saveBar}>
          {saved && !dirty ? (
            <p className={styles.muted}>Няма промени за запазване. Сменете нещо по-горе и бутонът „Запази“ ще се появи.</p>
          ) : (
            <button type="button" className={styles.primary} onClick={save} disabled={saving}>
              {saving ? 'Запазване…' : <>Запази условията<span className={styles.saveKind}> за „{info.label}“</span></>}
            </button>
          )}
          {dirty ? (
            <button type="button" className={styles.secondary} disabled={saving} onClick={() => {
              setDrafts((current) => ({ ...current, [kind]: undefined }));
              setCustomPicked((current) => ({ ...current, [kind]: false }));
              setErrors({});
              setNotice(undefined);
            }}>Върни запазените</button>
          ) : null}
          {unsavedElsewhere.length ? <p className={styles.unsaved}><span className={styles.dot} aria-hidden="true" />Незапазени промени има и при: {unsavedElsewhere.join(', ')}.</p> : null}
          <span className={`${styles.muted} ${styles.saveNote}`}>Записаните досега деца остават с условията, които са приели.</span>
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
      <h1>Такси, отсъствия и отказ</h1>
      <p className={styles.lead}>Задайте правилата веднъж за всеки начин на плащане. Родителите ги виждат преди записване.</p>
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
