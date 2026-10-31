'use client';

import Link from 'next/link';
import { type FormEvent, useCallback, useEffect, useRef, useState } from 'react';
import { useDashboardContext } from '@/features/dashboard/dashboard-context';
import { loadStaffGroups } from '@/features/enrollment/api';
import {
  type AccessCard, type AccessChanges, type AccessHistoryEntry, changeRole, loadAccess, loadMember, saveAccess, TeamApiError, type TeamMember,
} from './api';
import { changeableRoles, focusLater, initials, memberStatus, roleLabel, roleLabels } from './presentation';
import {
  accessSummary, applyPartner, cardAreas, defaultScope, draftFromCard, financeLock, firstName, type PickerGroup, payloadFromDraft, rightRows,
  losesFinance, type RightsDraft, type RightsErrors, rightsErrors, rightsHeadline, rightsHistoryText, roleChangeLines, sameDraft, scopeDrops,
  selectionSummary, templateLabel, touchesFinance, withEnd, withRight, withScope,
} from './rights';
import { GroupPicker, RightAreas, RightItems, RightsLegend, ScopeField } from './rights-editor';
import { AccessLists } from './role-access';
import { NoTeamAccess } from './team-page';
import styles from './team.module.css';

const historyTime = new Intl.DateTimeFormat('bg-BG', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Sofia' });
const failure = (reason: unknown, fallback: string) => (reason instanceof Error && reason.message ? reason.message : fallback);

function HistoryLine({ entry }: { entry: AccessHistoryEntry }) {
  return (
    <li>
      <span>{rightsHistoryText(entry)}{entry.by ? ` — ${entry.by}` : ''}</span>
      <span className={styles.small}>{historyTime.format(new Date(entry.at))}</span>
    </li>
  );
}

/** The latest change and "Цялата история": the added rights, the scope, the template, the groups and the contacts shown. */
function RightsHistory({ history }: { history: AccessHistoryEntry[] }) {
  const [latest, ...rest] = history;
  return (
    <section className={styles.card} aria-labelledby="rights-history">
      <h2 id="rights-history">История</h2>
      {latest ? (
        <>
          <ul className={styles.history}><HistoryLine entry={latest} /></ul>
          {rest.length > 0 ? (
            <details className={styles.more}>
              <summary>Цялата история</summary>
              <ul className={styles.history}>{rest.map((entry) => <HistoryLine key={`${entry.action}-${entry.at}-${entry.ability ?? ''}-${entry.group_label ?? ''}`} entry={entry} />)}</ul>
            </details>
          ) : null}
        </>
      ) : <p className={styles.muted}>Още няма промени по правата.</p>}
    </section>
  );
}

type RoleStep = { kind: 'closed' } | { kind: 'choose'; next: string } | { kind: 'preview'; next: string; changes: AccessChanges };

/** "Роля" and "Смени ролята": what the new role takes away is shown first, then the change is confirmed. */
function RoleSection({ organizationId, actorRole, member, card, dirty, onChanged }: {
  organizationId: string; actorRole: string; member: TeamMember; card: AccessCard; dirty: boolean; onChanged: (text: string) => void;
}) {
  const options = member.state === 'login' && card.editable ? changeableRoles(actorRole, card.role) : null;
  const [step, setStep] = useState<RoleStep>({ kind: 'closed' });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  async function preview(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (step.kind !== 'choose' || step.next === card.role) return;
    setBusy(true); setError('');
    try {
      const next = await loadAccess(organizationId, member.id, step.next);
      setStep({ kind: 'preview', next: step.next, changes: next.changes ?? { revoke: [], clear_scope: false, remove_groups: 0, clear_template: false, loses_finance: false } });
      focusLater('role-yes');
    } catch (reason) {
      setError(failure(reason, 'Не успяхме да проверим какво се променя.'));
    } finally {
      setBusy(false);
    }
  }

  async function confirm() {
    if (step.kind !== 'preview') return;
    setBusy(true); setError('');
    try {
      await changeRole(organizationId, member.id, step.next, card.version);
      onChanged(`Ролята на ${member.full_name} вече е „${roleLabel(step.next)}“.`);
    } catch (reason) {
      setError(failure(reason, 'Ролята не беше сменена.'));
      setStep({ kind: 'choose', next: step.next });
    } finally {
      setBusy(false);
    }
  }

  function close() {
    setStep({ kind: 'closed' }); setError('');
    focusLater('role-change');
  }

  const lines = step.kind === 'preview' ? roleChangeLines(step.changes, step.next) : [];
  return (
    <section className={styles.card} aria-labelledby="rights-role">
      <div className={styles.cardHead}>
        <h2 id="rights-role">Роля: {roleLabel(card.role)}</h2>
        {options && step.kind === 'closed' && !dirty ? (
          <button id="role-change" type="button" className={styles.secondary}
            onClick={() => { setStep({ kind: 'choose', next: options.find((role) => role !== card.role) ?? options[0] }); focusLater('role-next'); }}>Смени ролята</button>
        ) : null}
      </div>
      {options && dirty ? <p className={styles.small}>За да смените ролята, първо запазете или откажете промените по правата.</p> : null}
      {member.state !== 'login' && card.state === 'access' ? <p className={styles.small}>Ролята идва от поканата. Сменя се, като изпратите поканата отново с друга роля.</p> : null}
      {error ? <p className={styles.error} role="alert">{error}</p> : null}
      {step.kind === 'choose' && options ? (
        <form className={styles.inline} onSubmit={preview}>
          <div className={styles.field}>
            <label htmlFor="role-next">Нова роля</label>
            <select id="role-next" className={styles.input} value={step.next} onChange={(event) => setStep({ kind: 'choose', next: event.target.value })}>
              {options.filter((role) => role !== card.role).map((role) => <option key={role} value={role}>{roleLabels[role]}</option>)}
            </select>
          </div>
          <div className={styles.actions}>
            <button type="submit" className={styles.primary} disabled={busy} aria-busy={busy}>{busy ? 'Проверка…' : 'Покажи какво се променя'}</button>
            <button type="button" className={styles.secondary} onClick={close}>Отказ</button>
          </div>
        </form>
      ) : null}
      {step.kind === 'preview' ? (
        <div className={styles.confirm} role="group" aria-labelledby="role-confirm">
          <p id="role-confirm">Да сменим ли ролята на {member.full_name} от „{roleLabel(card.role)}“ на „{roleLabel(step.next)}“?</p>
          {lines.length > 0 ? (
            <ul className={styles.dropList} aria-label="Какво отпада">{lines.map((line) => <li key={line}>{line}</li>)}</ul>
          ) : <p className={styles.small}>Нищо от добавеното не отпада.</p>}
          <AccessLists summary={accessSummary({ role: step.next, scope: defaultScope(step.next), grants: {} })} when="later" />
          <div className={styles.actions}>
            <button id="role-yes" type="button" className={styles.primary} disabled={busy} aria-busy={busy} onClick={confirm}>{busy ? 'Смяна…' : 'Да, смени ролята'}</button>
            <button type="button" className={styles.secondary} onClick={close}>Отказ</button>
          </div>
        </div>
      ) : null}
    </section>
  );
}

type FormProps = {
  organizationId: string; actorRole: string; member: TeamMember; card: AccessCard;
  onSaved: (card: AccessCard, text: string) => void; onReload: (text?: string) => void;
};

/** The card under its version: one draft, "Запази промените" sends it whole; a 409 asks to load the new one. */
function RightsForm({ organizationId, actorRole, member, card, onSaved, onReload }: FormProps) {
  const name = firstName(member.full_name);
  const role = card.role ?? '';
  const actorIsOwner = actorRole === 'organization_owner';
  const grantable = card.grantable ?? [];
  const teaches = card.rights.some((right) => right.source === 'assignment');
  const [initial] = useState<RightsDraft>(() => draftFromCard(card));
  const [draft, setDraft] = useState<RightsDraft>(initial);
  const [groups, setGroups] = useState<PickerGroup[]>();
  const [picking, setPicking] = useState(false);
  const [errors, setErrors] = useState<RightsErrors>({ rights: {} });
  const [error, setError] = useState('');
  const [stale, setStale] = useState(false);
  const [busy, setBusy] = useState(false);
  // Finance is confirmed before saving: given ("Да, давам достъп") or taken away by a narrower scope.
  const [asking, setAsking] = useState<'give' | 'lose' | null>(null);
  const [wide] = useState(() => typeof window !== 'undefined' && window.matchMedia('(min-width: 48rem)').matches);
  const errorRef = useRef<HTMLParagraphElement>(null);
  const dirty = !sameDraft(draft, initial);
  const editable = card.editable;

  // The groups to choose from and to name by place: only those who change the card see them all.
  useEffect(() => {
    if (!editable && card.scope !== 'groups') return;
    let active = true;
    loadStaffGroups(organizationId).then((items) => { if (active) setGroups(items); }).catch(() => { if (active) setGroups([]); });
    return () => { active = false; };
  }, [organizationId, editable, card.scope]);

  useEffect(() => {
    if (error) errorRef.current?.focus();
  }, [error]);

  const labels: Record<string, string> = {};
  for (const group of card.groups) labels[`group:${group.group_id}`] = group.label ?? 'Група';
  for (const activity of card.activities) labels[`activity:${activity.activity_id}`] = activity.title ?? 'Дейност';
  const selection = selectionSummary(draft.targets, groups, labels);
  const rowsInput = { role, draft, grantable: editable ? grantable : [], teaches, actorIsOwner };
  const areas = cardAreas.map((area) => ({ area, rows: rightRows(area, rowsInput) }));
  const financeRows = rightRows('finance', rowsInput);
  const financeClosed = financeLock({ name, role, scope: draft.scope, actorIsOwner, grantable });
  const holdsFinance = Object.keys(draft.grants).some((key) => key.startsWith('finance.'));
  const narrowLock = !actorIsOwner && holdsFinance ? 'Това би отнело достъпа до финансите — може само собственикът.' : null;
  const summary = accessSummary({ role, scope: draft.scope, grants: draft.grants, teaches, selected: draft.targets.length });
  const drops = scopeDrops(draft);
  // Twice in the page, one shown at a time (CSS): beside the card when there is room, before the rights otherwise.
  const will = (id: string) => (
    <>
      <h2 id={id}>Какво ще може {name}</h2>
      <AccessLists summary={summary} when="now" />
      {role === 'instructor' ? <p className={styles.small}>Своите групи — където е водещ, помощник или заместник; добавят се в профила.</p> : null}
    </>
  );
  const change = (next: RightsDraft) => { setDraft(next); setErrors({ rights: {} }); setError(''); };

  async function save(confirmed: boolean) {
    if (draft.scope === 'groups' && draft.targets.length === 0) {
      setErrors({ rights: {}, groups: 'Изберете поне една група.' });
      setPicking(true);
      focusLater('rights-groups-open');
      return;
    }
    if (losesFinance(draft, initial) && !confirmed) {
      setAsking('lose');
      focusLater('finance-yes');
      return;
    }
    const finance = touchesFinance(draft, initial);
    if (finance && !confirmed) {
      setAsking('give');
      focusLater('finance-yes');
      return;
    }
    setAsking(null); setBusy(true); setError(''); setStale(false);
    const payload = payloadFromDraft(role, draft, finance);
    try {
      const saved = await saveAccess(organizationId, member.id, payload, card.version);
      onSaved(saved, `Правата на ${member.full_name} са запазени.`);
    } catch (reason) {
      if (reason instanceof TeamApiError && reason.status === 409) {
        setStale(true);
        setError('Правата са променени междувременно. Заредете новите права и направете промените отново.');
      } else {
        const mapped = reason instanceof TeamApiError ? rightsErrors(reason.fieldErrors, payload) : { rights: {} };
        setErrors(mapped);
        setError(mapped.general ?? failure(reason, 'Правата не бяха запазени.'));
      }
    } finally {
      setBusy(false);
    }
  }

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    void save(false);
  }

  const onToggle = (key: string, on: boolean) => change(withRight(role, draft, key, on));
  const onEnd = (key: string, lastDay: string | null) => change(withEnd(draft, key, lastDay));

  return (
    <div className={styles.rightsLayout}>
      <div className={styles.rightsColumn}>
        <RoleSection organizationId={organizationId} actorRole={actorRole} member={member} card={card} dirty={dirty} onChanged={(text) => onReload(text)} />
        <form className={styles.rightsMain} onSubmit={submit} noValidate aria-label="Права">

          {!editable && card.reason ? <p className={styles.info}>{card.reason}</p> : null}

          <div className={styles.card}>
            {editable ? (
              <ScopeField idPrefix="rights" allowed={card.allowed_scopes} value={draft.scope} lock={narrowLock} error={errors.scope}
                onChange={(scope) => { change(withScope(role, draft, scope)); if (scope === 'groups' && draft.targets.length === 0) setPicking(true); }} />
            ) : <p className={styles.muted}>За кои групи: <strong className={styles.strong}>{draft.scope === 'own' ? 'Своите групи' : draft.scope === 'groups' ? 'Избрани групи' : 'Цялата организация'}</strong></p>}
            {/* Never silently: what the narrower scope takes away, and that it comes back. */}
            <p className={drops ? styles.dropNote : 'sr-only'} role="status">
              {drops ? `${drops} Връщат се, ако изберете отново „Цялата организация“.` : ''}
            </p>
            {draft.scope === 'groups' ? (
              <div className={styles.selection}>
                {selection.count > 0 ? (
                  <p>{selection.place ? <>По място <strong className={styles.strong}>{selection.place}</strong>, {selection.count === 1 ? '1 група' : `${selection.count} групи`}: </> : null}{selection.text}</p>
                ) : <p className={styles.muted}>Още няма избрани групи.</p>}
                {errors.groups ? <p className={styles.fieldError} id="rights-groups-error">{errors.groups}</p> : null}
                {editable ? (
                  <div className={styles.actions}>
                    <button id="rights-groups-open" type="button" className={styles.secondary} aria-expanded={picking} aria-controls="rights-picker"
                      aria-describedby={errors.groups ? 'rights-groups-error' : undefined} onClick={() => setPicking((value) => !value)}>
                      {picking ? 'Скрий групите' : 'Промени групите'}
                    </button>
                  </div>
                ) : null}
                {editable && picking ? (
                  <div id="rights-picker">
                    {groups ? <GroupPicker idPrefix="rights" groups={groups} selected={draft.targets} onChange={(targets) => change({ ...draft, targets })} />
                      : <span className={styles.skeleton} aria-label="Зареждане на групите" />}
                  </div>
                ) : null}
              </div>
            ) : null}
            {role === 'viewer' && editable ? (
              draft.template ? (
                <p id="partner-done" tabIndex={-1} className={styles.small}><span className={`${styles.badge} ${styles.tone_info}`}>{templateLabel(draft.template)}</span> „Само преглед“ с избрани групи, записване и присъствия. Без финанси.</p>
              ) : (
                <div className={styles.actions}>
                  <button type="button" className={styles.secondary} aria-describedby="partner-hint"
                    disabled={!grantable.includes('enrollment.enroll') || !grantable.includes('attendance.mark')}
                    onClick={() => { change(applyPartner(draft)); setPicking(true); focusLater('partner-done'); }}>Попълни като „Партньор от училище“</button>
                  <p id="partner-hint" className={styles.small}>Избрани групи, „Записва деца“ и „Отбелязва присъствия“. Без финанси.</p>
                </div>
              )
            ) : null}
            {errors.template ? <p className={styles.fieldError}>{errors.template}</p> : null}
          </div>

          {/* On a phone and a tablet what the person will be able to do comes before the rights; beside them when there is room. */}
          <section className={`${styles.card} ${styles.narrowOnly}`} aria-labelledby="rights-will">{will('rights-will')}</section>

          <section className={styles.card} aria-labelledby="rights-extra">
            <div className={styles.cardHead}>
              <h2 id="rights-extra">Допълнителни права</h2>
              <RightsLegend />
            </div>
            <RightAreas idPrefix="rights" areas={areas} errors={errors.rights} startOpen={wide} onToggle={onToggle} onEnd={onEnd} />
          </section>

          <section className={`${styles.card} ${styles.financeCard}`} aria-labelledby="rights-finance">
            <div className={styles.cardHead}>
              <h2 id="rights-finance">Финанси</h2>
              {financeClosed ? <span className={`${styles.badge} ${styles.tone_muted}`}>заключено</span> : null}
            </div>
            {financeClosed ? <p>{financeClosed}</p> : null}
            <RightItems idPrefix="rights-finance" rows={financeClosed ? financeRows.map((row) => ({ ...row, editable: false })) : financeRows}
              errors={errors.rights} onToggle={onToggle} onEnd={onEnd} />
            <p className={styles.small}>Дава ги само собственикът, след потвърждение.</p>
            {asking ? (
              <div className={styles.confirm} role="group" aria-labelledby="finance-confirm">
                <p id="finance-confirm">{asking === 'give'
                  ? `Давате на ${member.full_name} достъп до таксите и плащанията на цялата организация.`
                  : `С „${draft.scope === 'own' ? 'Своите групи' : 'Избрани групи'}“ ${member.full_name} губи достъпа до таксите и плащанията. Да го махнем ли?`}</p>
                <div className={styles.actions}>
                  <button id="finance-yes" type="button" className={styles.primary} disabled={busy} onClick={() => void save(true)}>
                    {asking === 'give' ? 'Да, давам достъп' : 'Да, махни финансите'}
                  </button>
                  <button type="button" className={styles.secondary} onClick={() => setAsking(null)}>Отказ</button>
                </div>
              </div>
            ) : null}
          </section>

          {card.history ? <RightsHistory history={card.history} /> : null}

          {error ? (
            <div className={styles.saveError}>
              <p ref={errorRef} tabIndex={-1} className={styles.error} role="alert">{error}</p>
              {stale ? <div className={styles.actions}><button type="button" className={styles.secondary} onClick={() => onReload()}>Зареди новите права</button></div> : null}
            </div>
          ) : null}

          {editable ? (
            <div className={styles.saveBar}>
              <Link className={styles.secondary} href={`/dashboard/team/${encodeURIComponent(member.id)}`}>Отказ</Link>
              <button type="submit" className={styles.primary} disabled={busy || !dirty} aria-busy={busy}>{busy ? 'Запазване…' : 'Запази промените'}</button>
            </div>
          ) : null}
        </form>
      </div>

      <aside className={`${styles.rightsAside} ${styles.wideOnlyBlock}`} aria-labelledby="rights-will-wide">
        <section className={styles.card}>{will('rights-will-wide')}</section>
      </aside>
    </div>
  );
}

