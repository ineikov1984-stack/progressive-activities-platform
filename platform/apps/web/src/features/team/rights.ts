import { sofiaDay } from '../enrollment/presentation';
import type { AccessCard, AccessChanges, AccessHistoryEntry, AccessPayload, AccessScope, AccessTarget } from './api';
import { assignmentRole, roleLabel, type TeamRole } from './presentation';

/*
 * The rights of a person of the team in words (docs/product/team-rights.md, т. 2–4, 10). The lists mirror
 * TeamAbilities and OrganizationRole::abilities in the API; the API decides, the screen only explains and offers.
 * The keys are never shown.
 */

export type RightArea = 'enrollment' | 'attendance' | 'schedule' | 'contacts' | 'team' | 'finance' | 'settings';

export const rightAreas: RightArea[] = ['enrollment', 'attendance', 'schedule', 'contacts', 'team', 'finance', 'settings'];

export const areaLabels: Record<RightArea, string> = {
  enrollment: 'Деца и записвания',
  attendance: 'Присъствия',
  schedule: 'График',
  contacts: 'Контакти на родителите',
  team: 'Екип',
  finance: 'Финанси',
  settings: 'Настройки',
};

export const areaRights: Record<RightArea, string[]> = {
  enrollment: ['enrollment.view', 'enrollment.view_own', 'enrollment.enroll'],
  attendance: ['attendance.mark', 'attendance.correct_late', 'attendance.view_month'],
  schedule: ['schedule.view_all', 'schedule.manage'],
  contacts: ['contacts.view', 'contacts.message'],
  team: ['team.view', 'team.assign', 'team.manage'],
  finance: ['finance.view', 'finance.collect', 'finance.pay_view'],
  settings: ['catalog.manage', 'catalog.publish', 'terms.manage'],
};

/** The name and one sentence of what it means, as in the card. */
export const rightTexts: Record<string, { label: string; hint: string }> = {
  'enrollment.view': { label: 'Вижда децата и записванията', hint: 'Децата в групите — име и клас, заявките и чакащите.' },
  'enrollment.view_own': { label: 'Вижда своите групи', hint: 'Децата в групите, които води — име и клас.' },
  'enrollment.enroll': { label: 'Записва деца', hint: 'Добавя дете в група, одобрява заявки, мести и спира.' },
  'attendance.mark': { label: 'Отбелязва присъствия', hint: 'Поправя до края на следващия ден.' },
  'attendance.correct_late': { label: 'Поправя след срока', hint: 'С причина.' },
  'attendance.view_month': { label: 'Вижда присъствията за месеца', hint: 'Таблицата по дати — само за групите в обхвата.' },
  'schedule.view_all': { label: 'Вижда целия график', hint: 'Всички групи, само за преглед.' },
  'schedule.manage': { label: 'Променя графика', hint: 'Мести и отменя занимания, сменя зала, слага заместник.' },
  'contacts.view': { label: 'Вижда телефона и имейла', hint: 'За спешно обаждане. Всяко показване остава в историята.' },
  'contacts.message': { label: 'Пише до родителите', hint: 'Съобщение до групата през системата, без да вижда номерата.' },
  'team.view': { label: 'Вижда екипа', hint: 'Кой е в екипа, ролите и групите — без телефоните.' },
  'team.assign': { label: 'Слага учители в групите', hint: 'Водещ, помощник или заместник, от–до.' },
  'team.manage': { label: 'Добавя хора и дава права', hint: 'Покани, данни, роли, архив.' },
  'finance.view': { label: 'Вижда таксите и плащанията', hint: 'Дължимото, платеното, отчетите.' },
  'finance.collect': { label: 'Приема плащания', hint: 'В брой и по банка.' },
  'finance.pay_view': { label: 'Вижда възнагражденията', hint: 'На целия екип; своето учителят вижда винаги.' },
  'catalog.manage': { label: 'Създава и променя дейности', hint: 'Дейности, групи, цени, места.' },
  'catalog.publish': { label: 'Публикува на сайта', hint: 'Родителите виждат дейността.' },
  'terms.manage': { label: 'Условия за плащане и данни', hint: 'Таксите, отсъствията, данните на организацията.' },
};

