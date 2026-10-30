import Link from 'next/link';
import type { CSSProperties } from 'react';
import type { PublicOpenDayResponse } from './api';
import { PublicMedia } from './category-illustration';
import { enrollmentCta, localDayLabel, meaningfulText, safeAccent } from './presentation';
import { VisitOfferList } from './visit-offer-list';
import styles from './catalog.module.css';

export function OpenDayPage({ response }: { response: PublicOpenDayResponse }) {
  const { organization, campaign } = response;
  const accent = { '--catalog-accent': safeAccent(organization.accent_color) } as CSSProperties;
  const period = [campaign.starts_on, campaign.ends_on].filter((date): date is string => Boolean(date)).map(localDayLabel).join(' – ');
  return <main className={styles.publicShell} style={accent}>
    <nav className={styles.publicNav} aria-label="Навигация"><Link href={`/o/${organization.slug}`}>← Всички дейности</Link><span>{organization.public_name}</span></nav>
    <section className={styles.hero}>
      <div className={styles.heroCopy}><p className={styles.eyebrow}>ОТВОРЕНИ ВРАТИ</p><h1>{campaign.title}</h1>
        {meaningfulText(campaign.description) && <p className={styles.lead}>{campaign.description!.trim()}</p>}
        <p className={styles.campaignPeriod}>{period || 'Датите още не са обявени'}</p>
        <a className={styles.primaryButton} href="#campaign-days">Вижте посещенията <span aria-hidden="true">↓</span></a>
        <p className={styles.heroNotice}>Всяко посещение има своя цена, час и свободни места. {enrollmentCta}</p>
      </div>
      <div className={styles.heroVisual}><PublicMedia url={campaign.image_url} title={campaign.title} category="education" priority /></div>
    </section>
    <div id="campaign-days" tabIndex={-1}>{campaign.days.map((day) => <section key={day.date} className={styles.contentSection} aria-labelledby={`day-${day.date}`}>
      <p className={styles.eyebrow}>ИЗБЕРЕТЕ ПОСЕЩЕНИЕ</p><h2 id={`day-${day.date}`}><time dateTime={day.date}>{localDayLabel(day.date)}</time></h2>
      {day.activities.map(({ activity, visit_offers }) => <section key={activity.slug} className={styles.campaignActivity} aria-labelledby={`${day.date}-${activity.slug}`}>
        <div className={styles.campaignActivityHeading}><div><p className={styles.categoryLabel}>{activity.category}</p><h3 id={`${day.date}-${activity.slug}`}>{activity.title}</h3></div><Link className={styles.secondaryButton} href={`/o/${organization.slug}/activities/${activity.slug}`}>За дейността ↗</Link></div>
        {meaningfulText(activity.summary) && <p>{activity.summary.trim()}</p>}
        <VisitOfferList offers={visit_offers} headingLevel={4} />
      </section>)}
    </section>)}</div>
  </main>;
}

export function UnavailableOpenDay({ organizationSlug }: { organizationSlug: string }) {
  return <main className={styles.publicShell}><section className={styles.emptyState}><p className={styles.eyebrow}>ОТВОРЕНИ ВРАТИ</p><h1>Денят на отворените врати вече приключи</h1><p>Разгледайте актуалните дейности на организацията.</p><Link className={styles.primaryButton} href={`/o/${encodeURIComponent(organizationSlug)}`}>Всички дейности →</Link></section></main>;
}
