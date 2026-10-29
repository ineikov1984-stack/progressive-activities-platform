'use client';

import Image from 'next/image';
import Link from 'next/link';
import { type ChangeEvent, type ReactNode, useCallback, useEffect, useRef, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { useDashboardContext } from '@/features/dashboard/dashboard-context';
import { CategoryIllustration } from '@/features/catalog/public/category-illustration';
import { type ActivityDraft, type ActivityGroup, type ActivitySections, loadActivity, loadGroups, publishActivity, updateActivity, uploadCatalogMedia } from '../api';
import { activityCategories, activityCompleteness, formatEur } from '../presentation';
import { PrivateImage, usePrivateMedia } from '../private-image';
import { Autosave, type AutosaveState } from './autosave';
import { agePresets, completedSteps, contentBlocker, contentFromActivity, savedAgo, sectionsPayload, stepByNumber, summaryLimit, type WizardContent, type WizardStep, wizardSteps } from './steps';
import styles from './wizard.module.css';

const icons = {
  back: 'M19 12H5M11 6l-6 6 6 6',
  next: 'M5 12h14M13 6l6 6-6 6',
  eye: 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z',
  check: 'M5 12l5 5 9-10',
  plus: 'M12 5v14M5 12h14',
  trash: 'M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3',
  info: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z M12 11v5M12 8v.01',
  warn: 'M12 3l10 18H2z M12 10v4M12 17v.01',
};

function Icon({ path }: { path: string }) {
  return <svg className={styles.icon} viewBox="0 0 24 24" aria-hidden="true">{path.split(' M').map((part, index) => <path key={index} d={index === 0 ? part : `M${part}`} />)}</svg>;
}

function Field({ id, label, hint, children, aside }: { id: string; label: string; hint?: ReactNode; children: ReactNode; aside?: ReactNode }) {
  return (
    <div className={styles.field}>
      <div className={styles.fieldHead}><label className={styles.label} htmlFor={id}>{label}</label>{aside}</div>
      {children}
      {hint ? <span className={styles.hint} id={`${id}-hint`}>{hint}</span> : null}
    </div>
  );
}

function ListEditor({ id, label, items, placeholder, onChange, disabled, addLabel }: { id: string; label: string; items: string[]; placeholder: string; onChange: (items: string[]) => void; disabled: boolean; addLabel: string }) {
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

function PreviewCard({ content, cover, organizationName, activity }: { content: WizardContent; cover?: string; organizationName: string; activity: ActivityDraft }) {
  const prices = activity.offering?.price_options ?? [];
  const cheapest = [...prices].sort((a, b) => a.amount_cents - b.amount_cents)[0];
  return (
    <div className={styles.previewCard}>
      <div className={styles.previewMedia}>
        {cover ? <PreviewImage src={cover} /> : <CategoryIllustration category={content.category} />}
        <span className={styles.previewCategory}>{content.category}</span>
      </div>
      <div className={styles.previewBody}>
        <strong className={styles.previewTitle}>{content.title.trim() || 'Име на дейността'}</strong>
        <span className={styles.previewProvider}><span aria-hidden="true">{organizationName.slice(0, 2).toUpperCase()}</span>{organizationName}</span>
        <p className={styles.previewSummary}>{content.summary.trim() || 'Краткото описание ще се появи тук.'}</p>
        <span className={content.age_label.trim() ? styles.previewFact : styles.previewPending}>{content.age_label.trim() || 'Възраст — в стъпка 2'}</span>
        <span className={cheapest ? styles.previewFact : styles.previewPending}>{cheapest ? `от ${formatEur(cheapest.amount_cents)}` : 'Цена — в стъпка 6'}</span>
      </div>
    </div>
  );
}

function PreviewImage({ src }: { src: string }) {
  return <Image src={src} alt="" fill sizes="320px" unoptimized className={styles.previewPhoto} />;
}

export function ActivityWizard({ activityId }: { activityId: string }) {
  const router = useRouter();
  const searchParams = useSearchParams();
  const { organization } = useDashboardContext();
  const step = stepByNumber(searchParams.get('step'));

  const [activity, setActivity] = useState<ActivityDraft>();
  const [groups, setGroups] = useState<ActivityGroup[]>([]);
  const [content, setContent] = useState<WizardContent>();
  const [loadError, setLoadError] = useState('');
  const [saveState, setSaveState] = useState<AutosaveState>('idle');
  const [saveError, setSaveError] = useState('');
  const [savedAt, setSavedAt] = useState<number>();
  const [now, setNow] = useState(() => Date.now());
  const [busy, setBusy] = useState('');
  const [galleryAlt, setGalleryAlt] = useState('');
  const [publishMessage, setPublishMessage] = useState('');
  const version = useRef(0);
  const headingRef = useRef<HTMLHeadingElement>(null);
  const contentRef = useRef<WizardContent | undefined>(undefined);

  const locked = activity !== undefined && activity.status !== 'draft';
  const cover = usePrivateMedia(organization.id, activity?.hero_image_path);

  useEffect(() => {
    let active = true;
    Promise.all([loadActivity(organization.id, activityId), loadGroups(organization.id, activityId).catch(() => [] as ActivityGroup[])])
      .then(([loaded, loadedGroups]) => {
        if (!active) return;
        version.current = loaded.version;
        setActivity(loaded);
        const initial = contentFromActivity(loaded);
        contentRef.current = initial;
        setContent(initial);
        setGroups(loadedGroups);
      })
      .catch((reason: unknown) => { if (active) setLoadError(reason instanceof Error ? reason.message : 'Дейността не беше заредена.'); });
    return () => { active = false; };
  }, [activityId, organization.id]);

  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 5000);
    return () => clearInterval(timer);
  }, []);

  const autosaveRef = useRef<Autosave<WizardContent> | null>(null);
  useEffect(() => {
    const autosave = new Autosave<WizardContent>({
      delayMs: 1200,
      save: async (value) => {
        const updated = await updateActivity(organization.id, activityId, {
          version: version.current,
          title: value.title.trim(),
          category: value.category,
          summary: value.summary.trim(),
          age_label: value.age_label.trim(),
          sections: sectionsPayload(value.sections),
        });
        version.current = updated.version;
        setActivity(updated);
        setSavedAt(Date.now());
        setSaveError('');
      },
      onState: (state, error) => {
        setSaveState(state);
        if (state === 'error') setSaveError(error instanceof Error ? error.message : 'Промените не бяха запазени. Проверете връзката и опитайте отново.');
      },
  });
    autosaveRef.current = autosave;
    return () => { void autosave.flush(); autosaveRef.current = null; };
  }, [activityId, organization.id]);

  const commit = useCallback((next: WizardContent) => {
    contentRef.current = next;
    setContent(next);
    if (!contentBlocker(next)) autosaveRef.current?.schedule(next);
  }, []);

  const change = useCallback((changes: Partial<WizardContent>) => {
    if (contentRef.current) commit({ ...contentRef.current, ...changes });
  }, [commit]);

  const changeSections = useCallback((changes: ActivitySections) => {
    const current = contentRef.current;
    if (current) commit({ ...current, sections: { ...current.sections, ...changes } });
  }, [commit]);

  function goTo(target: WizardStep) {
    void autosaveRef.current?.flush();
    router.replace(`/dashboard/activities/${activityId}?step=${target.number}`, { scroll: false });
    requestAnimationFrame(() => { window.scrollTo({ top: 0, behavior: 'smooth' }); headingRef.current?.focus(); });
  }

  async function withBusy(label: string, action: () => Promise<void>) {
    setBusy(label);
    setSaveError('');
    try { await autosaveRef.current?.flush(); await action(); }
    catch (reason) { setSaveError(reason instanceof Error ? reason.message : 'Действието не беше изпълнено.'); }
    finally { setBusy(''); }
  }

  async function uploadCover(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    await withBusy('cover', async () => {
      const media = await uploadCatalogMedia(organization.id, file, activityId);
      const updated = await updateActivity(organization.id, activityId, { version: version.current, hero_image_path: media.path });
      version.current = updated.version;
      setActivity(updated);
      setSavedAt(Date.now());
    });
  }

  async function removeCover() {
    await withBusy('cover', async () => {
      const updated = await updateActivity(organization.id, activityId, { version: version.current, hero_image_path: null });
      version.current = updated.version;
      setActivity(updated);
      setSavedAt(Date.now());
    });
  }

  async function uploadGallery(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    event.target.value = '';
    const current = contentRef.current;
    if (!file || !current) return;
    if (!galleryAlt.trim()) { setSaveError('Опишете снимката с няколко думи, преди да я качите. Описанието помага на хора, които не виждат добре.'); return; }
    await withBusy('gallery', async () => {
      const media = await uploadCatalogMedia(organization.id, file, activityId);
      const gallery = [...(current.sections.gallery ?? []), { path: media.path, alt: galleryAlt.trim() }];
      const sections = { ...current.sections, gallery };
      const updated = await updateActivity(organization.id, activityId, { version: version.current, sections: sectionsPayload(sections) });
      version.current = updated.version;
      setActivity(updated);
      const next = { ...current, sections };
      contentRef.current = next;
      setContent(next);
      setGalleryAlt('');
      setSavedAt(Date.now());
    });
  }

  async function publish() {
    if (!activity) return;
    await withBusy('publish', async () => {
      const result = await publishActivity(organization.id, activityId, version.current);
      version.current = result.version;
      setActivity({ ...activity, ...result });
      setPublishMessage('Дейността е публикувана. Родителите вече могат да я видят в каталога.');
    });
  }

  if (loadError) return <div className={styles.page}><p className={styles.error} role="alert">{loadError}</p><Link href="/dashboard/activities">← Към дейностите</Link></div>;
  if (!activity || !content) return <div className={styles.page} aria-busy="true"><span className={styles.skeleton} /><span className={styles.skeletonTall} /></div>;

  const done = completedSteps({ ...activity, ...content, sections: content.sections }, groups);
  const blocker = contentBlocker(content);
  const index = wizardSteps.findIndex((item) => item.id === step.id);
  const previous = wizardSteps[index - 1];
  const next = wizardSteps[index + 1];
  const statusText = locked ? 'Публикувана' : saveState === 'saving' || saveState === 'pending' ? 'Запазване…' : saveState === 'error' ? 'Не е запазено' : savedAgo(savedAt, now);
  const publicHref = `/o/${organization.slug}/activities/${activity.slug}`;
  const classicHref = (anchor?: string) => `/dashboard/activities/${activityId}/classic${anchor ? `#${anchor}` : ''}`;

  return (
    <div className={styles.page}>
      <header className={styles.header}>
        <div className={styles.headerMain}>
          <Link className={styles.quiet} href="/dashboard/activities"><Icon path={icons.back} />Дейности</Link>
          <span className={styles.divider} aria-hidden="true" />
          <div className={styles.titleBlock}>
            <h1 className={styles.title}>{content.title.trim() || 'Нова дейност'}</h1>
            <span className={`${styles.status} ${saveState === 'error' ? styles.statusError : ''}`} role="status" aria-live="polite">
              {!locked && saveState !== 'error' && saveState !== 'saving' && saveState !== 'pending' ? <Icon path={icons.check} /> : null}
              {locked ? 'Публикувана' : `Чернова · ${statusText}`}
            </span>
          </div>
        </div>
        {activity.status === 'published'
          ? <Link className={styles.secondary} href={publicHref}><Icon path={icons.eye} />Преглед като родител</Link>
          : <span className={styles.headerHint}>„Преглед като родител“ — след публикуване</span>}
      </header>

      {locked ? (
        <div className={styles.lockedNotice} role="note">
          <strong>Тази дейност е {activity.status === 'published' ? 'публикувана' : 'в архива'} и родителите я виждат такава, каквато е.</strong>
          <span>Описанието, снимките, цените, групите и графикът засега не могат да се променят оттук. Скоро ще може да правите промени като чернова и да ги публикувате с едно натискане, без дейността да изчезва от каталога.</span>
        </div>
      ) : null}
      {saveError ? <p className={styles.error} role="alert">{saveError}</p> : null}

      <div className={styles.layout}>
        <nav className={styles.steps} aria-label="Стъпки">
          <span className={styles.muted}>Стъпка {step.number} от {wizardSteps.length}</span>
          <ol>
            {wizardSteps.map((item) => {
              const current = item.id === step.id;
              const complete = done.has(item.id);
              return (
                <li key={item.id}>
                  <button type="button" className={`${styles.step} ${current ? styles.stepCurrent : ''} ${complete ? styles.stepDone : ''}`} aria-current={current ? 'step' : undefined} onClick={() => goTo(item)}>
                    <span className={styles.stepDot} aria-hidden="true">{complete ? <Icon path={icons.check} /> : item.number}</span>
                    <span className={styles.stepText}>
                      <span>{item.title}</span>
                      <span className={styles.stepNote}>{complete ? 'Готово' : item.optional ? 'По избор' : item.note}</span>
                    </span>
                    {complete ? <span className="sr-only">(готово)</span> : null}
                  </button>
                </li>
              );
            })}
          </ol>
        </nav>

        <main className={styles.main}>
          <div className={styles.stepHead}>
            <span className={styles.eyebrow}>Стъпка {step.number}</span>
            <h2 className={styles.stepTitle} ref={headingRef} tabIndex={-1}>{stepHeadings[step.id]}</h2>
            <p className={styles.muted}>{stepIntros[step.id]}</p>
          </div>

          <section className={styles.panel}>
            <fieldset className={styles.fieldsetPlain} disabled={locked}>
              {step.id === 'basics' ? (
                <>
                  <Field id="w-title" label="Име на дейността" hint="Кратко и разбираемо за родител. Пример: „Роботика за 3.–4. клас“.">
                    <input id="w-title" className={styles.input} value={content.title} maxLength={120} required aria-describedby="w-title-hint" onChange={(event) => change({ title: event.target.value })} />
                  </Field>
                  <div className={styles.field}>
                    <span className={styles.label} id="w-category">Категория</span>
                    <div className={styles.chips} role="group" aria-labelledby="w-category">
                      {[...new Set([...activityCategories, content.category])].map((item) => (
                        <button type="button" key={item} aria-pressed={content.category === item} className={`${styles.chip} ${content.category === item ? styles.chipOn : ''}`} onClick={() => change({ category: item })}>{item}</button>
                      ))}
                    </div>
                  </div>
                  <Field id="w-summary" label="Кратко описание" hint={<><span>1–2 изречения: какво правят децата и какво печелят.</span><span aria-live="polite">{content.summary.length} / {summaryLimit}</span></>}>
                    <textarea id="w-summary" className={`${styles.input} ${styles.textarea}`} value={content.summary} aria-describedby="w-summary-hint" onChange={(event) => change({ summary: event.target.value })} />
                  </Field>
                </>
              ) : null}

              {step.id === 'audience' ? (
                <>
                  <Field id="w-age" label="За кого е подходяща" hint="Възраст или класове, както ги казвате на родителите. Изберете готов вариант или напишете свой.">
                    <input id="w-age" className={styles.input} value={content.age_label} maxLength={80} aria-describedby="w-age-hint" onChange={(event) => change({ age_label: event.target.value })} />
                  </Field>
                  <div className={styles.chips} role="group" aria-label="Готови варианти за възраст">
                    {agePresets.map((item) => <button type="button" key={item} className={`${styles.chip} ${content.age_label === item ? styles.chipOn : ''}`} aria-pressed={content.age_label === item} onClick={() => change({ age_label: item })}>{item}</button>)}
                  </div>
                  <Field id="w-terms" label="Условия за участие (по избор)" hint="Например: подходящо и за начинаещи; нужна е спортна екипировка.">
                    <textarea id="w-terms" className={`${styles.input} ${styles.textarea}`} value={content.sections.participation_terms ?? ''} onChange={(event) => changeSections({ participation_terms: event.target.value })} />
                  </Field>
                </>
              ) : null}

              {step.id === 'story' ? (
                <>
                  <Field id="w-about" label="Какво ще правят децата" hint="Опишете едно занимание: как започва, какво правят, с какво си тръгват.">
                    <textarea id="w-about" className={`${styles.input} ${styles.textareaTall}`} value={content.sections.about ?? ''} onChange={(event) => changeSections({ about: event.target.value })} />
                  </Field>
                  <ListEditor id="w-benefits" label="Какво печелят" items={content.sections.benefits ?? []} placeholder="Например: концентрация и внимание" addLabel="Добави полза" disabled={locked} onChange={(benefits) => changeSections({ benefits })} />
                  <Field id="w-method" label="Как протичат заниманията (по избор)">
                    <textarea id="w-method" className={`${styles.input} ${styles.textarea}`} value={content.sections.methodology ?? ''} onChange={(event) => changeSections({ methodology: event.target.value })} />
                  </Field>
                  <fieldset className={styles.fieldset} disabled={locked}>
                    <legend className={styles.label}>Често задавани въпроси (по избор)</legend>
                    {(content.sections.faq ?? []).map((item, at) => (
                      <div className={styles.faqRow} key={at}>
                        <input className={styles.input} aria-label={`Въпрос ${at + 1}`} placeholder="Въпрос" value={item.question} onChange={(event) => changeSections({ faq: (content.sections.faq ?? []).map((row, i) => i === at ? { ...row, question: event.target.value } : row) })} />
                        <textarea className={`${styles.input} ${styles.textareaSmall}`} aria-label={`Отговор ${at + 1}`} placeholder="Отговор" value={item.answer} onChange={(event) => changeSections({ faq: (content.sections.faq ?? []).map((row, i) => i === at ? { ...row, answer: event.target.value } : row) })} />
                        <button type="button" className={styles.quiet} onClick={() => changeSections({ faq: (content.sections.faq ?? []).filter((_, i) => i !== at) })}><Icon path={icons.trash} />Премахни въпроса</button>
                      </div>
                    ))}
                    <button type="button" className={styles.quiet} onClick={() => changeSections({ faq: [...(content.sections.faq ?? []), { question: '', answer: '' }] })}><Icon path={icons.plus} />Добави въпрос</button>
                  </fieldset>
                </>
              ) : null}

              {step.id === 'media' ? (
                <>
                  <div className={styles.field}>
                    <span className={styles.label}>Основна снимка</span>
                    <div className={styles.coverRow}>
                      <div className={styles.coverFrame}>{cover ? <PreviewImage src={cover} /> : <CategoryIllustration category={content.category} />}</div>
                      <div className={styles.coverActions}>
                        <label className={styles.secondary}>
                          <input type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={uploadCover} disabled={locked || busy === 'cover'} />
                          {busy === 'cover' ? 'Качване…' : activity.hero_image_path ? 'Смени снимката' : 'Качи снимка'}
                        </label>
                        {activity.hero_image_path ? <button type="button" className={styles.quiet} onClick={removeCover} disabled={busy === 'cover'}>Махни снимката</button> : null}
                        <span className={styles.hint}>JPG, PNG или WebP до 5 MB. Хоризонтална снимка от истинско занимание работи най-добре.</span>
                      </div>
                    </div>
                  </div>
                  <div className={styles.field}>
                    <span className={styles.label}>Галерия (по избор)</span>
                    <div className={styles.gallery}>
                      {(content.sections.gallery ?? []).map((image, at) => (
                        <figure key={image.path} className={styles.galleryItem}>
                          <PrivateImage organizationId={organization.id} path={image.path} alt={image.alt} width={240} height={160} />
                          <figcaption>{image.alt}</figcaption>
                          <button type="button" className={styles.quiet} onClick={() => changeSections({ gallery: (content.sections.gallery ?? []).filter((_, i) => i !== at) })}><Icon path={icons.trash} />Премахни</button>
                        </figure>
                      ))}
                    </div>
                    <div className={styles.galleryAdd}>
                      <Field id="w-gallery-alt" label="Какво се вижда на снимката" hint="Задължително преди качване. Помага на хора, които не виждат добре.">
                        <input id="w-gallery-alt" className={styles.input} value={galleryAlt} maxLength={255} placeholder="Например: деца решават задача на дъската" onChange={(event) => setGalleryAlt(event.target.value)} />
                      </Field>
                      <label className={styles.secondary}>
                        <input type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={uploadGallery} disabled={locked || busy === 'gallery'} />
                        {busy === 'gallery' ? 'Качване…' : 'Добави снимка в галерията'}
                      </label>
                    </div>
                  </div>
                </>
              ) : null}
            </fieldset>

            {step.classicAnchor && step.id !== 'review' ? (
              <div className={styles.classic}>
                <p>Тази стъпка все още е в досегашния редактор. Скоро ще бъде тук, със същото удобство като първите стъпки.</p>
                <Link className={styles.secondary} href={classicHref(step.classicAnchor)}>Отвори „{step.title}“</Link>
              </div>
            ) : null}

            {step.id === 'trials' ? (
              <div className={styles.classic}>
                <p>Пробни и еднократни посещения помагат на родителите да опитат, преди да се запишат. Стъпката е по избор.</p>
                <Link className={styles.secondary} href={`/dashboard/activities/${activityId}/visit-offers`}>Управлявай пробните посещения</Link>
              </div>
            ) : null}

            {step.id === 'review' ? (
              <ReviewStep activity={activity} content={content} groupsCount={groups.length} onPublish={publish} publishing={busy === 'publish'} message={publishMessage} classicHref={classicHref('review')} onGo={goTo} />
            ) : null}
          </section>

          <div className={styles.footer}>
            {previous ? <button type="button" className={styles.quiet} onClick={() => goTo(previous)}><Icon path={icons.back} />Назад</button> : <span />}
            <span className={styles.muted}>{!locked && blocker ? blocker : step.id === 'basics' ? 'Задължително за публикуване: име, категория и описание.' : ''}</span>
            {next ? <button type="button" className={styles.primary} onClick={() => goTo(next)}>Напред: {next.title}<Icon path={icons.next} /></button> : null}
          </div>
        </main>

        <aside className={styles.preview} aria-label="Както ще го види родителят">
          <span className={styles.previewLabel}><Icon path={icons.eye} />Както ще го види родителят</span>
          <PreviewCard content={content} cover={cover} organizationName={organization.name} activity={activity} />
          {!activity.hero_image_path ? (
            <div className={styles.tip}><Icon path={icons.info} /><span>Без снимка картата използва илюстрацията на категорията. Истинска снимка от заниманията вдъхва повече доверие на родителите.</span></div>
          ) : null}
        </aside>
      </div>
    </div>
  );
}