export const rightLabel = (key: string): string => rightTexts[key]?.label ?? 'Право';

/** What may be added to a person (TeamAbilities::GRANTABLE). */
export const GRANTABLE = [
  'enrollment.enroll', 'attendance.mark', 'attendance.view_month', 'schedule.view_all', 'schedule.manage',
  'contacts.view', 'contacts.message', 'team.view', 'team.assign', 'finance.view', 'finance.collect',
  'finance.pay_view', 'catalog.manage', 'catalog.publish',
];
/** Only with a role, never added (the starred rights of the proposal). */
export const ROLE_ONLY = ['attendance.correct_late', 'team.manage', 'terms.manage'];
export const FINANCE = ['finance.view', 'finance.collect', 'finance.pay_view'];
/** Not limited to a group: given only with "Цялата организация". */
export const WHOLE_ORGANIZATION = [
  'schedule.manage', 'team.assign', 'team.manage', 'terms.manage', 'catalog.manage', 'catalog.publish', ...FINANCE,
];
/** What leading a group gives, without a checkbox. */
export const TEACHING = ['enrollment.view_own', 'attendance.mark'];
/** An added right brings these along (TeamAbilities::IMPLIES, only the ones the card shows). */
export const IMPLIES: Record<string, string[]> = {
  'enrollment.enroll': ['enrollment.view'],
  'team.assign': ['team.view'],
  'catalog.publish': ['catalog.manage'],
};
export const SCHOOL_PARTNER = ['enrollment.enroll', 'attendance.mark'];
export const SCHOOL_PARTNER_TEMPLATE = 'school_partner';

/** The rights each role carries, in the card's terms (OrganizationRole::abilities). */
const roleRights: Record<TeamRole, string[]> = {
  organization_owner: [
    'enrollment.view', 'enrollment.enroll', 'attendance.mark', 'attendance.correct_late', 'attendance.view_month', 'schedule.manage',
    'contacts.view', 'contacts.message', 'team.view', 'team.assign', 'team.manage', ...FINANCE, 'catalog.manage', 'catalog.publish', 'terms.manage',
  ],
  organization_admin: [
    'enrollment.view', 'enrollment.enroll', 'attendance.mark', 'attendance.correct_late', 'attendance.view_month', 'schedule.manage',
    'contacts.view', 'contacts.message', 'team.view', 'team.assign', 'team.manage', 'catalog.manage', 'catalog.publish', 'terms.manage',
  ],
  coordinator: [
    'enrollment.view', 'enrollment.enroll', 'attendance.mark', 'attendance.view_month', 'schedule.manage',
    'contacts.view', 'contacts.message', 'team.view', 'team.assign', 'catalog.manage',
  ],
  instructor: ['enrollment.view_own'],
  finance: ['enrollment.view', 'attendance.view_month', 'team.view', ...FINANCE],
  viewer: ['enrollment.view', 'attendance.view_month', 'team.view'],
};

export function rightsOfRole(role: string | null | undefined): string[] {
  return role && role in roleRights ? roleRights[role as TeamRole] : [];
}

/** The scopes a role allows and the one it starts with (OrganizationRole::allowedScopes, defaultScope). */
export function allowedScopes(role: string | null | undefined): AccessScope[] {
  if (role === 'instructor') return ['own', 'groups', 'all'];
  if (role === 'viewer') return ['all', 'groups'];
  return ['all'];
}

export function defaultScope(role: string | null | undefined): AccessScope {
  return role === 'instructor' ? 'own' : 'all';
}

export const scopeLabels: Record<AccessScope, string> = { own: 'Своите групи', groups: 'Избрани групи', all: 'Цялата организация' };

/**
 * What the signed-in person may add to someone with the role (SaveStaffAccess::grantable) when the card does not say
 * it yet — in "Добави в екипа". Finance is left for the card, where it is confirmed.
 */
export function grantableFor(actorRole: string, role: string): string[] {
  if (actorRole !== 'organization_owner' && actorRole !== 'organization_admin') return [];
  const gives = rightsOfRole(role);
  return GRANTABLE.filter((key) => !gives.includes(key) && !FINANCE.includes(key));
}

