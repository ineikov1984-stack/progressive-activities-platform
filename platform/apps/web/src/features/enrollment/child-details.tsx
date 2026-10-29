'use client';

import { type MouseEvent, type ReactNode, useEffect, useRef, useState } from 'react';
import { changeErrorMessage, type ChildDetailsResponse, type DetailsTarget, loadDetails } from './api';
import {
  actorText, childLine, dateText, dateTimeText, historyText, pauseReasonLabels, priceText, queueText, reasonText, recordStatusText, recordTone,
  scheduleText, scopeLabels, sessionText, shortDay, sofiaDay,
} from './presentation';
import styles from './enrollments.module.css';

type Props = { organizationId: string; target: DetailsTarget; onClose: () => void };

/**
 * Everything about a child in this organization, opened from an application or an enrollment
 * (docs/product/enrollment-contacts-and-emails.md, т. 9.1). A modal side panel: the heading takes the
 * focus, Esc and "Затвори" close it and the focus goes back to the button that opened it.
 */
export function ChildDetailsPanel({ organizationId, target, onClose }: Props) {
  const dialog = useRef<HTMLDialogElement>(null);
  const heading = useRef<HTMLHeadingElement>(null);
  const [loaded, setLoaded] = useState<{ key: string; details: ChildDetailsResponse }>();
  const [failed, setFailed] = useState<{ key: string; text: string }>();
  const [attempt, setAttempt] = useState(0);
  const key = `${target.kind}:${target.id}:${attempt}`;

  useEffect(() => {
    const element = dialog.current;
    if (element && !element.open) element.showModal();
    heading.current?.focus();
  }, []);

  useEffect(() => {
    let active = true;
    loadDetails(organizationId, target)
      .then((details) => { if (active) setLoaded({ key, details }); })
      .catch((reason: unknown) => { if (active) setFailed({ key, text: changeErrorMessage(reason, 'Подробностите не се заредиха. Проверете връзката.') }); });
    return () => { active = false; };
  }, [organizationId, target, key]);

  const details = loaded?.key === key ? loaded.details : undefined;
  const error = failed?.key === key ? failed.text : '';
  const name = details?.child.name ?? 'Дете';

  // A click on the dimmed page outside the panel closes it, as Esc does.
  function outside(event: MouseEvent<HTMLDialogElement>) {
    if (event.target === dialog.current) dialog.current?.close();
  }

  return (
    <dialog ref={dialog} className={styles.drawer} aria-labelledby="child-details-title" aria-busy={!details && !error} onClose={onClose} onClick={outside}>
      <div className={styles.drawerInner}>
        <header className={styles.drawerHead}>
          <div className={styles.drawerTitle}>
            <p className={styles.eyebrow}>Подробности</p>
            <h2 id="child-details-title" ref={heading} tabIndex={-1}>{details ? name : 'Зареждане…'}</h2>
            {details && childLine(details.child) ? <p className={styles.small}>{childLine(details.child)}</p> : null}
          </div>
          <button type="button" className={styles.secondary} onClick={() => dialog.current?.close()}>Затвори</button>
        </header>

        {error ? (
          <div className={styles.drawerBody}>
            <p className={styles.error} role="alert">{error}</p>
            <p><button type="button" className={styles.secondary} onClick={() => setAttempt((value) => value + 1)}>Опитай отново</button></p>
          </div>
        ) : !details ? (
          <div className={styles.drawerBody}><span className={styles.skeleton} /><span className={styles.skeleton} /></div>
        ) : (
          <div className={styles.drawerBody}>
            <ParentSection details={details} name={name} />
            <RecordSection details={details} />
            <HereSection details={details} />
            {details.history ? <HistorySection details={details} /> : null}
          </div>
        )}
      </div>
    </dialog>
  );
}