/**
 * "Права на {име}" (docs/product/team-rights.md, т. 10; макет „Екип · права и допълнителни права“): the role, the
 * scope, the added rights by area with an optional end, finance apart, what the person will be able to do, and the
 * history. Opened from "Права" in the profile.
 */
export function MemberRights({ memberId }: { memberId: string }) {
  const { organization } = useDashboardContext();
  const canView = organization.permissions.includes('team.view');
  const canManage = organization.permissions.includes('team.manage');
  const [attempt, setAttempt] = useState(0);
  const [loaded, setLoaded] = useState<{ key: string; member: TeamMember; card: AccessCard }>();
  const [failed, setFailed] = useState<{ key: string; text: string; status: number }>();
  const [notice, setNotice] = useState<{ text: string }>();
  const [now] = useState(() => new Date());
  const noticeRef = useRef<HTMLParagraphElement>(null);
  const key = `${organization.id}|${memberId}|${attempt}`;

  useEffect(() => {
    if (!canView) return;
    let active = true;
    Promise.all([loadMember(organization.id, memberId), loadAccess(organization.id, memberId)])
      .then(([details, card]) => { if (active) setLoaded({ key, member: details.member, card }); })
      .catch((reason: unknown) => {
        if (active) setFailed({ key, text: failure(reason, 'Правата не се заредиха.'), status: reason instanceof TeamApiError ? reason.status : 0 });
      });
    return () => { active = false; };
  }, [canView, organization.id, memberId, key]);

  useEffect(() => {
    if (!notice) return;
    noticeRef.current?.scrollIntoView({ block: 'nearest' });
    noticeRef.current?.focus();
  }, [notice]);

  const reload = useCallback((text?: string) => {
    if (text) setNotice({ text });
    setAttempt((value) => value + 1);
  }, []);
  const saved = useCallback((card: AccessCard, text: string) => {
    setLoaded((current) => (current ? { ...current, card, member: { ...current.member, version: card.version } } : current));
    setNotice({ text });
  }, []);

  const current = loaded?.member.id === memberId ? loaded : undefined;
  const error = failed?.key === key ? failed : undefined;
  const profile = `/dashboard/team/${encodeURIComponent(memberId)}`;

  if (!canView) {
    return <div className={styles.page}><Link className={styles.back} href="/dashboard/team"><span aria-hidden="true">←&nbsp;</span>Екип</Link><NoTeamAccess /></div>;
  }
  if (!current) {
    return (
      <div className={styles.page} aria-busy={!error}>
        <Link className={styles.back} href={profile}><span aria-hidden="true">←&nbsp;</span>Профилът</Link>
        {error ? (
          <div className={styles.empty} role="alert">
            <strong>{error.status === 404 ? 'Не намерихме този профил' : error.text}</strong>
            {error.status === 404 ? <p><Link href="/dashboard/team">Към екипа</Link></p>
              : <p><button type="button" className={styles.secondary} onClick={() => reload()}>Опитай отново</button></p>}
          </div>
        ) : (
          <>
            <span className="sr-only" role="status">Зареждане на правата…</span>
            <span className={styles.skeleton} />
            <div className={styles.skeletonList}><span className={styles.skeleton} /><span className={styles.skeleton} /></div>
          </>
        )}
      </div>
    );
  }

  const { member, card } = current;
  const status = memberStatus(member, now);
  const label = templateLabel(card.template);
  return (
    <div className={styles.page}>
      <Link className={styles.back} href={profile}><span aria-hidden="true">←&nbsp;</span>Профилът на {firstName(member.full_name)}</Link>
      <header className={styles.profileHead}>
        <span className={styles.avatar} aria-hidden="true">{initials(member.full_name)}</span>
        <div className={styles.heading}>
          <h1 id="rights-title">Права на {member.full_name}</h1>
          <p className={styles.headLine}>
            {card.state === 'access' ? <span className={styles.headRole}>{rightsHeadline(card.role, draftFromCard(card))}</span> : null}
            {label ? <span className={`${styles.badge} ${styles.tone_info}`}>{label}</span> : null}
            <span className={`${styles.badge} ${styles[`tone_${status.tone}`]}`}>{status.label}</span>
          </p>
        </div>
      </header>

      <p ref={noticeRef} tabIndex={-1} className={notice ? styles.ok : 'sr-only'} role="status">{notice?.text ?? ''}</p>

      {card.state === 'no_access' ? (
        <section className={`${styles.card} ${styles.cardWarm}`} aria-label="Няма достъп">
          <p>Няма достъп — правата се дават заедно с поканата.</p>
          {canManage ? <div className={styles.actions}><Link className={styles.primary} href={`${profile}?invite=1`}>Изпрати покана</Link></div> : null}
        </section>
      ) : card.state === 'archived' ? (
        <p className={styles.info}>Записът е в архива — няма достъп и права в организацията.</p>
      ) : (
        <RightsForm key={`${card.version}-${attempt}`} organizationId={organization.id} actorRole={organization.role} member={member} card={card}
          onSaved={saved} onReload={reload} />
      )}
    </div>
  );
}