/*
 * The draft of the card: the scope, the selected groups ("group:<id>" or "activity:<id>"), the added rights with
 * their last day and the template's label. `parked` holds what a narrower scope took away, with its end, so that
 * "Цялата организация" chosen again brings it back; it is never sent.
 */
export type RightsDraft = {
  scope: AccessScope; targets: string[]; grants: Record<string, string | null>; template: string | null;
  parked: Record<string, string | null>;
};

export const groupTarget = (id: string) => `group:${id}`;
export const activityTarget = (id: string) => `activity:${id}`;

export function draftFromCard(card: Pick<AccessCard, 'role' | 'scope' | 'groups' | 'activities' | 'rights' | 'template'>): RightsDraft {
  const grants: Record<string, string | null> = {};
  for (const right of card.rights) if (right.source === 'grant' && !right.implied) grants[right.key] = right.expires_on;
  return {
    scope: card.scope ?? defaultScope(card.role),
    targets: [...card.groups.map((group) => groupTarget(group.group_id)), ...card.activities.map((activity) => activityTarget(activity.activity_id))],
    grants,
    template: card.template,
    parked: {},
  };
}

export const emptyDraft = (role: string): RightsDraft => ({ scope: defaultScope(role), targets: [], grants: {}, template: null, parked: {} });

/** "Партньор от училище": "Само преглед" with selected groups, enrolling and attendance (SaveStaffAccess::template). */
export function partnerFits(role: string | null | undefined, draft: Pick<RightsDraft, 'scope' | 'grants'>): boolean {
  return role === 'viewer' && draft.scope === 'groups' && SCHOOL_PARTNER.every((key) => key in draft.grants);
}

export function applyPartner(draft: RightsDraft): RightsDraft {
  const narrowed = withScope('viewer', draft, 'groups');
  const grants = { ...narrowed.grants };
  for (const key of SCHOOL_PARTNER) if (!(key in grants)) grants[key] = null;
  return { ...narrowed, grants, template: SCHOOL_PARTNER_TEMPLATE };
}

/**
 * A new scope. A narrow one takes away what is given only for the whole organization and keeps it aside
 * ("Ще отпаднат: …"); "Цялата организация" brings it back. The template's label stays only while it still fits.
 */
export function withScope(role: string | null | undefined, draft: RightsDraft, scope: AccessScope): RightsDraft {
  let grants: Record<string, string | null>;
  let parked: Record<string, string | null>;
  if (scope === 'all') {
    grants = { ...draft.parked, ...draft.grants };
    parked = {};
  } else {
    grants = {};
    parked = { ...draft.parked };
    for (const [key, end] of Object.entries(draft.grants)) {
      if (WHOLE_ORGANIZATION.includes(key)) parked[key] = end;
      else grants[key] = end;
    }
  }
  const next = { ...draft, scope, grants, parked };
  return { ...next, template: next.template && partnerFits(role, next) ? next.template : null };
}

/** "Ще отпаднат: Променя графика, Приема плащания" — what the narrower scope takes away; null when nothing. */
export function scopeDrops(draft: Pick<RightsDraft, 'parked'>): string | null {
  const keys = Object.keys(draft.parked);
  return keys.length > 0 ? `Ще отпаднат: ${keys.map(rightLabel).join(', ')} — дават се само с „Цялата организация“.` : null;
}

/** The narrower scope takes away finance the person has: that is asked first, as a change of role is. */
export function losesFinance(draft: Pick<RightsDraft, 'parked'>, before: Pick<RightsDraft, 'grants'>): boolean {
  return FINANCE.some((key) => key in draft.parked && key in before.grants);
}

export function withRight(role: string | null | undefined, draft: RightsDraft, key: string, on: boolean): RightsDraft {
  const grants = { ...draft.grants };
  if (on) grants[key] = grants[key] ?? null;
  else delete grants[key];
  const next = { ...draft, grants };
  return { ...next, template: next.template && partnerFits(role, next) ? next.template : null };
}

export function withEnd(draft: RightsDraft, key: string, lastDay: string | null): RightsDraft {
  return key in draft.grants ? { ...draft, grants: { ...draft.grants, [key]: lastDay } } : draft;
}