function ParentSection({ details, name }: { details: ChildDetailsResponse; name: string }) {
  if (details.contact_status === 'not_allowed') return null;
  const contact = details.contact;
  return (
    <section className={styles.detailSection} aria-labelledby="details-parent">
      <h3 id="details-parent">Родител</h3>
      {details.contact_status === 'outside_window' || !contact ? (
        <p className={styles.muted}>Контактите на родителя се виждат, докато заявката или записването са активни, и до 90 дни след края.</p>
      ) : (
        <>
          <p className={styles.contactName}>{contact.name}</p>
          <dl className={styles.facts}>
            <div>
              <dt>Телефон</dt>
              <dd>{contact.phone_display ?? 'Родителят още не е добавил телефон.'}</dd>
            </div>
            <div>
              <dt>Имейл</dt>
              <dd>{contact.email}{contact.email_verified ? '' : <span className={styles.smallWarning}> · още не е потвърден</span>}</dd>
            </div>
          </dl>
          <div className={styles.actions}>
            {contact.phone ? <a className={styles.primaryLink} href={`tel:${contact.phone}`}>Обади се<span className={styles.srOnly}> на родителя на {name}</span></a> : null}
            {contact.email ? <a className={styles.secondaryLink} href={`mailto:${contact.email}`}>Пиши<span className={styles.srOnly}> на родителя на {name}</span></a> : null}
          </div>
          {details.parent_note ? (
            <figure className={styles.parentNote}>
              <figcaption>Бележка от родителя</figcaption>
              <blockquote>{details.parent_note}</blockquote>
            </figure>
          ) : null}
        </>
      )}
    </section>
  );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return <div><dt>{label}</dt><dd>{children}</dd></div>;
}

function RecordSection({ details }: { details: ChildDetailsResponse }) {
  const today = sofiaDay();
  if (details.kind === 'application') {
    const application = details.application;
    const waiting = application.status === 'waitlisted';
    return (
      <section className={styles.detailSection} aria-labelledby="details-record">
        <h3 id="details-record">{waiting ? 'Чака място' : application.status === 'submitted' ? 'Нова заявка' : 'Заявка'}</h3>
        <p className={styles.recordTitle}>{application.activity.title} · {application.group.name}</p>
        <dl className={styles.facts}>
          <Fact label="Записване">{application.visit_offer_title ?? scopeLabels[application.scope] ?? 'Записване'}{application.session_starts_at ? ` · ${dateTimeText(application.session_starts_at)}` : ''}</Fact>
          {scheduleText(application.schedule) ? <Fact label="Дни и час">{scheduleText(application.schedule)}</Fact> : null}
          <Fact label="Цена">{priceText(application.price)}</Fact>
          <Fact label="Подадена">{dateText(application.submitted_at)}</Fact>
          {waiting ? <Fact label="Ред">{queueText(application.queue_position)}</Fact> : null}
          {application.public_reason ? <Fact label="Казахме на родителя">„{application.public_reason}“</Fact> : null}
        </dl>
      </section>
    );
  }

  const enrollment = details.enrollment;
  const pause = enrollment.pauses[0];
  const fullPeriod = enrollment.scope === 'full_period';
  return (
    <section className={styles.detailSection} aria-labelledby="details-record">
      <h3 id="details-record">Записване</h3>
      <p className={styles.recordTitle}>{enrollment.activity.title} · {enrollment.group.name}</p>
      <dl className={styles.facts}>
        {!fullPeriod ? <Fact label="Записване">{scopeLabels[enrollment.scope] ?? 'Посещение'}{enrollment.session_starts_at ? ` · ${dateTimeText(enrollment.session_starts_at)}` : ''}</Fact> : null}
        {scheduleText(enrollment.schedule) ? <Fact label="Дни и час">{scheduleText(enrollment.schedule)}</Fact> : null}
        {enrollment.price.amount_cents !== null && enrollment.price.unit ? (
          <Fact label="Цена">{priceText({ amount_cents: enrollment.price.amount_cents, currency: enrollment.price.currency ?? 'EUR', unit: enrollment.price.unit })}</Fact>
        ) : null}
        {fullPeriod ? <Fact label={enrollment.starts_on > today ? 'Започва' : 'Записано от'}>{shortDay(enrollment.starts_on)}</Fact> : null}
        {enrollment.transferred_from ? <Fact label="Преместено от">„{enrollment.transferred_from.group_name ?? 'друга група'}“</Fact> : null}
        {enrollment.moves_to ? <Fact label="Премества се">в „{enrollment.moves_to.group_name ?? 'друга група'}“ от {shortDay(enrollment.moves_to.starts_on)}</Fact>
          : enrollment.ends_on ? <Fact label={enrollment.ends_on >= today ? 'Последен ден' : 'Приключило'}>{shortDay(enrollment.ends_on)}</Fact> : null}
        {pause ? (
          <Fact label={enrollment.paused_today ? 'В пауза' : 'Пауза'}>
            {enrollment.paused_today ? `до ${shortDay(pause.ends_on)}` : `${shortDay(pause.starts_on)} – ${shortDay(pause.ends_on)}`}
            {pauseReasonLabels[pause.reason_category] ? ` · ${pauseReasonLabels[pause.reason_category]}` : ''}
          </Fact>
        ) : null}
        <Fact label="Следващо занимание">{enrollment.next_session_at ? sessionText(enrollment.next_session_at) : 'Няма насрочено'}</Fact>
        {enrollment.skipped_sessions.length > 0 ? (
          <Fact label="Пропуска">{enrollment.skipped_sessions.map((item) => dateText(item.starts_at)).filter(Boolean).join(', ')}</Fact>
        ) : null}
      </dl>
    </section>
  );
}

