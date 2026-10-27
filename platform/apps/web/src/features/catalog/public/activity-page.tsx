import Image from 'next/image';
import Link from 'next/link';
import type { CSSProperties, ReactNode } from 'react';
import type { PublicActivityResponse } from './api';
import { publicMediaUrl } from './api';
import { availabilityLabel, enrollmentCta, formatPublicPrice, navigationAction, summarizePublicSchedule } from './presentation';
import styles from './catalog.module.css';

export function ActivityPage({ response }: { response: PublicActivityResponse }) {
  const { organization, activity } = response;
  const accent = { '--catalog-accent': organization.accent_color ?? '#216653' } as CSSProperties;
  const primaryGroup = activity.groups[0];

  return <main className={styles.publicShell} style={accent}>
    <nav className={styles.publicNav}><Link href={`/o/${organization.slug}`}>← Всички дейности</Link><span>{organization.public_name}</span></nav>
    <section className={styles.hero}>
      <div className={styles.heroCopy}>
        <div className={styles.tagRow}><span>{activity.category}</span><span className={styles.availableBadge}>{availabilityLabel(activity.availability)}</span><span>Подходящо за: {activity.age_label}</span></div>
        <h1>{activity.title}</h1><p className={styles.lead}>{activity.summary}</p>
        {activity.price && <div className={styles.heroPrice}><small>ЦЕНА</small><strong>{formatPublicPrice(activity.price)}</strong></div>}
        <div className={styles.heroActions}><span className={styles.futureCta}>{enrollmentCta}</span><a href="#groups" className={styles.secondaryButton}>Виж графика</a></div>
      </div>
      <div className={styles.heroVisual}>{activity.hero_image_url ? <Image src={publicMediaUrl(activity.hero_image_url)!} alt="" fill priority sizes="(max-width: 800px) 100vw, 46vw" unoptimized /> : <div className={styles.imagePlaceholder} aria-hidden="true">✦</div>}</div>
    </section>

    {primaryGroup && <section className={styles.quickFacts} aria-label="Практическа информация">
      <Fact label="Възраст" value={primaryGroup.age_label || activity.age_label}/><Fact label="График" value={summarizePublicSchedule(primaryGroup.schedule)}/>
      <Fact label="Локация" value={primaryGroup.location?.name ?? 'Ще бъде уточнена'}/><Fact label="Група" value={primaryGroup.capacity_max ? `До ${primaryGroup.capacity_max} деца` : 'Малка група'}/>
    </section>}

    {activity.sections.about && <ContentSection eyebrow="ЗА ДЕЙНОСТТА" title="Да мислиш, преди да направиш своя ход"><p>{activity.sections.about}</p></ContentSection>}
    {activity.sections.benefits?.length ? <ContentSection eyebrow="ПОЛЗИ" title="Какво развива заниманието?"><div className={styles.benefitGrid}>{activity.sections.benefits.map((benefit, i) => <article key={benefit}><span>0{i + 1}</span><h3>{benefit}</h3></article>)}</div></ContentSection> : null}
    {activity.sections.methodology && <ContentSection eyebrow="ПОДХОД" title="Как протичат заниманията?"><p>{activity.sections.methodology}</p></ContentSection>}

    <section className={styles.contentSection} id="groups"><p className={styles.eyebrow}>ПРАКТИЧЕСКА ИНФОРМАЦИЯ</p><h2>Групи и график</h2>
      <div className={styles.groupList}>{activity.groups.map((group) => { const navigation = navigationAction(group.location); return <article className={styles.groupCard} key={group.name}>
        <div><h3>{group.name}</h3><p>{group.age_label || activity.age_label} · {summarizePublicSchedule(group.schedule)}</p><span className={group.availability.status === 'limited' ? styles.limitedBadge : styles.availableBadge}>{availabilityLabel(group.availability)}</span></div>
        <div className={styles.locationBlock}><strong>{group.location?.name ?? 'Мястото ще бъде уточнено'}</strong>{group.location?.address && <span>{group.location.address}</span>}{group.location?.public_directions && <span>{group.location.public_directions}</span>}{navigation && <a href={navigation.href} target="_blank" rel="noreferrer">{navigation.label} ↗</a>}</div>
        <div className={styles.priceList}>{group.prices.map((price) => <div key={`${price.enrollment_mode}-${price.unit}`}><strong>{formatPublicPrice(price)}</strong>{price.terms && <span>{price.terms}</span>}</div>)}</div>
      </article>})}</div>
      <div className={styles.enrollmentNotice}><strong>{enrollmentCta}</strong><p>Тук ще можете да изберете дете, група и начин на записване. На този етап бутонът не изпраща заявка.</p></div>
    </section>

    {(activity.sections.participation_terms || activity.terms.length > 0) && <ContentSection eyebrow="УСЛОВИЯ" title={`Условия за участие в „${activity.title}“`}><div className={styles.termsGrid}>{activity.sections.participation_terms && <p>{activity.sections.participation_terms}</p>}{activity.terms.map((term, i) => <article key={i}>{term.title && <h3>{term.title}</h3>}{term.terms && <p>{term.terms}</p>}{term.cancellation_policy && <p><strong>Отписване:</strong> {term.cancellation_policy}</p>}</article>)}</div></ContentSection>}
    {activity.instructors.length > 0 && <ContentSection eyebrow="НАШИЯТ ЕКИП" title={activity.sections.instructor_intro || 'Опит и професионален подход'}><div className={styles.instructorGrid}>{activity.instructors.map((instructor) => <article key={instructor.public_name}>{instructor.photo_url && <Image src={publicMediaUrl(instructor.photo_url)!} alt="" width={180} height={180} unoptimized/>}<div><h3>{instructor.public_name}</h3>{instructor.role_title && <strong>{instructor.role_title}</strong>}{instructor.bio && <p>{instructor.bio}</p>}</div></article>)}</div></ContentSection>}
    {activity.sections.gallery?.length ? <ContentSection eyebrow="ГАЛЕРИЯ" title="Моменти от заниманията"><div className={styles.gallery}>{activity.sections.gallery.map((image) => <Image key={image.url} src={publicMediaUrl(image.url)!} alt={image.alt} width={640} height={440} unoptimized/>)}</div></ContentSection> : null}
    {activity.sections.faq?.length ? <ContentSection eyebrow="ПОЛЕЗНО" title="Често задавани въпроси"><div className={styles.faqList}>{activity.sections.faq.map((item, i) => <details key={item.question} open={i === 0}><summary>{item.question}<span>+</span></summary><p>{item.answer}</p></details>)}</div></ContentSection> : null}
  </main>;
}

function Fact({ label, value }: { label: string; value: string }) { return <div><small>{label}</small><strong>{value}</strong></div>; }
function ContentSection({ eyebrow, title, children }: { eyebrow: string; title: string; children: ReactNode }) { return <section className={styles.contentSection}><p className={styles.eyebrow}>{eyebrow}</p><h2>{title}</h2>{children}</section>; }