/** Whole finance rights the draft adds to what the card has: they are given only after "Да, давам достъп". */
export function addsFinance(draft: RightsDraft, before: RightsDraft | null): boolean {
  return FINANCE.some((key) => key in draft.grants && !(before && key in before.grants));
}

function target(key: string): AccessTarget {
  return key.startsWith('activity:') ? { activity_id: key.slice('activity:'.length) } : { group_id: key.slice('group:'.length) };
}

/**
 * The body of "Запази промените": the whole card. The added rights the signed-in person may not give come back
 * unchanged — the API keeps them and refuses any change to them.
 */
export function payloadFromDraft(role: string | null | undefined, draft: RightsDraft, confirmFinance = false): AccessPayload {
  return {
    scope: draft.scope,
    groups: draft.scope === 'groups' ? draft.targets.map(target) : [],
    grants: Object.entries(draft.grants).map(([ability, expiresOn]) => ({ ability, expires_on: expiresOn })),
    template: draft.template && partnerFits(role, draft) ? draft.template : null,
    ...(confirmFinance ? { confirm_finance: true } : {}),
  };
}

export function sameDraft(a: RightsDraft, b: RightsDraft): boolean {
  const grants = (draft: RightsDraft) => JSON.stringify(Object.entries(draft.grants).sort(([x], [y]) => x.localeCompare(y)));
  const targets = (draft: RightsDraft) => (draft.scope === 'groups' ? [...draft.targets].sort().join('|') : '');
  return a.scope === b.scope && targets(a) === targets(b) && grants(a) === grants(b) && (a.template ?? null) === (b.template ?? null);
}

/* One row of the card: a checkbox with its name, sentence and label. */

export type RightRow = {
  key: string;
  label: string;
  hint: string;
  checked: boolean;
  /** "от ролята", "добавено", "само с роля"…; the words carry the state, not the colour. */
  tag: string | null;
  tone: 'role' | 'added' | 'locked' | 'none';
  /** Why it cannot be changed here, in words. */
  lock: string | null;
  /** Can be ticked or unticked by the signed-in person. */
  editable: boolean;
  /** An added right: it may have an end. */
  added: boolean;
  lastDay: string | null;
};

export type RowsInput = {
  role: string;
  draft: RightsDraft;
  /** What the signed-in person may add (the card's `grantable`). */
  grantable: string[];
  /** Leads or assists a group: those rights come from the groups. */
  teaches: boolean;
  actorIsOwner: boolean;
};

function rowFor(key: string, input: RowsInput): RightRow | null {
  const { role, draft, grantable, teaches } = input;
  const text = rightTexts[key] ?? { label: rightLabel(key), hint: '' };
  const base = { key, label: text.label, hint: text.hint, lastDay: null, added: false };
  const narrow = draft.scope !== 'all';
  if (rightsOfRole(role).includes(key)) return { ...base, checked: true, tag: 'от ролята', tone: 'role', lock: null, editable: false };
  if (teaches && TEACHING.includes(key)) return { ...base, checked: true, tag: 'от групите, които води', tone: 'role', lock: null, editable: false };
  if (key in draft.grants) {
    const mayChange = grantable.includes(key) && !(narrow && WHOLE_ORGANIZATION.includes(key));
    return {
      ...base, checked: true, tag: 'добавено', tone: 'added', added: true, lastDay: draft.grants[key] ?? null,
      lock: mayChange ? null : 'Дава ги само собственикът.', editable: mayChange,
    };
  }
  if (key === 'schedule.view_all' && !narrow) {
    return { ...base, checked: true, tag: 'с цялата организация', tone: 'role', lock: null, editable: false };
  }
  const bringer = Object.keys(draft.grants).find((granted) => IMPLIES[granted]?.includes(key));
  if (bringer) return { ...base, checked: true, tag: `идва с „${rightLabel(bringer)}“`, tone: 'added', lock: null, editable: false };
  if (ROLE_ONLY.includes(key)) {
    return { ...base, checked: false, tag: 'само с роля', tone: 'locked', lock: 'Само собственикът и администраторът.', editable: false };
  }
  if (!GRANTABLE.includes(key)) return null;
  if (narrow && WHOLE_ORGANIZATION.includes(key)) {
    // Finance says why once, for the whole section.
    return { ...base, checked: false, tag: null, tone: 'locked', lock: FINANCE.includes(key) ? null : 'Дава се само с „Цялата организация“.', editable: false };
  }
  if (!grantable.includes(key)) {
    // Finance says why once, for the whole section.
    return { ...base, checked: false, tag: null, tone: 'locked', lock: FINANCE.includes(key) ? null : 'Дава ги само собственикът.', editable: false };
  }
  return { ...base, checked: false, tag: null, tone: 'none', lock: null, editable: true };
}