const toneStyle = { active: styles.recordStatus, waiting: styles.recordWaiting, paused: styles.recordWaiting, ending: styles.recordEnding };

function HereSection({ details }: { details: ChildDetailsResponse }) {
  const today = sofiaDay();
  return (
    <section className={styles.detailSection} aria-labelledby="details-here">
      <h3 id="details-here">Други групи при нас</h3>
      {details.records.length === 0 ? <p className={styles.muted}>Детето няма други групи или заявки при нас.</p> : (
        <ul className={styles.records}>
          {details.records.map((record) => (
            <li key={`${record.kind}-${record.id}`}>
              <strong>{record.activity_title} · {record.group_name}</strong>
              <span className={toneStyle[recordTone(record, today)]}>{recordStatusText(record, today)}</span>
              {scheduleText(record.schedule) ? <span className={styles.small}>{scheduleText(record.schedule)}</span> : null}
              {record.kind === 'enrollment' && record.next_session_at ? <span className={styles.small}>Следващо: {sessionText(record.next_session_at)}</span> : null}
              {record.kind === 'enrollment' && record.next_pause ? <span className={styles.small}>Пауза {shortDay(record.next_pause.starts_on)} – {shortDay(record.next_pause.ends_on)}</span> : null}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

function HistorySection({ details }: { details: ChildDetailsResponse }) {
  const items = details.history ?? [];
  return (
    <section className={styles.detailSection} aria-labelledby="details-history">
      <h3 id="details-history">История</h3>
      {items.length === 0 ? <p className={styles.muted}>Още няма записани промени.</p> : (
        <ol className={styles.history}>
          {items.map((item) => {
            const who = actorText(item.actor);
            return (
              <li key={item.id}>
                <span className={styles.small}>{dateTimeText(item.occurred_at)}{who ? ` · ${who}` : ''}</span>
                <strong>{historyText(item)}</strong>
                {reasonText(item) ? <span>{reasonText(item)}</span> : null}
                {item.internal_note ? <span className={styles.internalNote}>Само за екипа: {item.internal_note}</span> : null}
              </li>
            );
          })}
        </ol>
      )}
    </section>
  );
}
