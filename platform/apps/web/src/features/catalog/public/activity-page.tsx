import Image from 'next/image';
import Link from 'next/link';
import type { CSSProperties, ReactNode } from 'react';
import type { PublicActivityResponse } from './api';
import { publicMediaUrl } from './api';
import { audienceLabel, availabilityLabel, cardPriceLabel, cleanActivitySections, enrollmentCta, groupScheduleLines, meaningfulText, offerTarget, publicBadges, safeAccent } from './presentation';
import { PublicMedia } from './category-illustration';
import { PublicLocationDetails, VisitOfferList } from './visit-offer-list';
import styles from './catalog.module.css';

export function ActivityPage({ response }: { response: PublicActivityResponse }) {
  const { organization, activity } = response;
  const accent = { '--catalog-accent': safeAccent(organization.accent_color) } as CSSProperties;
  const sections = cleanActivitySections(activity.sections);
  const offers = activity.visit_offers ?? [];
  const target = offerTarget(offers.length);
  const terms = activity.terms.map((item) => ({ title: meaningfulText(item.title), terms: meaningfulText(item.terms), cancellation_policy: meaningfulText(item.cancellation_policy) })).filter((item) => item.terms || item.cancellation_policy);
  const instructors = activity.instructors.filter((item) => meaningfulText(item.public_name));
  const sectionCount = [sections.about, sections.benefits?.length, sections.methodology, sections.participation_terms || terms.length, instructors.length, sections.gallery?.length, sections.faq?.length].filter(Boolean).length;
  const mode = sectionCount > 4 ? 'rich' : sectionCount > 0 ? 'balanced' : 'minimal';

  return <main className={styles.publicShell} style={accent} data-content-mode={mode}>
    <nav className={styles.publicNav} aria-label="Път до дейността"><Link href={`/o/${organization.slug}`}>← Всички дейности</Link><span>{organization.public_name}</span></nav>
    <section className={styles.hero}>
      <div className={styles.heroCopy}>
        <p className={styles.eyebrow}>{activity.category}</p>
        <div className={styles.badgeRow} data-status-badges>{publicBadges(activity).map((label, index) => <span key={label} className={index === 0 ? styles.statusBadge : styles.specialBadge} data-status={index === 0 ? activity.availability.status : undefined}>{label}</span>)}</div>
        <h1>{activity.title}</h1>
        {audienceLabel(activity) && <p className={styles.audience}>{audienceLabel(activity)}</p>}
        {meaningfulText(activity.summary) && <p className={styles.lead}>{activity.summary.trim()}</p>}
        <div className={styles.heroPrice}><small>ЦЕНА</small><strong>{cardPriceLabel(activity.price)}</strong></div>
        <div className={styles.heroActions}><a className={styles.primaryButton} href={target.href}>{target.label} <span aria-hidden="true">↓</span></a></div>
        <p className={styles.heroNotice}>{enrollmentCta}.</p>
      </div>
      <div className={styles.heroVisual}><PublicMedia url={activity.hero_image_url} title={activity.title} category={activity.category} priority /></div>
    </section>

    {sections.about && <ContentSection eyebrow="ЗА ДЕЙНОСТТА" title="За дейността"><p>{sections.about}</p></ContentSection>}
    {sections.benefits!.length > 0 && <ContentSection eyebrow="ПОЛЗИ" title="Какво развива заниманието?"><div className={styles.benefitGrid}>{sections.benefits!.map((benefit, index) => <article key={`${index}-${benefit}`}><span aria-hidden="true">{String(index + 1).padStart(2, '0')}</span><h3>{benefit}</h3></article>)}</div></ContentSection>}
    {sections.methodology && <ContentSection eyebrow="ПОДХОД" title="Как протичат заниманията?"><p>{sections.methodology}</p></ContentSection>}

    {offers.length > 0 && <section className={styles.contentSection} id="visit-offers" aria-labelledby="visit-offers-title" tabIndex={-1}>
      <p className={styles.eyebrow}>ЕДНО МАЛКО НАЧАЛО</p><h2 id="visit-offers-title">Посещения и предложения</h2>
      <VisitOfferList offers={offers} groups={activity.groups} />
    </section>}

    <section className={styles.contentSection} id="groups" aria-labelledby="groups-title" tabIndex={-1}>
      <p className={styles.eyebrow}>ПРАКТИЧЕСКА ИНФОРМАЦИЯ</p><h2 id="groups-title">Групи и график</h2>
      {activity.groups.length ? <div className={styles.groupList}>{activity.groups.map((group, index) => <article className={styles.groupCard} key={group.id ?? index}>
        <div><h3>{group.name}</h3>{meaningfulText(group.age_label) && <p className={styles.audience}>{group.age_label!.trim()}</p>}{groupScheduleLines(group.schedule).map((line, i) => <p key={i}>{line}</p>)}<span className={styles.statusBadge} data-status={group.availability.status}>{availabilityLabel(group.availability)}</span></div>
        <PublicLocationDetails location={group.location} />
        <div className={styles.priceList}><small>ЦЕНА ЗА ГРУПАТА</small>{group.prices.length ? group.prices.map((price, i) => <div key={i}><strong>{cardPriceLabel(price)}</strong>{meaningfulText(price.terms) && <span>{price.terms!.trim()}</span>}</div>) : <span>Все още не е обявено</span>}</div>
      </article>)}</div> : <p>График: Все още не е обявено</p>}
      <div className={styles.enrollmentNotice}><strong>{enrollmentCta}</strong><p>На този етап бутонът не изпраща заявка.</p></div>
    </section>

    {(sections.participation_terms || terms.length > 0) && <ContentSection eyebrow="УСЛОВИЯ" title={`Условия за участие в „${activity.title}“`}>
      {sections.participation_terms && <p>{sections.participation_terms}</p>}
      {terms.length > 0 && <div className={styles.termsGrid}>{terms.map((term, i) => <article key={i}>{term.title && <h3>{term.title}</h3>}{term.terms && <p>{term.terms}</p>}{term.cancellation_policy && <p><strong>Отписване:</strong> {term.cancellation_policy}</p>}</article>)}</div>}
    </ContentSection>}
    {instructors.length > 0 && <ContentSection eyebrow="НАШИЯТ ЕКИП" title={sections.instructor_intro || 'Запознайте се с преподавателите'}><div className={styles.instructorGrid}>{instructors.map((instructor, i) => <article key={i}>
      {publicMediaUrl(instructor.photo_url) && <Image src={publicMediaUrl(instructor.photo_url)!} alt={instructor.public_name.trim()} width={160} height={160} unoptimized />}
      <div><h3>{instructor.public_name.trim()}</h3>{meaningfulText(instructor.role_title) && <strong>{instructor.role_title!.trim()}</strong>}{meaningfulText(instructor.bio) && <p>{instructor.bio!.trim()}</p>}{(instructor.qualifications ?? []).map(meaningfulText).filter(Boolean).map((qualification, index) => <p key={index}>{qualification}</p>)}</div>
    </article>)}</div></ContentSection>}
    {sections.gallery!.length > 0 && <ContentSection eyebrow="ГАЛЕРИЯ" title="Моменти от заниманията"><div className={styles.gallery}>{sections.gallery!.map((image, index) => <div className={styles.galleryVisual} key={index}><PublicMedia url={image.url} title={image.alt || activity.title} category={activity.category} /></div>)}</div></ContentSection>}
    {sections.faq!.length > 0 && <ContentSection eyebrow="ПОЛЕЗНО" title="Често задавани въпроси"><div className={styles.faqList}>{sections.faq!.map((item, index) => <details key={index}><summary>{item.question}<span aria-hidden="true">+</span></summary><p>{item.answer}</p></details>)}</div></ContentSection>}
  </main>;
}

function ContentSection({ eyebrow, title, children }: { eyebrow: string; title: string; children: ReactNode }) {
  return <section className={styles.contentSection}><p className={styles.eyebrow}>{eyebrow}</p><h2>{title}</h2>{children}</section>;
}