export function rightRows(area: RightArea, input: RowsInput): RightRow[] {
  return areaRights[area].map((key) => rowFor(key, input)).filter((row): row is RightRow => row !== null);
}

/** "1 добавено · 1 от ролята", "нищо добавено", "заключено". */
export function areaSummary(rows: RightRow[]): string {
  const added = rows.filter((row) => row.added).length;
  const fromRole = rows.filter((row) => row.checked && !row.added && row.tone === 'role').length;
  if (added === 0 && fromRole === 0 && rows.length > 0 && rows.every((row) => !row.editable)) return 'заключено';
  const parts = [
    ...(added > 0 ? [added === 1 ? '1 добавено' : `${added} добавени`] : []),
    ...(fromRole > 0 ? [`${fromRole} от ролята`] : []),
  ];
  return parts.length > 0 ? parts.join(' · ') : 'нищо добавено';
}

/** The areas of the card without finance, which has a section of its own. */
export const cardAreas = rightAreas.filter((area) => area !== 'finance');

/** "Само преглед + 2 права"; without additions only the role. */
export function rightsHeadline(role: string | null | undefined, draft: Pick<RightsDraft, 'grants'>): string {
  const count = Object.keys(draft.grants).length;
  if (count === 0) return roleLabel(role);
  return `${roleLabel(role)} + ${count} ${count === 1 ? 'право' : 'права'}`;
}

export function templateLabel(template: string | null | undefined): string | null {
  return template === SCHOOL_PARTNER_TEMPLATE ? 'Партньор от училище' : null;
}

/** "Като преподавател", "Със „Само преглед“". */
export function roleAs(role: string): string {
  if (role === 'viewer') return 'Със „Само преглед“';
  return `Като ${roleLabel(role).toLocaleLowerCase('bg-BG')}`;
}

/** Why finance is closed here, or null when the owner may give it. */
export function financeLock(input: { name: string; role: string; scope: AccessScope; actorIsOwner: boolean; grantable: string[] }): string | null {
  if (FINANCE.every((key) => rightsOfRole(input.role).includes(key))) return null;
  if (input.scope === 'groups') return `${input.name} има избрани групи, а финансите са за цялата организация — затова тук не се дават.`;
  if (input.scope === 'own') return `${input.name} има само своите групи, а финансите са за цялата организация — затова тук не се дават.`;
  if (!input.actorIsOwner) return null;
  return FINANCE.some((key) => input.grantable.includes(key)) ? null : 'Финансите още не са включени в системата.';
}

/* "Може да / Ще вижда / Няма да вижда" — computed from the role, the scope and the additions (т. 10). */

export type AccessSummary = { can: string[]; sees: string[]; hidden: string[] };
export type SummaryInput = {
  role: string;
  scope: AccessScope;
  grants: Record<string, string | null>;
  /** Leads or assists groups (a teacher always will). */
  teaches?: boolean;
  /** How many groups and activities are selected, for "в тези 4 групи". */
  selected?: number;
};

const months = ['януари', 'февруари', 'март', 'април', 'май', 'юни', 'юли', 'август', 'септември', 'октомври', 'ноември', 'декември'];

/** "30 юни 2027" from "2027-06-30". */
export function longDay(day: string): string {
  const [year, month, date] = day.split('-').map(Number);
  return year && month && date ? `${date} ${months[month - 1]} ${year}` : day;
}

