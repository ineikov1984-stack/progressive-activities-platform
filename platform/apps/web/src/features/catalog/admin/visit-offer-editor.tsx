'use client';

import React, { useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { AuthenticationRequiredError, loadOrganizationContext } from '../../dashboard/api';
import { ActivityDraft, ActivityGroup, CatalogApiError, loadActivity, loadGroups, loadOpenDayCampaigns, loadVisitOffers, OpenDayCampaign, saveVisitOffer, transitionVisitOffer, updateCampaignMembership, VisitOffer, VisitOfferStatus } from './api';
import { campaignMembershipChange, CampaignMembershipLockedError, cancelledCampaignMembershipMessage, changeVisitOfferGroup, formatEur, newVisitOfferDraft, parseEuroCents, VisitOfferDraft, VisitOfferErrors, visitOfferDraftFromRecord, visitOfferErrors, visitOfferErrorTarget, visitOfferPayload } from './presentation';
import { restoreVisitOfferSetupDraft, saveVisitOfferSetupDraft, visitOfferSetupKey } from './visit-offer-draft';
import admin from './activity-editor.module.css';
import styles from './visit-offer-editor.module.css';

const steps = ['Вид и съдържание', 'Група и занятия', 'Места и допустимост', 'Цена и основание', 'Срокове и отказ', 'Преглед и публикуване'];
const types = { trial: 'Пробно посещение', single: 'Еднократно посещение', open_day: 'Отворени врати' };
const statuses: Record<VisitOfferStatus, string> = { draft: 'Чернова', published: 'Публикувано', closed: 'Приключило', cancelled: 'Отменено' };
function focusError(key: string) {
  requestAnimationFrame(() => {
    const control = document.getElementById(visitOfferErrorTarget(key).controlId);
    const target = control?.tagName === 'FIELDSET' ? control.querySelector<HTMLElement>('input:not(:disabled)') ?? control : control;
    target?.focus();
  });
}
const sessionLabel = (session: ActivityGroup['sessions'][number]) => `${new Intl.DateTimeFormat('bg-BG', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Europe/Sofia' }).format(new Date(session.starts_at))} – ${new Intl.DateTimeFormat('bg-BG', { timeStyle: 'short', timeZone: 'Europe/Sofia' }).format(new Date(session.ends_at))}`;
function priceLabel(draft: VisitOfferDraft) {
  if (draft.fee_type === 'free') return 'Безплатно';
  try { return `${draft.fee_type === 'from_price' ? 'От ' : ''}${formatEur(parseEuroCents(draft.amount))}`; } catch { return 'Попълнете цената'; }
}

type FormProps = {
  draft: VisitOfferDraft; groups: ActivityGroup[]; campaigns: OpenDayCampaign[]; activityTitle: string; step: number;
  errors: VisitOfferErrors; busy: boolean; blocked: boolean; status: VisitOfferStatus;
  membershipLocked?: boolean; onSetup?: (event: React.MouseEvent<HTMLAnchorElement>) => void;
  onChange: (draft: VisitOfferDraft) => void; onStep: (step: number) => void; onSave: () => void; onPublish: () => void;
};

export function VisitOfferForm({ draft, groups, campaigns, activityTitle, step, errors, busy, blocked, status, membershipLocked, onSetup, onChange, onStep, onSave, onPublish }: FormProps) {
  const group = groups.find((item) => item.id === draft.group_id);
  const campaignLocked = membershipLocked || campaigns.some((item) => item.id === draft.campaign_id && item.status === 'cancelled');
  const set = (changes: Partial<VisitOfferDraft>) => onChange({ ...draft, ...changes });
  const error = (key: string) => errors[key] ? <span className={styles.fieldError} id={`error-${key}`}>{errors[key]}</span> : null;
  const attrs = (key: string, help?: string) => ({ id: `visit-field-${key}`, 'aria-invalid': Boolean(errors[key]), 'aria-describedby': [help, errors[key] ? `error-${key}` : ''].filter(Boolean).join(' ') || undefined });
  const numeric = (key: 'visit_limit' | 'trial_limit_per_academic_year' | 'single_visit_limit' | 'single_visit_window_days' | 'booking_deadline_hours' | 'cancellation_deadline_hours', label: string, min = 1) => <label>{label}<input type="number" min={min} step="1" value={Number.isNaN(draft[key]) ? '' : draft[key]} onChange={(event) => set({ [key]: event.target.value === '' ? NaN : Number(event.target.value) })} {...attrs(key)}/>{error(key)}</label>;
  const go = (next: number, field?: string) => { onStep(next); if (field) focusError(field); else requestAnimationFrame(() => document.getElementById(`visit-heading-${next}`)?.focus()); };
  return <>
    <nav className={`${admin.steps} ${styles.steps}`} aria-label="Стъпки на предложението">{steps.map((label, index) => <button key={label} type="button" aria-current={index === step ? 'step' : undefined} aria-controls={`visit-step-${index}`} onClick={() => go(index)}>{index + 1}. {label}</button>)}</nav>
    <fieldset className={styles.form} disabled={busy || status === 'cancelled'}>
      {steps.map((label, index) => <section key={label} id={`visit-step-${index}`} hidden={step !== index} className={admin.panel} aria-labelledby={`visit-heading-${index}`}>
        <p className={admin.eyebrow}>Стъпка {index + 1} от 6</p><h2 id={`visit-heading-${index}`} tabIndex={-1}>{label}</h2>
        {index === 0 && <><div className={admin.sectionTitle}><p>Опишете конкретна възможност за посещение на „{activityTitle}“.</p><button type="button" disabled aria-describedby="ai-help">Генерирай с AI</button></div><p id="ai-help" className={admin.help}>AI помощта още не е включена. Попълнете съдържанието ръчно.</p><div className={admin.formGrid}>
          <label>Вид посещение<select value={draft.type} onChange={(event) => set({ type: event.target.value as VisitOfferDraft['type'] })} {...attrs('type')}>{Object.entries(types).map(([value, text]) => <option value={value} key={value}>{text}</option>)}</select>{error('type')}</label>
          <label>Публично заглавие<input value={draft.title} maxLength={255} onChange={(event) => set({ title: event.target.value })} {...attrs('title')}/>{error('title')}</label>
          <label className={admin.full}>Описание (по желание)<textarea value={draft.description ?? ''} onChange={(event) => set({ description: event.target.value })} {...attrs('description', 'description-help')}/><small id="description-help">Например: Децата ще изпробват работа с глина и ще отнесат първата си творба у дома.</small>{error('description')}</label>
          <label>Подходяща възраст (по желание)<input value={draft.audience_age_label ?? ''} maxLength={100} onChange={(event) => set({ audience_age_label: event.target.value })} {...attrs('audience_age_label', 'age-help')}/><small id="age-help">Например: 6–8 години.</small>{error('audience_age_label')}</label>
          <label>Подходящ клас (по желание)<input value={draft.audience_class_label ?? ''} maxLength={100} onChange={(event) => set({ audience_class_label: event.target.value })} {...attrs('audience_class_label', 'class-help')}/><small id="class-help">Например: I–II клас. Оставете празно, ако не е приложимо.</small>{error('audience_class_label')}</label>
          <label className={admin.full}>Кампания „Отворени врати“ (по желание)<select value={draft.campaign_id} onChange={(event) => set({ campaign_id: event.target.value })} disabled={campaignLocked} {...attrs('campaign_id', campaignLocked ? 'campaign-locked' : undefined)}><option value="">Самостоятелно предложение</option>{campaigns.map((campaign) => <option key={campaign.id} value={campaign.id} disabled={campaign.status === 'closed' || campaign.status === 'cancelled'}>{campaign.title} · {statuses[campaign.status]}{campaign.status !== 'published' ? ' (не е публична)' : ''}</option>)}</select>{campaignLocked && <small id="campaign-locked">{cancelledCampaignMembershipMessage}</small>}<small>Например: „Есенни отворени врати“. Запазването на предложението не публикува кампанията.{!campaigns.length ? ' Все още няма създадени кампании.' : ''}</small>{error('campaign_id')}</label>
        </div></>}
        {index === 1 && <><p>Часовете са по българско време.</p>{!groups.length ? <p className={admin.help}>Няма създадени групи. <Link href={`/dashboard/activities/${draft.activity_id}#schedule`} onClick={onSetup}>Добавете група и график в редактора на дейността.</Link> Въведеното предложение ще ви очаква при връщане в този раздел.</p> : null}<div className={admin.formGrid}><label>Дейност<input value={activityTitle} readOnly {...attrs('activity_id')}/>{error('activity_id')}</label><label>Група<select value={draft.group_id} onChange={(event) => onChange(changeVisitOfferGroup(draft, event.target.value))} {...attrs('group_id')}><option value="">Изберете група</option>{groups.map((item) => <option key={item.id} value={item.id}>{item.name} · {item.age_label}</option>)}</select>{error('group_id')}</label></div><fieldset className={styles.sessions} tabIndex={-1} {...attrs('session_ids')}><legend>Конкретни занятия</legend>{group?.sessions.length ? group.sessions.map((session) => { const available = session.status === 'scheduled' && Date.parse(session.starts_at) > Date.now(); const checked = draft.session_ids.includes(session.id); return <label key={session.id} className={styles.check}><input type="checkbox" checked={checked} disabled={!available && !checked} onChange={(event) => set({ session_ids: event.target.checked ? [...draft.session_ids, session.id] : draft.session_ids.filter((id) => id !== session.id) })}/><span>{sessionLabel(session)}{!available ? ' · минало или отменено — не може да се публикува' : ''}</span></label>; }) : <p>{group ? 'Групата няма занятия. Добавете график от редактора на дейността.' : 'Първо изберете група, за да видите действителните ѝ занятия.'}</p>}{error('session_ids')}</fieldset>{group?.conflicts.map((conflict, i) => <p key={i} className={conflict.severity === 'blocking' ? admin.error : admin.help}>{conflict.severity === 'blocking' ? 'Блокира публикуването: ' : 'Проверете: '}{conflict.message}</p>)}</>}
        {index === 2 && <><div className={admin.formGrid}>{numeric('visit_limit', 'Максимум места за това посещение на занятие')}<p className={admin.help}>Общият капацитет на групата{group ? ` е ${group.capacity_max}` : ''}. Незаетите места за посещения остават достъпни за редовни записвания.</p>
          {(draft.type === 'trial' || errors.trial_limit_per_academic_year || errors.no_show_trial_limit) && <>{numeric('trial_limit_per_academic_year', 'Пробни посещения на дете за учебна година')}<label>Ограничение след неявявания (по желание)<input type="number" min="1" step="1" value={draft.no_show_trial_limit ?? ''} onChange={(event) => set({ no_show_trial_limit: event.target.value === '' ? null : Number(event.target.value) })} {...attrs('no_show_trial_limit')}/><small>Например: след 2 неявявания. Празното поле не добавя ограничение.</small>{error('no_show_trial_limit')}</label><p className={admin.help}>По подразбиране: едно пробно посещение на дете за тази дейност в учебната година.</p></>}
          {(draft.type === 'single' || errors.unlimited_single_visits || errors.single_visit_limit || errors.single_visit_window_days) && <><label className={`${styles.check} ${admin.full}`}><input type="checkbox" checked={draft.unlimited_single_visits} onChange={(event) => set({ unlimited_single_visits: event.target.checked })} {...attrs('unlimited_single_visits')}/>Разреши неограничен брой еднократни посещения{error('unlimited_single_visits')}</label>{(!draft.unlimited_single_visits || errors.single_visit_limit || errors.single_visit_window_days) && <>{numeric('single_visit_limit', 'Максимум посещения на дете')}{numeric('single_visit_window_days', 'В рамките на колко дни')}</>}<p className={admin.help}>По подразбиране: до 2 посещения за 30 дни на дете за тази дейност.</p></>}
          {draft.type === 'open_day' && <p className={admin.help}>Посещенията за отворени врати следват избраните занятия и свободните места. Лимитите за пробни и еднократни посещения не се прилагат.</p>}
          <label className={`${styles.check} ${admin.full}`}><input type="checkbox" checked={draft.waitlist_enabled} onChange={(event) => set({ waitlist_enabled: event.target.checked })} {...attrs('waitlist_enabled')}/>Разреши списък на чакащи при запълване{error('waitlist_enabled')}</label>
        </div></>}
        {index === 3 && <div className={admin.formGrid}>
          <label>Ценови модел<select value={draft.fee_type} onChange={(event) => set({ fee_type: event.target.value as VisitOfferDraft['fee_type'] })} {...attrs('fee_type')}><option value="free">Безплатно</option><option value="paid">Такса за посещение</option><option value="from_price">Цена „От…“</option></select>{error('fee_type')}</label>
          <label>Валута<input readOnly value="EUR — евро" {...attrs('currency')}/>{error('currency')}</label>
          {(draft.fee_type !== 'free' || errors.amount_cents) && <label>Цена в евро<input inputMode="decimal" value={draft.fee_type === 'free' ? '0.00' : draft.amount} readOnly={draft.fee_type === 'free'} onChange={(event) => set({ amount: event.target.value })} {...attrs('amount_cents')}/><small>{draft.fee_type === 'free' ? 'Безплатният модел е 0 евро. За платена цена сменете ценовия модел.' : 'Например: 12,50. Най-много два знака след запетаята.'}</small>{error('amount_cents')}</label>}
          {(draft.fee_type !== 'free' || errors.fee_reason) && <label className={admin.full}>Публично основание за таксата<textarea value={draft.fee_reason ?? ''} onChange={(event) => set({ fee_reason: event.target.value })} {...attrs('fee_reason')}/><small>Например: Включва материали, екипировка или входна такса. Родителят ще вижда това обяснение.</small>{error('fee_reason')}</label>}
          {draft.fee_type === 'from_price' && <p className={admin.help}>Използвайте „От…“ само когато крайната цена зависи от избрано предложение или допълнение. Опишете от какво зависи в основанието.</p>}<p className={admin.pricePreview}>{priceLabel(draft)}</p>
        </div>}
        {index === 4 && <div className={admin.formGrid}>
          {numeric('booking_deadline_hours', 'Записване до колко часа преди началото', 0)}{numeric('cancellation_deadline_hours', 'Отказ до колко часа преди началото', 0)}
          <p className={`${admin.help} ${admin.full}`}>По подразбиране: записване до 12 часа и отказ до 24 часа преди началото.</p>
          <label className={admin.full}>Публични правила за отказ (по желание)<textarea value={draft.cancellation_policy ?? ''} onChange={(event) => set({ cancellation_policy: event.target.value })} {...attrs('cancellation_policy', 'policy-help')}/><small id="policy-help">Например: При отказ до 24 часа преди началото предложете нова дата; след срока таксата не се възстановява.</small>{error('cancellation_policy')}</label>
          <label>Финансово условие при отказ<select value={draft.cancellation_outcome} onChange={(event) => set({ cancellation_outcome: event.target.value as VisitOfferDraft['cancellation_outcome'] })} {...attrs('cancellation_outcome')}><option value="non_refundable">Без възстановяване</option><option value="refund">Възстановяване на сумата</option><option value="credit">Кредит за бъдещо посещение</option></select><small>Условието се записва. Системата не извършва автоматични плащания или възстановявания.</small>{error('cancellation_outcome')}</label>
          <label>Издание на условията<input value={draft.terms_version ?? ''} maxLength={100} onChange={(event) => set({ terms_version: event.target.value })} {...attrs('terms_version')}/><small>Например: 2026-есен-1. Задължително при публикуване; сменете го при нови условия.</small>{error('terms_version')}</label>
        </div>}
        {index === 5 && <><article className={styles.preview}><p>{types[draft.type]} · {statuses[status]}</p><h3>{draft.title || 'Заглавието още не е попълнено'}</h3><p>{draft.description}</p><p>{[draft.audience_age_label, draft.audience_class_label].filter(Boolean).join(' · ')}</p><p>{activityTitle} · {group?.name || 'Няма избрана група'}</p><ul>{group?.sessions.filter((session) => draft.session_ids.includes(session.id)).map((session) => <li key={session.id}>{sessionLabel(session)}</li>)}</ul><p className={admin.pricePreview}>{priceLabel(draft)}</p>{draft.fee_type !== 'free' && <p>{draft.fee_reason}</p>}<p>До {draft.visit_limit} места на занятие · {draft.waitlist_enabled ? 'Списък на чакащи' : 'Без списък на чакащи'}</p><p>{draft.type === 'trial' ? `До ${draft.trial_limit_per_academic_year} пробни посещения за учебна година.` : draft.type === 'single' ? draft.unlimited_single_visits ? 'Без ограничение на броя еднократни посещения.' : `До ${draft.single_visit_limit} посещения за ${draft.single_visit_window_days} дни.` : 'Отворени врати за избраните занятия.'}</p><p>Записване до {draft.booking_deadline_hours} часа; отказ до {draft.cancellation_deadline_hours} часа преди началото.</p><p>{draft.cancellation_policy}</p><p>{({ refund: 'Възстановяване на сумата', credit: 'Кредит за бъдещо посещение', non_refundable: 'Без възстановяване' })[draft.cancellation_outcome]}</p>{draft.campaign_id && <p>Кампания: {campaigns.find((item) => item.id === draft.campaign_id)?.title} · {statuses[campaigns.find((item) => item.id === draft.campaign_id)?.status ?? 'draft']}</p>}</article><p className={admin.help}>Това е преглед на съдържанието. Свободните места се изчисляват при записване.</p>{Object.keys(errors).length > 0 && <div className={admin.error} role="alert"><strong>Поправете следното преди публикуване:</strong><ul>{Object.entries(errors).map(([key, message]) => <li key={key}><button type="button" className={styles.errorLink} onClick={() => go(visitOfferErrorTarget(key).step, key)}>{message}</button></li>)}</ul></div>}<button type="button" disabled={busy || blocked || status !== 'draft' || Object.keys(errors).length > 0} onClick={onPublish}>Публикувай предложението</button>{status !== 'draft' && <p className={admin.help}>Статус: {statuses[status]}. Повторно публикуване не е достъпно.</p>}</>}
      </section>)}
      <footer className={styles.actions}><button type="button" className={admin.secondaryButton} disabled={step === 0} onClick={() => go(step - 1)}>Назад</button><button type="button" disabled={busy || blocked} onClick={onSave}>{busy ? 'Запазване…' : status === 'draft' ? 'Запази черновата' : 'Запази промените'}</button>{step < 5 && <button type="button" onClick={() => go(step + 1)}>Напред</button>}<p>Запазете преди да напуснете страницата. Запазените данни остават и след презареждане.</p></footer>
    </fieldset>
  </>;
}

export function VisitOfferEditor({ activityId }: { activityId: string }) {
  const router = useRouter();
  const [organizationId, setOrganizationId] = useState('');
  const [setupKey, setSetupKey] = useState('');
  const [activity, setActivity] = useState<ActivityDraft>();
  const [groups, setGroups] = useState<ActivityGroup[]>([]);
  const [offers, setOffers] = useState<VisitOffer[]>([]);
  const [campaigns, setCampaigns] = useState<OpenDayCampaign[]>([]);
  const [saved, setSaved] = useState<VisitOffer>();
  const [draft, setDraft] = useState(() => newVisitOfferDraft(activityId));
  const [step, setStep] = useState(0);
  const [dirty, setDirty] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [errors, setErrors] = useState<VisitOfferErrors>({});
  const [notice, setNotice] = useState('');
  const [blocked, setBlocked] = useState(false);
  const [latest, setLatest] = useState<VisitOffer>();
  const [attempt, setAttempt] = useState(0);
  const [validationAt, setValidationAt] = useState(Date.now);

  useEffect(() => {
    let active = true;
    loadOrganizationContext().then(async (context) => {
      const org = context.organization.id;
      const [loadedActivity, loadedGroups, loadedOffers, loadedCampaigns] = await Promise.all([loadActivity(org, activityId), loadGroups(org, activityId), loadVisitOffers(org), loadOpenDayCampaigns(org)]);
      if (!active) return;
      setOrganizationId(org); setActivity(loadedActivity); setGroups(loadedGroups); setOffers(loadedOffers.filter((offer) => offer.activity_id === activityId)); setCampaigns(loadedCampaigns);
      const key = visitOfferSetupKey(context.user.id, org, activityId); setSetupKey(key);
      const id = new URLSearchParams(window.location.search).get('offer');
      let restored = null;
      try { restored = restoreVisitOfferSetupDraft(window.sessionStorage, key, activityId); } catch { /* Storage may be unavailable; ordinary server loading still works. */ }
      if (id && restored?.offerId !== id) restored = null;
      const current = loadedOffers.find((offer) => offer.id === (id ?? restored?.offerId) && offer.activity_id === activityId);
      setSaved(current);
      if (restored) {
        setDraft(restored.draft); setStep(restored.step); setDirty(true);
        setNotice('Възстановихме незавършеното предложение след настройката на групата. Запазете го, когато сте готови.');
        if (restored.offerId && (!current || current.version !== restored.offerVersion)) {
          setBlocked(true); setLatest(current); setError('Предложението е променено по време на настройката. Вашите въведени данни са възстановени. Прегледайте актуалното предложение преди запазване.');
        }
        if (current) window.history.replaceState(null, '', `${window.location.pathname}?offer=${encodeURIComponent(current.id)}`);
      } else setDraft(current ? visitOfferDraftFromRecord(current) : { ...newVisitOfferDraft(activityId), audience_age_label: loadedActivity.age_label });
      if (id && !current) setError('Предложението не е намерено в тази дейност. Можете да създадете ново.');
    }).catch((reason: unknown) => { if (!active) return; if (reason instanceof AuthenticationRequiredError) router.replace('/login'); else setError('Редакторът не беше зареден. Опитайте отново.'); });
    return () => { active = false; };
  }, [activityId, router, attempt]);

  useEffect(() => {
    const warn = (event: BeforeUnloadEvent) => { if (dirty) event.preventDefault(); };
    window.addEventListener('beforeunload', warn); return () => window.removeEventListener('beforeunload', warn);
  }, [dirty]);

  function openGroupSetup(event: React.MouseEvent<HTMLAnchorElement>) {
    try {
      saveVisitOfferSetupDraft(window.sessionStorage, setupKey, { draft, step, ...(saved ? { offerId: saved.id, offerVersion: saved.version } : {}) });
    } catch {
      event.preventDefault();
      setError('Не успяхме да запазим временно въведеното в този браузър. Останахте в редактора, за да не загубите текста.');
    }
  }

  function keepRecord(record: VisitOffer) {
    setSaved(record); setOffers((current) => current.some((item) => item.id === record.id) ? current.map((item) => item.id === record.id ? record : item) : [record, ...current]);
    window.history.replaceState(null, '', `${window.location.pathname}?offer=${encodeURIComponent(record.id)}`);
  }
  function selectOffer(id: string) {
    if (dirty && !window.confirm('Имате незапазени промени. Да отворим друго предложение без да ги запазим?')) return;
    const record = offers.find((item) => item.id === id); setSaved(record); setDraft(record ? visitOfferDraftFromRecord(record) : { ...newVisitOfferDraft(activityId), audience_age_label: activity?.age_label ?? '' });
    setStep(0); setDirty(false); setError(''); setErrors({}); setNotice(''); setBlocked(false); setLatest(undefined);
    window.history.replaceState(null, '', `${window.location.pathname}${id ? `?offer=${encodeURIComponent(id)}` : ''}`);
  }
  async function refreshConflict() {
    setBusy(true);
    try {
      const [freshOffers, freshGroups, freshCampaigns] = await Promise.all([loadVisitOffers(organizationId), loadGroups(organizationId, activityId), loadOpenDayCampaigns(organizationId)]);
      setOffers(freshOffers.filter((item) => item.activity_id === activityId)); setGroups(freshGroups); setCampaigns(freshCampaigns);
      setLatest(freshOffers.find((item) => item.id === saved?.id)); setNotice('Актуалните данни са заредени. Въведеното във формата е запазено до вашия избор.');
    } catch { setError('Актуалните данни не бяха заредени. Опитайте отново.'); } finally { setBusy(false); }
  }
  async function persist(publish: boolean) {
    const validation = visitOfferErrors(draft, groups, publish || saved?.status === 'published', Date.now(), saved?.status === 'published' ? saved.sessions.map((session) => session.id) : []);
    if (saved && saved.campaign_id !== (draft.campaign_id || null) && campaigns.some((item) => item.id === saved.campaign_id && item.status === 'cancelled')) validation.campaign_id = cancelledCampaignMembershipMessage;
    setErrors(validation); setError(''); setNotice('');
    if (Object.keys(validation).length) { const first = Object.keys(validation)[0]; setStep(publish ? 5 : visitOfferErrorTarget(first).step); if (!publish) focusError(first); return; }
    setBusy(true);
    let record: VisitOffer | undefined;
    try {
      record = await saveVisitOffer(organizationId, visitOfferPayload(draft, saved?.version), saved?.id); keepRecord(record);
      const membership = campaignMembershipChange(record, draft.campaign_id, campaigns);
      if (membership) {
        await updateCampaignMembership(organizationId, membership.id, membership.body);
        const [freshOffers, freshCampaigns] = await Promise.all([loadVisitOffers(organizationId), loadOpenDayCampaigns(organizationId)]);
        const fresh = freshOffers.find((item) => item.id === record?.id);
        if (!fresh) throw new Error('Предложението не беше намерено след запазването.');
        record = fresh; keepRecord(record); setOffers(freshOffers.filter((item) => item.activity_id === activityId)); setCampaigns(freshCampaigns);
      }
      if (publish) { record = await transitionVisitOffer(organizationId, record.id, record.version, 'publish'); keepRecord(record); }
      setDraft(visitOfferDraftFromRecord(record)); setDirty(false); setNotice(publish ? 'Предложението е публикувано.' : 'Предложението е запазено.');
    } catch (reason) {
      if (reason instanceof CatalogApiError && reason.status === 422) {
        const fields = Object.fromEntries(Object.entries(reason.fieldErrors).map(([key, messages]) => [key.startsWith('session_ids') ? 'session_ids' : key, messages.join(' ')]));
        const first = Object.keys(fields)[0]; setErrors(fields); setStep(publish ? 5 : visitOfferErrorTarget(first).step); if (!publish && first) focusError(first);
        setError(record ? 'Данните са запазени, но последната стъпка не е завършена. Проверете съобщенията по-долу.' : 'Проверете отбелязаните полета. Предложението не е запазено.');
      } else if (reason instanceof CampaignMembershipLockedError) {
        setErrors({ campaign_id: reason.message }); setStep(0); setError(reason.message);
      } else if (reason instanceof CatalogApiError && reason.status === 409) {
        setBlocked(true); setError('Предложението или кампанията са променени от друг служител. Вашият текст е запазен във формата. Заредете актуалните данни преди нов опит.');
      } else {
        if (record) setBlocked(true);
        setError(reason instanceof CatalogApiError && reason.status === 403 ? 'Нямате право за това действие. Обърнете се към администратор на организацията.' : 'Действието не завърши. Проверете връзката и заредете актуалните данни преди нов опит.');
      }
    } finally { setBusy(false); }
  }
  async function endOffer(transition: 'close' | 'cancel') {
    if (!saved || dirty) return;
    const consequence = 'Новите заявки спират. Потвърдените посещения, заявленията и историята се запазват. Няма автоматични плащания или възстановявания.';
    if (!window.confirm(`${transition === 'close' ? 'Да приключим' : 'Да отменим'} предложението? ${consequence}${transition === 'cancel' ? ' След отмяна редакцията се заключва.' : ''}`)) return;
    setBusy(true); setError(''); setNotice('');
    try {
      const record = await transitionVisitOffer(organizationId, saved.id, saved.version, transition);
      keepRecord(record); setDraft(visitOfferDraftFromRecord(record)); setErrors({});
      setNotice(transition === 'close' ? 'Предложението е приключено.' : 'Предложението е отменено.');
    } catch (reason) {
      if (reason instanceof CatalogApiError && reason.status === 403) setError('Нямате право за това действие. Обърнете се към администратор на организацията.');
      else { setBlocked(true); setError('Предложението е променено или действието не завърши. Заредете актуалните данни преди нов опит.'); }
    } finally { setBusy(false); }
  }
  if (!activity) return <main className={admin.page}><Link href={`/dashboard/activities/${activityId}`}>← Редактор на дейността</Link><p role={error ? 'alert' : 'status'}>{error || 'Зареждане на предложенията…'}</p>{error && <button type="button" onClick={() => { setError(''); setAttempt((value) => value + 1); }}>Опитай отново</button>}</main>;
  const shownErrors = step === 5 ? { ...visitOfferErrors(draft, groups, true, validationAt, saved?.status === 'published' ? saved.sessions.map((session) => session.id) : []), ...errors } : errors;
  return <main className={`${admin.page} ${styles.page}`}>
    <header className={admin.pageHeader}><div><Link href={`/dashboard/activities/${activityId}`} onClick={(event) => { if (dirty && !window.confirm('Имате незапазени промени. Да напуснем без запазване?')) event.preventDefault(); }}>← Редактор на дейността</Link><p className={admin.eyebrow}>Предложения за посещение</p><h1>{activity.title}</h1><p>Пробни посещения, еднократни посещения и отворени врати</p></div></header>
    <section className={admin.panel}><label className={styles.selector}>Предложение за редакция<select value={saved?.id ?? ''} onChange={(event) => selectOffer(event.target.value)} disabled={busy}><option value="">Ново предложение</option>{offers.map((offer) => <option value={offer.id} key={offer.id}>{offer.title} · {statuses[offer.status]}</option>)}</select></label>{!offers.length && <p className={admin.help}>Още няма предложения за тази дейност. Създайте първото в шестте стъпки по-долу.</p>}</section>
    {notice && <p className={admin.notice} role="status">{notice}</p>}{error && <p className={admin.error} role="alert">{error}</p>}
    {blocked && <section className={admin.panel}><button type="button" disabled={busy} onClick={refreshConflict}>Зареди актуалните данни</button>{latest && <><h2>Последно запазено предложение</h2><p>{latest.title} · {statuses[latest.status]} · {priceLabel(visitOfferDraftFromRecord(latest))}</p><p>{latest.description}</p><p>{latest.sessions.map(sessionLabel).join('; ')}</p><button type="button" onClick={() => { keepRecord(latest); setDraft(visitOfferDraftFromRecord(latest)); setDirty(false); setBlocked(false); setError(''); setErrors({}); setLatest(undefined); }}>Замени формата с актуалното предложение</button><p className={admin.help}>Това заменя вашите незапазени промени с показаните данни от сървъра.</p></>}</section>}
    <section className={admin.panel}><Link href="/dashboard/open-days" onClick={(event) => { if (dirty && !window.confirm('Имате незапазени промени. Да отворим кампаниите без запазване?')) event.preventDefault(); }}>Създай или управлявай кампании „Отворени врати“</Link>
      {saved && <><h2>Статус на предложението: {statuses[saved.status]}</h2><p>При приключване или отмяна новите заявки спират. Потвърдените посещения, заявленията и историята се запазват. Няма автоматични плащания или възстановявания. Приключено предложение не се публикува повторно; отмененото е заключено за редакция.</p>
        <div className={admin.headerActions}>{saved.status === 'published' && <button type="button" disabled={busy || blocked || dirty} onClick={() => endOffer('close')}>Приключи предложението</button>}{saved.status !== 'cancelled' && <button type="button" className={admin.dangerButton} disabled={busy || blocked || dirty} onClick={() => endOffer('cancel')}>Отмени предложението</button>}</div>{dirty && <p>Запазете промените преди приключване или отмяна.</p>}
      </>}
    </section>
    <VisitOfferForm draft={draft} groups={groups} campaigns={campaigns} activityTitle={activity.title} step={step} errors={shownErrors} busy={busy} blocked={blocked} status={saved?.status ?? 'draft'} membershipLocked={campaigns.some((item) => item.id === saved?.campaign_id && item.status === 'cancelled')} onSetup={openGroupSetup} onChange={(value) => { setDraft(value); setDirty(true); setErrors({}); setNotice(''); }} onStep={(value) => { setValidationAt(Date.now()); setStep(value); }} onSave={() => persist(false)} onPublish={() => persist(true)}/>
  </main>;
}