function ReviewStep({ activity, content, groupsCount, onPublish, publishing, message, classicHref, onGo }: { activity: ActivityDraft; content: WizardContent; groupsCount: number; onPublish: () => void; publishing: boolean; message: string; classicHref: string; onGo: (step: WizardStep) => void }) {
  const completeness = activityCompleteness({ ...activity, ...content, sections: content.sections });
  const missing = completeness.steps.filter((item) => !item.done);
  const stepFor: Record<string, WizardStep> = Object.fromEntries(wizardSteps.map((item) => [item.id, item]));
  const ready = missing.length === 0 && groupsCount > 0;
  return (
    <div className={styles.review}>
      <ul className={styles.checklist}>
        {completeness.steps.map((item) => (
          <li key={item.id} className={item.done ? styles.checkDone : styles.checkMissing}>
            <Icon path={item.done ? icons.check : icons.warn} />
            <span>{item.label}</span>
            {!item.done && stepFor[item.id] ? <button type="button" className={styles.linkButton} onClick={() => onGo(stepFor[item.id])}>Попълни</button> : null}
          </li>
        ))}
        <li className={groupsCount > 0 ? styles.checkDone : styles.checkMissing}>
          <Icon path={groupsCount > 0 ? icons.check : icons.warn} />
          <span>Поне една група с график</span>
          {groupsCount === 0 ? <button type="button" className={styles.linkButton} onClick={() => onGo(stepFor.groups)}>Добави</button> : null}
        </li>
      </ul>
      {message ? <p className={styles.success} role="status">{message}</p> : null}
      {activity.status === 'draft' ? (
        <div className={styles.publishRow}>
          <button type="button" className={styles.primary} onClick={onPublish} disabled={publishing || !ready} aria-describedby="publish-hint">{publishing ? 'Публикуване…' : 'Публикувай дейността'}</button>
          <span className={styles.hint} id="publish-hint">{ready ? 'След публикуване родителите ще я видят в каталога.' : 'Попълнете липсващите стъпки, за да публикувате.'}</span>
        </div>
      ) : null}
      <p className={styles.hint}>Условията и политиката за отказ засега се редактират в <Link href={classicHref}>досегашния редактор</Link>.</p>
    </div>
  );
}

const stepHeadings: Record<WizardStep['id'], string> = {
  basics: 'Разкажете накратко за дейността',
  audience: 'За кого е дейността',
  story: 'Какво преживяват децата',
  media: 'Покажете заниманията',
  people: 'Къде и с кого',
  price: 'Цени и записване',
  groups: 'Групи и график',
  trials: 'Пробни и еднократни посещения',
  review: 'Преглед преди публикуване',
};

const stepIntros: Record<WizardStep['id'], string> = {
  basics: 'Това е първото, което родителите виждат в каталога. Всичко може да се промени по-късно.',
  audience: 'Родителите филтрират по възраст и клас. Използвайте думите, с които говорите с тях.',
  story: 'Конкретният разказ убеждава повече от общите думи. Празните полета няма да се показват.',
  media: 'Снимките от истински занимания вдъхват доверие. Без снимка показваме илюстрация на категорията.',
  people: 'Изберете зала или адрес и водещ преподавател.',
  price: 'Колко струва и как се записват децата.',
  groups: 'Дни, часове и капацитет на всяка група.',
  trials: 'По избор: пробно занимание или еднократно посещение.',
  review: 'Проверете какво липсва. Публикувайте, когато всичко е готово.',
};
