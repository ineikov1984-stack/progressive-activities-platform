'use client';

import Image from 'next/image';
import Link from 'next/link';
import { type ChangeEvent, useCallback, useEffect, useRef, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { useDashboardContext } from '@/features/dashboard/dashboard-context';
import { CategoryIllustration } from '@/features/catalog/public/category-illustration';
import { type ActivityDraft, type ActivityGroup, type ActivitySections, type InstructorProfile, loadActivity, loadCatalogWorkspace, loadGroups, type Location, publishActivity, updateActivity, uploadCatalogMedia } from '../api';
import { activityCategories, activityCompleteness, activityStatusLabel, formatEur } from '../presentation';
import { PrivateImage, usePrivateMedia } from '../private-image';
import { Autosave, type AutosaveState } from './autosave';
import { checkOffering, enrollmentModes, isFreeTrial, priceUnits, type OfferingDraft } from './offering';
import { StepGroups } from './step-groups';
import { StepPeople } from './step-people';
import { StepPrice } from './step-price';
import { StepSpecial } from './step-special';
import { agePresets, completedSteps, contentBlocker, contentFromActivity, draftActivity, savedAgo, sectionsPayload, stepByNumber, storedBasics, summaryLimit, type WizardContent, type WizardStep, wizardSteps } from './steps';
import { Field, Icon, icons, ListEditor } from './ui';
import styles from './wizard.module.css';

function PreviewCard({ content, cover, organizationName, activity }: { content: WizardContent; cover?: string; organizationName: string; activity: ActivityDraft }) {
  const prices = activity.offering?.price_options ?? [];
  // A free trial is the "from" price only when there is nothing else (docs/product/visits.md, т. 5.5).
  const cheapest = [...prices].sort((a, b) => Number(a.amount_cents === 0) - Number(b.amount_cents === 0) || a.amount_cents - b.amount_cents)[0];
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
        <span className={cheapest ? styles.previewFact : styles.previewPending}>{cheapest ? (cheapest.amount_cents === 0 ? 'Безплатно' : `от ${formatEur(cheapest.amount_cents)}`) : 'Цена — в стъпка 6'}</span>
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
  const [locations, setLocations] = useState<Location[]>([]);
  const [instructors, setInstructors] = useState<InstructorProfile[]>([]);
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
  const savedOffering = useRef('');

  const locked = activity !== undefined && activity.status !== 'draft';
  const cover = usePrivateMedia(organization.id, activity?.hero_image_path);

  useEffect(() => {
    let active = true;
    Promise.all([
      loadActivity(organization.id, activityId),
      loadGroups(organization.id, activityId).catch(() => [] as ActivityGroup[]),
      loadCatalogWorkspace(organization.id).catch(() => undefined),
    ])
      .then(([loaded, loadedGroups, workspace]) => {
        if (!active) return;
        setLocations(workspace?.locations ?? []);
        setInstructors(workspace?.instructors ?? []);
        version.current = loaded.version;
        setActivity(loaded);
        const initial = contentFromActivity(loaded);
        savedOffering.current = initial.offering ? JSON.stringify(checkOffering(initial.offering).payload) : '';
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
        // The offering (prices replace the old ones) is sent only when it changed.
        const checked = value.offering ? checkOffering(value.offering).payload : undefined;
        const offeringPayload = checked && JSON.stringify(checked) !== savedOffering.current ? checked : undefined;
        const updated = await updateActivity(organization.id, activityId, {
          version: version.current,
          ...storedBasics(value),
          sections: sectionsPayload(value.sections),
          ...(offeringPayload ? { offering: offeringPayload } : {}),
        });
        if (offeringPayload) savedOffering.current = JSON.stringify(offeringPayload);
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

  const changeOffering = useCallback((changes: Partial<OfferingDraft>) => {
    const current = contentRef.current;
    if (current?.offering) commit({ ...current, offering: { ...current.offering, ...changes } });
  }, [commit]);

  // On phones the step list scrolls sideways; keep the current step visible.
  const loaded = content !== undefined;
  useEffect(() => {
    if (loaded) document.querySelector<HTMLElement>('[aria-current="step"]')?.scrollIntoView({ block: 'nearest', inline: 'center' });
  }, [step.id, loaded]);

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

  const draft = draftActivity(activity, content);
  const done = completedSteps(draft, groups);
  const offeringProblems = content.offering ? checkOffering(content.offering).problems : {};
  const blocker = contentBlocker(content);
  const index = wizardSteps.findIndex((item) => item.id === step.id);
  const previous = wizardSteps[index - 1];
  const next = wizardSteps[index + 1];
  const statusText = saveState === 'saving' || saveState === 'pending' ? 'Запазване…' : saveState === 'error' ? 'Не е запазено' : savedAt === undefined ? 'промените се пазят автоматично' : savedAgo(savedAt, now);
  const publicHref = `/o/${organization.slug}/activities/${activity.slug}`;

  return (
    <div className={styles.page}>
      <header className={styles.header}>
        <div className={styles.headerMain}>
          <Link className={styles.quiet} href="/dashboard/activities"><Icon path={icons.back} />Дейности</Link>
          <span className={styles.divider} aria-hidden="true" />
          <div className={styles.titleBlock}>
            <h1 className={styles.title}>{content.title.trim() || 'Нова дейност'}</h1>
            <span className={`${styles.status} ${saveState === 'error' ? styles.statusError : ''}`} role="status" aria-live="polite">
              {!locked && savedAt !== undefined && saveState === 'saved' ? <Icon path={icons.check} /> : null}
              {locked ? activityStatusLabel(activity.status) : `Чернова · ${statusText}`}
            </span>
          </div>
        </div>
        {activity.status === 'published'
          ? <Link className={styles.secondary} href={publicHref}><Icon path={icons.eye} />Преглед като родител</Link>
          : <span className={styles.headerHint}>„Преглед като родител“ — след публикуване</span>}
      </header>

      {locked ? (
        <div className={styles.lockedNotice} role="note">
          <strong>{activity.status === 'published' ? 'Дейността е публикувана и се вижда от родителите.' : 'Дейността е в архива.'}</strong>
          <span>Този формуляр е само за преглед. Описанието, снимките, цените, групите и графикът не могат да се променят оттук.</span>
        </div>
      ) : null}
      {saveError ? <p className={styles.error} role="alert">{saveError}</p> : null}

      <div className={`${styles.layout} ${step.id === 'people' || step.id === 'groups' ? styles.layoutWide : ''}`}>
        <nav className={styles.steps} aria-label="Стъпки">
          <label className={styles.mobileStepChoice}>Стъпка {step.number} от {wizardSteps.length}
            <select aria-label="Избери стъпка" value={step.number} onChange={(event)=>goTo(stepByNumber(event.target.value))}>
              {wizardSteps.map(item=><option key={item.id} value={item.number}>{item.number}. {item.title}{done.has(item.id)?' · готово':''}</option>)}
            </select>
          </label>
          <span className={`${styles.muted} ${styles.desktopSteps}`}>Стъпка {step.number} от {wizardSteps.length}</span>
          <ol className={styles.desktopSteps}>
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
                    <textarea id="w-summary" className={`${styles.input} ${styles.textarea}`} value={content.summary} placeholder="Например: Децата сглобяват и програмират малки роботи и се учат да мислят стъпка по стъпка."  aria-describedby="w-summary-hint" onChange={(event) => change({ summary: event.target.value })} />
                  </Field>
                </>
              ) : null}

              {step.id === 'audience' ? (
                <>
                  <Field id="w-age" label="За кого е подходяща" hint="Възраст или класове." help={{ label: 'Как да посоча възрастта?', text: 'Изберете готов вариант или напишете свой — с думите, които използвате с родителите. Например: „3.–4. клас“ или „7–10 години“.' }}>
                    <input id="w-age" className={styles.input} value={content.age_label} placeholder="Например: 7–10 г. или 1.–4. клас"  maxLength={80} aria-describedby="w-age-hint" onChange={(event) => change({ age_label: event.target.value })} />
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
                  <Field id="w-about" label="Какво ще правят децата" help={{ label: 'Как да опиша заниманието?', text: 'Опишете едно занимание: как започва, какво правят децата и с какво си тръгват. Използвайте конкретни примери вместо общи обещания.' }}>
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
                        {activity.hero_image_path ? <button type="button" className={styles.quiet} onClick={removeCover} disabled={busy === 'cover'}>Премахни снимката</button> : null}
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

            {(step.id === 'people' || step.id === 'price') && !content.offering ? (
              <div className={styles.classic}>
                <p>Тази дейност е създадена по стария начин и няма данни за място и цени. Отворете досегашния редактор, за да ги добавите.</p>
                <Link className={styles.secondary} href={`/dashboard/activities/${activityId}/classic`}>Отвори досегашния редактор</Link>
              </div>
            ) : null}

            {step.id === 'people' && content.offering ? (
              <StepPeople
                organizationId={organization.id}
                offering={content.offering}
                locations={locations}
                instructors={instructors}
                instructorIntro={content.sections.instructor_intro ?? ''}
                locked={locked}
                onOffering={changeOffering}
                onIntro={(instructor_intro) => changeSections({ instructor_intro })}
                onLocation={(location) => setLocations((items) => [...items, location])}
                onInstructor={(instructor) => setInstructors((items) => [...items, instructor])}
              />
            ) : null}

            {step.id === 'price' && content.offering ? (
              <StepPrice
                organizationId={organization.id}
                offering={content.offering}
                groupModes={groups.flatMap((group) => group.price_options.map((price) => price.enrollment_mode))}
                problems={offeringProblems}
                locked={locked}
                onOffering={changeOffering}
              />
            ) : null}

            {step.id === 'groups' ? (
              <StepGroups
                organizationId={organization.id}
                activityId={activityId}
                modes={(content.offering?.prices ?? []).flatMap((price) => {
                  const mode = enrollmentModes.find((item) => item.value === price.enrollment_mode);
                  const unit = priceUnits.find((item) => item.value === price.unit)?.label ?? '';
                  return mode ? [{ value: mode.value, label: mode.label, price: isFreeTrial(price) ? 'Безплатно' : `${price.amount} € ${unit}`.trim() }] : [];
                })}
                ageLabel={content.age_label.trim() || activity.age_label}
                groups={groups}
                locations={locations}
                instructors={instructors}
                defaultLocationId={content.offering?.location_id ?? null}
                locked={locked}
                onGroup={(group) => setGroups((items) => items.some((item) => item.id === group.id) ? items.map((item) => item.id === group.id ? group : item) : [...items, group])}
              />
            ) : null}

            {step.id === 'trials' ? (
              <StepSpecial
                organizationId={organization.id}
                activityId={activityId}
                groups={groups}
                hasVisits={(content.offering?.prices ?? []).some((price) => price.enrollment_mode === 'trial' || price.enrollment_mode === 'single')}
              />
            ) : null}

            {step.id === 'review' ? (
              <ReviewStep activity={draft} groups={groups} onPublish={publish} publishing={busy === 'publish'} message={publishMessage} onGo={goTo} />
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
          <PreviewCard content={content} cover={cover} organizationName={organization.name} activity={draft} />
          {!activity.hero_image_path ? (
            <div className={styles.tip}><Icon path={icons.info} /><span>Без снимка картата използва илюстрацията на категорията. Истинска снимка от заниманията вдъхва повече доверие на родителите.</span></div>
          ) : null}
        </aside>
      </div>
    </div>
  );
}

/** What the API requires to publish; the rest is recommended but does not block. */
const requiredForPublish = new Set(['basics', 'audience', 'price']);

function ReviewStep({ activity, groups, onPublish, publishing, message, onGo }: { activity: ActivityDraft; groups: ActivityGroup[]; onPublish: () => void; publishing: boolean; message: string; onGo: (step: WizardStep) => void }) {
  const completeness = activityCompleteness(activity);
  const stepFor: Record<string, WizardStep> = Object.fromEntries(wizardSteps.map((item) => [item.id, item]));
  const blocking = groups.flatMap((group) => group.conflicts).filter((conflict) => conflict.severity === 'blocking');
  const required = completeness.steps.filter((item) => requiredForPublish.has(item.id));
  const recommended = completeness.steps.filter((item) => !requiredForPublish.has(item.id));
  const ready = required.every((item) => item.done) && groups.length > 0 && blocking.length === 0;
  const row = (key: string, done: boolean, label: string, target?: WizardStep, action = 'Попълни', tone: 'missing' | 'optional' = 'missing') => (
    <li key={key} className={done ? styles.checkDone : tone === 'missing' ? styles.checkMissing : styles.checkOptional}>
      <Icon path={done ? icons.check : icons.warn} />
      <span>{label}</span>
      {!done && target ? <button type="button" className={styles.linkButton} onClick={() => onGo(target)}>{action}</button> : null}
    </li>
  );
  return (
    <div className={styles.review}>
      <h3 className={styles.reviewTitle}>Задължително за публикуване</h3>
      <ul className={styles.checklist}>
        {required.map((item) => row(item.id, item.done, item.label, stepFor[item.id]))}
        {row('groups', groups.length > 0, 'Поне една група с график', stepFor.groups, 'Добави')}
        {blocking.length > 0 ? row('conflicts', false, 'Графикът се застъпва с друго занимание', stepFor.groups, 'Провери') : null}
      </ul>
      <h3 className={styles.reviewTitle}>Препоръчително — помага на родителите да изберат</h3>
      <ul className={styles.checklist}>
        {recommended.map((item) => row(item.id, item.done, item.label, stepFor[item.id], 'Попълни', 'optional'))}
      </ul>
      {message ? <p className={styles.success} role="status">{message}</p> : null}
      {activity.status === 'draft' ? (
        <div className={styles.publishRow}>
          <button type="button" className={styles.primary} onClick={onPublish} disabled={publishing || !ready} aria-describedby="publish-hint">{publishing ? 'Публикуване…' : 'Публикувай дейността'}</button>
          <span className={styles.hint} id="publish-hint">{ready ? 'След публикуване родителите ще я видят в каталога.' : 'Попълнете задължителните стъпки, за да публикувате.'}</span>
        </div>
      ) : null}
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
  trials: 'Специални посещения',
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
  trials: 'По избор: ден на отворените врати или посещения само на определени дати.',
  review: 'Проверете какво липсва. Публикувайте, когато всичко е готово.',
};