/** The last day of an added right from its end (the start of the next day in Sofia). */
export function lastDayOf(expiresAt: string): string {
  const next = Date.parse(`${sofiaDay(expiresAt)}T12:00:00Z`) - 86_400_000;
  return new Date(next).toISOString().slice(0, 10);
}

export function effectiveRights(input: SummaryInput): Set<string> {
  const rights = new Set(rightsOfRole(input.role));
  for (const key of Object.keys(input.grants)) {
    rights.add(key);
    for (const brought of IMPLIES[key] ?? []) rights.add(brought);
  }
  if (input.teaches || input.role === 'instructor') for (const key of TEACHING) rights.add(key);
  if (input.scope === 'all') rights.add('schedule.view_all');
  return rights;
}

export function accessSummary(input: SummaryInput): AccessSummary {
  const { role, scope } = input;
  if (role === 'organization_owner') {
    return { can: ['всичко в организацията, включително правата на екипа'], sees: ['контактите на родителите и финансите'], hidden: [] };
  }
  if (role === 'organization_admin') {
    return {
      can: ['записва деца, отбелязва и поправя присъствия, променя графика', 'добавя хора в екипа и дава права', 'създава и публикува дейности, променя условията за плащане'],
      sees: ['всички групи, децата и контактите на родителите', 'целия екип'],
      hidden: ['финансите'],
    };
  }
  const has = effectiveRights(input);
  const end = (key: string) => (input.grants[key] ? ` — до ${longDay(input.grants[key] as string)}` : '');
  const count = input.selected ?? 0;
  const where = scope === 'own' ? ' в своите групи'
    : scope === 'groups' ? (count > 1 ? ` в тези ${count} групи` : count === 1 ? ' в тази група' : ' в избраните групи') : '';
  const fromGroups = !rightsOfRole(role).includes('attendance.mark') && !('attendance.mark' in input.grants);

  const can: string[] = [];
  if (has.has('enrollment.enroll')) can.push(`записва деца${where}${end('enrollment.enroll')}`);
  if (has.has('attendance.mark')) {
    if (fromGroups) {
      can.push('отбелязва присъствия в своите групи — в деня на заниманието');
      can.push('поправя отбелязаното до края на следващия ден; всяка промяна остава в историята');
    } else can.push(`отбелязва присъствия${where} и поправя до края на следващия ден${end('attendance.mark')}`);
  }
  if (has.has('attendance.correct_late')) can.push('поправя присъствия и след срока, с причина');
  if (has.has('schedule.manage')) can.push(`мести и отменя занимания, сменя зала и слага заместник${end('schedule.manage')}`);
  if (has.has('contacts.message')) can.push(`пише до родителите през системата${end('contacts.message')}`);
  if (has.has('team.assign')) can.push(`слага учители в групите${end('team.assign')}`);
  if (has.has('finance.collect')) can.push(`приема плащания${end('finance.collect')}`);
  if (has.has('catalog.publish')) can.push(`създава, променя и публикува дейности${end('catalog.publish')}`);
  else if (has.has('catalog.manage')) can.push(`създава и променя дейности${end('catalog.manage')}`);
  if (can.length === 0) can.push('само гледа, без да променя');

  const sees: string[] = [];
  if (scope === 'own') sees.push('своите групи и децата в тях — име и клас');
  else if (scope === 'groups') sees.push(`децата${where} — име и клас`);
  else if (has.has('enrollment.view')) sees.push('всички групи и децата в тях');
  else sees.push('своите групи и децата в тях — име и клас');
  if (scope === 'own') sees.push('графика на своите групи — кога и в коя зала');
  else if (scope === 'groups' && !has.has('schedule.view_all')) sees.push(`кой учител кога и къде води${where}`);
  else sees.push(`целия график${scope === 'all' ? '' : end('schedule.view_all')}`);
  if (has.has('attendance.view_month')) sees.push(`присъствията за месеца${where}${end('attendance.view_month')}`);
  if (has.has('contacts.view')) sees.push(`телефона и имейла на родителите — след „Покажи телефона“${end('contacts.view')}`);
  if (has.has('team.view')) sees.push(`кой е в екипа${end('team.view')}`);
  if (has.has('finance.view')) sees.push(`таксите и плащанията${end('finance.view')}`);
  if (has.has('finance.pay_view')) sees.push(`възнагражденията на екипа${end('finance.pay_view')}`);

  const hidden: string[] = [];
  const contacts = has.has('contacts.view');
  const finance = has.has('finance.view');
  if (!contacts && !finance) hidden.push('телефоните, имейлите и плащанията на родителите');
  else if (!contacts) hidden.push('телефоните и имейлите на родителите');
  else if (!finance) hidden.push('таксите и плащанията');
  const others = scope !== 'all';
  const team = has.has('team.view');
  if (others && !team) hidden.push('другите групи и хората в екипа');
  else if (others) hidden.push('другите групи');
  else if (!team) hidden.push('хората в екипа');
  return { can, sees, hidden };
}

