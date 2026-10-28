'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { AuthenticationRequiredError, loadOrganizationContext } from '../../dashboard/api';
import { CatalogApiError, loadOpenDayCampaigns, loadVisitOffers, saveOpenDayCampaign, transitionOpenDayCampaign, type OpenDayCampaign, type VisitOffer, type VisitOfferStatus } from './api';
import styles from './activity-editor.module.css';

const statuses: Record<VisitOfferStatus, string> = { draft: 'Чернова', published: 'Публикувана', closed: 'Приключена', cancelled: 'Отменена' };
const blank = () => ({ title: '', slug: '', description: '', starts_on: '', ends_on: '', visit_offer_ids: [] as string[] });
const consequence = 'Публичната страница и етикетът на кампанията се скриват. Предложенията остават достъпни самостоятелно, докато не ги приключите или отмените отделно. Потвърдените посещения се запазват. Заявленията и историята не се изтриват; плащания и възстановявания не се извършват.';

export function OpenDayCampaignEditor() {
  const router = useRouter();
  const [organizationId, setOrganizationId] = useState('');
  const [organizationSlug, setOrganizationSlug] = useState('');
  const [permissions, setPermissions] = useState<string[]>([]);
  const [campaigns, setCampaigns] = useState<OpenDayCampaign[]>();
  const [offers, setOffers] = useState<VisitOffer[]>([]);
  const [saved, setSaved] = useState<OpenDayCampaign>();
  const [draft, setDraft] = useState(blank);
  const [busy, setBusy] = useState(false);
  const [dirty, setDirty] = useState(false);
  const [blocked, setBlocked] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let active = true;
    loadOrganizationContext().then(async ({ organization }) => {
      const [loadedCampaigns, loadedOffers] = await Promise.all([loadOpenDayCampaigns(organization.id), loadVisitOffers(organization.id)]);
      if (!active) return;
      setOrganizationId(organization.id); setOrganizationSlug(organization.slug); setPermissions(organization.permissions);
      setCampaigns(loadedCampaigns); setOffers(loadedOffers);
    }).catch((reason: unknown) => {
      if (!active) return;
      if (reason instanceof AuthenticationRequiredError) router.replace('/login');
      else setError('Кампаниите не бяха заредени. Опитайте отново.');
    });
    return () => { active = false; };
  }, [router, attempt]);

  useEffect(() => {
    const warn = (event: BeforeUnloadEvent) => { if (dirty) event.preventDefault(); };
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [dirty]);

  function applyRecord(record?: OpenDayCampaign) {
    setSaved(record);
    setDraft(record ? { title: record.title, slug: record.slug, description: record.description ?? '', starts_on: record.starts_on ?? '', ends_on: record.ends_on ?? '', visit_offer_ids: record.visit_offers.map((offer) => offer.id) } : blank());
    setDirty(false); setBlocked(false); setError(''); setNotice('');
  }
  function select(id: string) {
    if (dirty && !window.confirm('Имате незапазени промени. Да отворим друга кампания без запазване?')) return;
    applyRecord(campaigns?.find((campaign) => campaign.id === id));
  }
  function change(values: Partial<typeof draft>) { setDraft({ ...draft, ...values }); setDirty(true); setError(''); setNotice(''); }
  function handleError(reason: unknown) {
    if (reason instanceof CatalogApiError && reason.status === 422) setError(Object.values(reason.fieldErrors).flat().join(' ') || reason.message);
    else if (reason instanceof CatalogApiError && reason.status === 409) {
      setBlocked(true); setError('Кампанията или предложение са променени междувременно. Въведеният текст е запазен във формата. Заредете актуалната кампания преди нов опит.');
    } else if (reason instanceof CatalogApiError && reason.status === 403) setError('Нямате право за това действие. Обърнете се към администратор.');
    else { setBlocked(true); setError('Действието не завърши. Заредете актуалната кампания, за да проверите запазеното преди нов опит.'); }
  }
  async function refresh() {
    setBusy(true);
    try {
      const [freshCampaigns, freshOffers] = await Promise.all([loadOpenDayCampaigns(organizationId), loadVisitOffers(organizationId)]);
      setCampaigns(freshCampaigns); setOffers(freshOffers); applyRecord(freshCampaigns.find((campaign) => campaign.id === saved?.id));
    } catch { setError('Актуалните данни не бяха заредени. Опитайте отново.'); } finally { setBusy(false); }
  }
  async function save() {
    if (!draft.title.trim()) { setError('Въведете заглавие на кампанията.'); document.getElementById('campaign-title')?.focus(); return; }
    if (draft.starts_on && draft.ends_on && draft.ends_on < draft.starts_on) { setError('Крайната дата трябва да е след началната.'); return; }
    setBusy(true); setError(''); setNotice('');
    try {
      const record = await saveOpenDayCampaign(organizationId, {
        title: draft.title.trim(), ...(draft.slug.trim() ? { slug: draft.slug.trim() } : {}), description: draft.description.trim() || null,
        starts_on: draft.starts_on || null, ends_on: draft.ends_on || null, visit_offer_ids: draft.visit_offer_ids,
        visit_offer_versions: Object.fromEntries(offers.map((offer) => [offer.id, offer.version])),
        source_campaign_versions: Object.fromEntries((campaigns ?? []).map((campaign) => [campaign.id, campaign.version])),
        ...(saved ? { version: saved.version } : {}),
      }, saved?.id);
      setSaved(record);
      const [freshCampaigns, freshOffers] = await Promise.all([loadOpenDayCampaigns(organizationId), loadVisitOffers(organizationId)]);
      setCampaigns(freshCampaigns); setOffers(freshOffers); applyRecord(record); setNotice('Кампанията е запазена.');
    } catch (reason) { handleError(reason); } finally { setBusy(false); }
  }
  async function transition(action: 'publish' | 'close' | 'cancel') {
    if (!saved) return;
    if (action !== 'publish' && !window.confirm(`${action === 'close' ? 'Да приключим' : 'Да отменим'} кампанията? ${consequence}${action === 'cancel' ? ' След отмяна съдържанието и членството на предложенията се заключват.' : ''}`)) return;
    setBusy(true); setError(''); setNotice('');
    try {
      const record = await transitionOpenDayCampaign(organizationId, saved.id, saved.version, action);
      setCampaigns((current) => current?.map((campaign) => campaign.id === record.id ? record : campaign));
      applyRecord(record); setNotice(action === 'publish' ? 'Кампанията е публикувана.' : action === 'close' ? 'Кампанията е приключена.' : 'Кампанията е отменена.');
    } catch (reason) { handleError(reason); } finally { setBusy(false); }
  }
  const canManage = permissions.includes('catalog.manage');
  const canPublish = permissions.includes('catalog.publish');
  const readOnly = !canManage || saved?.status === 'cancelled';
  const guardNavigation = (event: React.MouseEvent<HTMLAnchorElement>) => { if (dirty && !window.confirm('Имате незапазени промени. Да напуснем без запазване?')) event.preventDefault(); };

  return <main className={styles.page}>
    <header className={styles.pageHeader}><div><p className={styles.eyebrow}>Каталог на организацията</p><h1>Кампании „Отворени врати“</h1><p>Обединете предложенията за посещение в обща публична страница.</p></div><Link href="/dashboard/activities" onClick={guardNavigation}>Към дейностите</Link></header>
    {error && <p className={styles.error} role="alert">{error}</p>}{notice && <p className={styles.notice} role="status">{notice}</p>}
    {campaigns === undefined ? <section className={styles.panel}><p>Зареждане на кампаниите…</p>{error && <button onClick={() => { setError(''); setAttempt((value) => value + 1); }}>Опитай отново</button>}</section> : <>
      <section className={styles.panel}><label className={styles.stack}>Кампания за редакция<select disabled={busy} value={saved?.id ?? ''} onChange={(event) => select(event.target.value)}><option value="">Нова кампания</option>{campaigns.map((campaign) => <option key={campaign.id} value={campaign.id}>{campaign.title} · {statuses[campaign.status]}</option>)}</select></label>{!campaigns.length && <p>Все още няма кампании. Създайте първата по-долу.</p>}</section>
      {blocked && <section className={styles.panel}><p>Зареждането заменя въведеното със запазените данни. Копирайте текста си, ако искате да го запазите.</p><button disabled={busy} onClick={refresh}>Зареди актуалната кампания</button></section>}
      <section className={styles.panel} aria-label="Редактор на кампания"><h2>{saved ? `${saved.title} · ${statuses[saved.status]}` : 'Нова кампания'}</h2>
        {!canManage && <p>Имате достъп за преглед. Редакцията изисква право за управление на каталога.</p>}
        {saved?.status === 'cancelled' && <p>Отменената кампания е заключена. Нейните предложения не могат да се преместват или отделят.</p>}
        <fieldset className={styles.stack} disabled={busy || blocked || readOnly}>
          <legend>Съдържание и предложения</legend>
          <div className={styles.formGrid}>
            <label htmlFor="campaign-title">Заглавие на кампанията<input id="campaign-title" maxLength={255} value={draft.title} onChange={(event) => change({ title: event.target.value })}/></label>
            <label>Адрес на кампанията (по желание)<input maxLength={255} value={draft.slug} onChange={(event) => change({ slug: event.target.value })}/><small>Например: esenni-otvoreni-vrati. При създаване празното поле получава адрес от заглавието.</small></label>
            <label className={styles.full}>Описание на кампанията<textarea value={draft.description} onChange={(event) => change({ description: event.target.value })}/><small>Например: Запознайте се с преподавателите и изберете занимание за детето.</small></label>
            <label>Начална дата<input type="date" value={draft.starts_on} onChange={(event) => change({ starts_on: event.target.value })}/></label>
            <label>Крайна дата<input type="date" value={draft.ends_on} onChange={(event) => change({ ends_on: event.target.value })}/></label>
          </div>
          <p className={styles.help}>Преди публикуване задайте период и добавете поне едно публикувано предложение. Часовете и цените идват от отделните предложения.</p>
          <fieldset className={styles.stack}><legend>Предложения в кампанията</legend>
            {!offers.length && <p>Няма предложения. <Link href="/dashboard/activities" onClick={guardNavigation}>Създайте предложение от редактора на дейността.</Link></p>}
            {offers.map((offer) => {
              const source = campaigns.find((campaign) => campaign.id === offer.campaign_id);
              return <label className={styles.check} key={offer.id}><input type="checkbox" checked={draft.visit_offer_ids.includes(offer.id)} disabled={source?.status === 'cancelled'} onChange={(event) => change({ visit_offer_ids: event.target.checked ? [...draft.visit_offer_ids, offer.id] : draft.visit_offer_ids.filter((id) => id !== offer.id) })}/>{offer.title} · {statuses[offer.status]}{source && source.id !== saved?.id ? ` · в „${source.title}“${source.status === 'cancelled' ? ' (заключена)' : ''}` : ''}</label>;
            })}
          </fieldset>
          <p className={styles.help}>Преместването в тази кампания премахва предложението от предишната. Изваждането от кампания запазва самостоятелното предложение и всички записвания.</p>
          <div className={styles.headerActions}><button onClick={save}>{saved ? 'Запази кампанията' : 'Създай кампания'}</button>
            {saved?.status === 'draft' && <button disabled={dirty || !canPublish} onClick={() => transition('publish')}>Публикувай кампанията</button>}
            {saved?.status === 'published' && <button disabled={dirty} onClick={() => transition('close')}>Приключи кампанията</button>}
            {saved && saved.status !== 'cancelled' && <button className={styles.dangerButton} disabled={dirty} onClick={() => transition('cancel')}>Отмени кампанията</button>}
          </div>
        </fieldset>
        {dirty && saved && <p>Запазете промените преди публикуване, приключване или отмяна.</p>}
        <p>{consequence}</p><p className={styles.help}>Приключена кампания не се публикува повторно. Отмяната заключва редакцията и членството на предложенията.</p>
        {saved?.status === 'published' && <Link href={`/o/${organizationSlug}/open-days/${saved.slug}`}>Отвори публичната кампания</Link>}
        {saved && <ul>{offers.filter((offer) => saved.visit_offers.some((member) => member.id === offer.id)).map((offer) => <li key={offer.id}><Link href={`/dashboard/activities/${offer.activity_id}/visit-offers?offer=${offer.id}`} onClick={guardNavigation}>Редактирай предложение: {offer.title}</Link></li>)}</ul>}
      </section>
    </>}
  </main>;
}