/** The headings of the lists: the rights in force or the ones an invitation or a change will give. */
export function accessHeadings(when: 'now' | 'later'): { can: string; sees: string; hidden: string } {
  return when === 'now'
    ? { can: 'Може да', sees: 'Вижда', hidden: 'Не вижда' }
    : { can: 'Ще може да', sees: 'Ще вижда', hidden: 'Няма да вижда' };
}

/* The groups: "Шахмат — Група 1, Група 2 · Рисуване — цялата дейност", and the place when all share one. */

export type PickerGroup = { id: string; name: string; activity_id: string | null; activity_title: string | null; location_name?: string | null };

export type SelectionSummary = { place: string | null; text: string; count: number };

export function selectionSummary(targets: string[], groups: PickerGroup[] | undefined, labels: Record<string, string>): SelectionSummary {
  const byActivity = new Map<string, string[]>();
  const loose: string[] = [];
  const places = new Set<string | null>();
  for (const key of targets) {
    if (key.startsWith('activity:')) {
      const id = key.slice('activity:'.length);
      const title = groups?.find((group) => group.activity_id === id)?.activity_title ?? labels[key] ?? 'Дейност';
      byActivity.set(title, [...(byActivity.get(title) ?? []), 'цялата дейност']);
      places.add(null);
      continue;
    }
    const group = groups?.find((item) => groupTarget(item.id) === key);
    if (group) {
      const title = group.activity_title ?? 'Дейност';
      byActivity.set(title, [...(byActivity.get(title) ?? []), group.name]);
      places.add(group.location_name ?? null);
    } else {
      loose.push(labels[key] ?? 'Група');
      places.add(null);
    }
  }
  const text = [
    ...[...byActivity].sort(([a], [b]) => a.localeCompare(b, 'bg')).map(([title, names]) => `${title} — ${names.join(', ')}`),
    ...loose,
  ].join(' · ');
  const [only] = [...places];
  return { place: places.size === 1 && typeof only === 'string' ? only : null, text, count: targets.length };
}

/** The places of the groups, for "Всички в …". */
export function groupPlaces(groups: PickerGroup[]): { place: string; ids: string[] }[] {
  const byPlace = new Map<string, string[]>();
  for (const group of groups) if (group.location_name) byPlace.set(group.location_name, [...(byPlace.get(group.location_name) ?? []), group.id]);
  return [...byPlace].map(([place, ids]) => ({ place, ids })).sort((a, b) => a.place.localeCompare(b.place, 'bg'));
}

/* What a new role takes away, before it is confirmed (т. 4.6). */

export function roleChangeLines(changes: AccessChanges, nextRole: string): string[] {
  const lines = changes.revoke.map((item) => (item.reason === 'role_changed'
    ? `„${rightLabel(item.ability)}“ отпада като добавено — новата роля вече го дава.`
    : `„${rightLabel(item.ability)}“ отпада — дава се само с „Цялата организация“.`));
  if (changes.clear_scope) lines.push(`„За кои групи“ става „${scopeLabels[defaultScope(nextRole)]}“.`);
  if (changes.remove_groups > 0) lines.push(changes.remove_groups === 1 ? 'Избраната група отпада.' : `Избраните групи (${changes.remove_groups}) отпадат.`);
  if (changes.clear_template) lines.push('Етикетът „Партньор от училище“ отпада.');
  if (changes.loses_finance) lines.push('Губи достъпа до финансите.');
  return lines;
}

/* The history of the rights, in words ("Добавено: Отбелязва присъствия, до 30 юни 2027"). */

const revokeWords: Record<string, string> = {
  removed: 'Махнато',
  role_changed: 'Отпадна със смяната на ролята',
  scope_changed: 'Отпадна с промяната на групите',
  archived: 'Отпадна с архива',
  expired: 'Изтече',
};

export function rightsHistoryText(entry: AccessHistoryEntry): string {
  const right = entry.ability ? rightLabel(entry.ability) : 'право';
  const until = entry.expires_at ? `, до ${longDay(lastDayOf(entry.expires_at))}` : '';
  const group = entry.group_label ?? null;
  const scope = (value: string | null | undefined) => (value === 'own' || value === 'groups' || value === 'all' ? scopeLabels[value] : 'по ролята');
  switch (entry.action) {
    case 'team.access_grant_added': return `Добавено: ${right}${until}`;
    case 'team.access_grant_changed': return `Нов срок: ${right}${until || ', без край'}`;
    case 'team.access_grant_revoked': return `${revokeWords[entry.reason ?? ''] ?? 'Махнато'}: ${right}`;
    case 'team.access_scope_changed': return `За кои групи: ${scope(entry.to)}`;
    case 'team.access_group_added': return group ? `Добавена група към правата: ${group}` : 'Добавена цяла дейност към правата';
    case 'team.access_group_removed': return group ? `Махната група от правата: ${group}` : 'Махната дейност от правата';
    case 'team.access_template_changed': return entry.to ? 'Етикет: Партньор от училище' : 'Махнат етикетът „Партньор от училище“';
    case 'catalog.assignment_added': return `Добавена група: ${group ?? 'група'}${entry.role ? `, ${assignmentRole(entry.role)}` : ''}`;
    case 'catalog.assignment_ended': return `Приключена група: ${group ?? 'група'}`;
    case 'catalog.assignment_removed': return `Махната група: ${group ?? 'група'}`;
    case 'enrollment.contact_revealed': return `Показан телефон на родител${group ? ` · ${group}` : ''}`;
    default: return 'Промяна на правата';
  }
}

/** The draft differs from the card in finance — its presence or its end: that is given only after confirming. */
export function touchesFinance(draft: RightsDraft, before: RightsDraft | null): boolean {
  return FINANCE.some((key) => {
    const now = key in draft.grants;
    const was = before !== null && key in before.grants;
    return now && (!was || (before?.grants[key] ?? null) !== (draft.grants[key] ?? null));
  });
}

export type RightsErrors = { rights: Record<string, string>; groups?: string; scope?: string; template?: string; general?: string };

/** The API's field errors on the card's fields: "grants.0.expires_on" names the right by its place in the body. */
export function rightsErrors(fieldErrors: Record<string, string[]>, payload: Pick<AccessPayload, 'grants'>): RightsErrors {
  const result: RightsErrors = { rights: {} };
  for (const [field, messages] of Object.entries(fieldErrors)) {
    const message = messages[0];
    if (!message) continue;
    const grant = /^grants\.(\d+)\./.exec(field);
    const ability = grant ? payload.grants[Number(grant[1])]?.ability : undefined;
    if (ability) result.rights[ability] = message;
    else if (field === 'groups' || field.startsWith('groups.')) result.groups ??= message;
    else if (field === 'scope') result.scope ??= message;
    else if (field === 'template') result.template ??= message;
    else result.general ??= message;
  }
  return result;
}

/** "Галина" from "Галина Тодорова". */
export const firstName = (fullName: string): string => fullName.trim().split(/\s+/)[0] || fullName;

/** The days an end may take: from tomorrow to three years ahead (SaveStaffAccess::assertEnd). */
export function endLimits(today: string): { min: string; max: string } {
  const day = Date.parse(`${today}T12:00:00Z`);
  const [year, month, date] = today.split('-');
  return { min: new Date(day + 86_400_000).toISOString().slice(0, 10), max: `${Number(year) + 3}-${month}-${date}` };
}
