import { describe, expect, it } from 'vitest';
import type { AccessCard } from '../src/features/team/api';
import {
  accessHeadings, accessSummary, addsFinance, applyPartner, areaSummary, draftFromCard, emptyDraft, endLimits, financeLock, firstName, grantableFor,
  groupPlaces, lastDayOf, longDay, losesFinance, partnerFits, payloadFromDraft, rightRows, rightsErrors, rightsHeadline, rightsHistoryText, rightsOfRole, rightTexts,
  roleAs, roleChangeLines, sameDraft, scopeDrops, selectionSummary, templateLabel, touchesFinance, withEnd, withRight, withScope,
} from '../src/features/team/rights';

const card = (extra: Partial<AccessCard> = {}): AccessCard => ({
  member_id: 'm-1', state: 'access', role: 'viewer', scope: 'groups', allowed_scopes: ['all', 'groups'],
  groups: [{ group_id: 'g-1', label: 'Шахмат · Група 1' }], activities: [{ activity_id: 'a-2', title: 'Рисуване' }],
  rights: [
    { key: 'enrollment.view', area: 'enrollment', source: 'role', grant_id: null, expires_on: null, implied: false },
    { key: 'enrollment.enroll', area: 'enrollment', source: 'grant', grant_id: 'gr-1', expires_on: null, implied: false },
    { key: 'attendance.mark', area: 'attendance', source: 'grant', grant_id: 'gr-2', expires_on: '2027-06-30', implied: false },
    { key: 'attendance.view_month', area: 'attendance', source: 'role', grant_id: null, expires_on: null, implied: false },
    { key: 'team.view', area: 'team', source: 'role', grant_id: null, expires_on: null, implied: false },
  ],
  template: 'school_partner', version: 4, editable: true, reason: null,
  grantable: ['enrollment.enroll', 'attendance.mark', 'schedule.view_all', 'schedule.manage', 'contacts.view', 'contacts.message', 'team.assign', 'catalog.manage', 'catalog.publish'],
  ...extra,
});

describe('team rights', () => {
  it('knows which rights each role carries, as the API does', () => {
    expect(rightsOfRole('viewer')).toEqual(['enrollment.view', 'attendance.view_month', 'team.view']);
    expect(rightsOfRole('instructor')).toEqual(['enrollment.view_own']);
    expect(rightsOfRole('organization_admin')).not.toContain('finance.view');
    expect(rightsOfRole('organization_owner')).toContain('finance.view');
    expect(rightsOfRole('coordinator')).not.toContain('catalog.publish');
    expect(rightsOfRole(null)).toEqual([]);
  });

  it('offers in "Добави в екипа" only what the role does not give, never finance and nothing to a coordinator', () => {
    expect(grantableFor('organization_owner', 'viewer')).toEqual(expect.arrayContaining(['enrollment.enroll', 'attendance.mark', 'contacts.view']));
    expect(grantableFor('organization_owner', 'viewer')).not.toContain('team.view');
    expect(grantableFor('organization_owner', 'viewer').some((key) => key.startsWith('finance.'))).toBe(false);
    expect(grantableFor('organization_admin', 'coordinator')).not.toContain('enrollment.enroll');
    expect(grantableFor('coordinator', 'viewer')).toEqual([]);
  });

  it('starts the draft from the card: the added rights with their last day, the groups and activities, the template', () => {
    const draft = draftFromCard(card());
    expect(draft).toEqual({
      scope: 'groups', targets: ['group:g-1', 'activity:a-2'], grants: { 'enrollment.enroll': null, 'attendance.mark': '2027-06-30' }, template: 'school_partner',
      parked: {},
    });
    expect(draftFromCard(card({ scope: null, role: 'instructor' })).scope).toBe('own');
    // What another right brings along is not sent back as an added right.
    const implied = card({ rights: [{ key: 'catalog.manage', area: 'settings', source: 'grant', grant_id: null, expires_on: null, implied: true }] });
    expect(draftFromCard(implied).grants).toEqual({});
  });

  it('sends the whole card, the groups only with "Избрани групи", and the template only while it fits', () => {
    const draft = draftFromCard(card());
    expect(payloadFromDraft('viewer', draft)).toEqual({
      scope: 'groups', groups: [{ group_id: 'g-1' }, { activity_id: 'a-2' }],
      grants: [{ ability: 'enrollment.enroll', expires_on: null }, { ability: 'attendance.mark', expires_on: '2027-06-30' }], template: 'school_partner',
    });
    expect(payloadFromDraft('viewer', withScope('viewer', draft, 'all'))).toMatchObject({ scope: 'all', groups: [], template: null });
    expect(payloadFromDraft('viewer', draft, true).confirm_finance).toBe(true);
  });

  it('says what a narrower scope takes away, keeps it aside and brings it back with the whole organization', () => {
    const wide = { ...emptyDraft('viewer'), grants: { 'schedule.manage': '2027-01-31', 'team.assign': null, 'finance.view': null, 'contacts.view': null } };
    const narrow = withScope('viewer', wide, 'groups');
    expect(narrow.grants).toEqual({ 'contacts.view': null });
    expect(scopeDrops(narrow)).toBe('Ще отпаднат: Променя графика, Слага учители в групите, Вижда таксите и плащанията — дават се само с „Цялата организация“.');
    // Never sent: the body holds only what stays.
    expect(payloadFromDraft('viewer', narrow).grants).toEqual([{ ability: 'contacts.view', expires_on: null }]);
    // Finance the person has is asked about before saving; one added only in this draft is not.
    expect(losesFinance(narrow, wide)).toBe(true);
    expect(losesFinance(narrow, emptyDraft('viewer'))).toBe(false);
    const back = withScope('viewer', narrow, 'all');
    expect(back.grants).toEqual(wide.grants);
    expect(scopeDrops(back)).toBeNull();
    expect(sameDraft(back, wide)).toBe(true);
    expect(sameDraft(narrow, { ...narrow, parked: {} })).toBe(true);
  });

  it('drops what is given only for the whole organization when the scope narrows, and the label when it no longer fits', () => {
    const wide = { ...emptyDraft('viewer'), grants: { 'schedule.manage': null, 'contacts.view': null } };
    expect(withScope('viewer', wide, 'groups').grants).toEqual({ 'contacts.view': null });
    expect(applyPartner(wide).parked).toEqual({ 'schedule.manage': null });
    const partner = applyPartner(emptyDraft('viewer'));
    expect(partner).toMatchObject({ scope: 'groups', grants: { 'enrollment.enroll': null, 'attendance.mark': null }, template: 'school_partner' });
    expect(partnerFits('viewer', partner)).toBe(true);
    expect(partnerFits('instructor', partner)).toBe(false);
    expect(withRight('viewer', partner, 'attendance.mark', false).template).toBeNull();
    expect(withEnd(partner, 'attendance.mark', '2027-06-30').grants['attendance.mark']).toBe('2027-06-30');
    expect(withEnd(partner, 'contacts.view', '2027-06-30')).toBe(partner);
  });

  it('tells a changed draft from the card, whatever the order', () => {
    const draft = draftFromCard(card());
    expect(sameDraft(draft, { ...draft, targets: [...draft.targets].reverse() })).toBe(true);
    expect(sameDraft(draft, withRight('viewer', draft, 'contacts.view', true))).toBe(false);
    expect(sameDraft(emptyDraft('viewer'), emptyDraft('viewer'))).toBe(true);
  });

  it('marks each right: from the role, added, brought along, only with a role, or locked — with the reason in words', () => {
    const input = { role: 'viewer', draft: draftFromCard(card()), grantable: card().grantable ?? [], teaches: false, actorIsOwner: true };
    const enrollment = rightRows('enrollment', input);
    expect(enrollment.map((row) => [row.key, row.tag])).toEqual([['enrollment.view', 'от ролята'], ['enrollment.enroll', 'добавено']]);
    const attendance = rightRows('attendance', input);
    expect(attendance.find((row) => row.key === 'attendance.mark')).toMatchObject({ checked: true, editable: true, lastDay: '2027-06-30' });
    expect(attendance.find((row) => row.key === 'attendance.correct_late')).toMatchObject({ tag: 'само с роля', lock: 'Само собственикът и администраторът.', editable: false });
    // Selected groups: settings are only for the whole organization.
    expect(rightRows('settings', input).every((row) => !row.editable)).toBe(true);
    expect(rightRows('settings', input)[0].lock).toBe('Дава се само с „Цялата организация“.');
    // A right the signed-in person may not give is seen, not changed.
    const admin = { ...input, grantable: ['enrollment.enroll'], actorIsOwner: false };
    expect(rightRows('attendance', admin).find((row) => row.key === 'attendance.mark')).toMatchObject({ checked: true, editable: false, lock: 'Дава ги само собственикът.' });
    // "Публикува" brings "Създава и променя" along.
    const publish = { ...input, draft: { ...emptyDraft('viewer'), grants: { 'catalog.publish': null } } };
    expect(rightRows('settings', publish).find((row) => row.key === 'catalog.manage')).toMatchObject({ checked: true, editable: false, tag: 'идва с „Публикува на сайта“' });
    // A teacher's groups give attendance without a checkbox.
    const teacher = { ...input, role: 'instructor', draft: emptyDraft('instructor'), teaches: true };
    expect(rightRows('attendance', teacher)[0]).toMatchObject({ key: 'attendance.mark', checked: true, tag: 'от групите, които води', editable: false });
  });

  it('counts each area: added, from the role, nothing added, or locked', () => {
    const input = { role: 'viewer', draft: draftFromCard(card()), grantable: card().grantable ?? [], teaches: false, actorIsOwner: true };
    expect(areaSummary(rightRows('enrollment', input))).toBe('1 добавено · 1 от ролята');
    expect(areaSummary(rightRows('contacts', input))).toBe('нищо добавено');
    expect(areaSummary(rightRows('settings', input))).toBe('заключено');
    const two = { ...input, draft: { ...input.draft, grants: { ...input.draft.grants, 'contacts.view': null, 'contacts.message': null } } };
    expect(areaSummary(rightRows('contacts', two))).toBe('2 добавени');
  });

  it('closes finance for a narrow scope and says why; the owner gives it only once it is on', () => {
    expect(financeLock({ name: 'Галина', role: 'viewer', scope: 'groups', actorIsOwner: true, grantable: [] }))
      .toBe('Галина има избрани групи, а финансите са за цялата организация — затова тук не се дават.');
    expect(financeLock({ name: 'Галина', role: 'viewer', scope: 'all', actorIsOwner: true, grantable: [] })).toBe('Финансите още не са включени в системата.');
    expect(financeLock({ name: 'Галина', role: 'viewer', scope: 'all', actorIsOwner: true, grantable: ['finance.view'] })).toBeNull();
    expect(financeLock({ name: 'Галина', role: 'finance', scope: 'all', actorIsOwner: false, grantable: [] })).toBeNull();
    const before = emptyDraft('viewer');
    const after = withRight('viewer', before, 'finance.view', true);
    expect(addsFinance(after, before)).toBe(true);
    expect(touchesFinance(after, before)).toBe(true);
    expect(touchesFinance(after, after)).toBe(false);
    expect(touchesFinance(withEnd(after, 'finance.view', '2027-01-31'), after)).toBe(true);
  });

  it('says what the person will be able to do, from the role, the scope and the additions', () => {
    expect(accessSummary({ role: 'instructor', scope: 'own', grants: {} })).toEqual({
      can: ['отбелязва присъствия в своите групи — в деня на заниманието', 'поправя отбелязаното до края на следващия ден; всяка промяна остава в историята'],
      sees: ['своите групи и децата в тях — име и клас', 'графика на своите групи — кога и в коя зала'],
      hidden: ['телефоните, имейлите и плащанията на родителите', 'другите групи и хората в екипа'],
    });
    const partner = accessSummary({ role: 'viewer', scope: 'groups', grants: { 'enrollment.enroll': null, 'attendance.mark': '2027-06-30' }, selected: 4 });
    expect(partner.can).toEqual(['записва деца в тези 4 групи', 'отбелязва присъствия в тези 4 групи и поправя до края на следващия ден — до 30 юни 2027']);
    expect(partner.hidden).toEqual(['телефоните, имейлите и плащанията на родителите', 'другите групи']);
    expect(accessSummary({ role: 'viewer', scope: 'all', grants: {} }).can).toEqual(['само гледа, без да променя']);
    expect(accessSummary({ role: 'viewer', scope: 'all', grants: { 'contacts.view': null } }).sees).toContain('телефона и имейла на родителите — след „Покажи телефона“');
    expect(accessSummary({ role: 'organization_owner', scope: 'all', grants: {} }).hidden).toEqual([]);
    expect(accessSummary({ role: 'organization_admin', scope: 'all', grants: {} }).hidden).toEqual(['финансите']);
    for (const role of ['coordinator', 'finance', 'viewer', 'instructor']) {
      const summary = accessSummary({ role, scope: role === 'instructor' ? 'own' : 'all', grants: {} });
      expect(summary.can.length > 0 && summary.sees.length > 0).toBe(true);
    }
  });

  it('heads the lists for the rights in force and for the ones an invitation will give', () => {
    expect(accessHeadings('now')).toEqual({ can: 'Може да', sees: 'Вижда', hidden: 'Не вижда' });
    expect(accessHeadings('later')).toEqual({ can: 'Ще може да', sees: 'Ще вижда', hidden: 'Няма да вижда' });
    expect(roleAs('instructor')).toBe('Като преподавател');
    expect(roleAs('viewer')).toBe('Със „Само преглед“');
  });

  it('writes "Само преглед + 2 права" and the template\'s label', () => {
    expect(rightsHeadline('viewer', draftFromCard(card()))).toBe('Само преглед + 2 права');
    expect(rightsHeadline('viewer', { grants: { 'contacts.view': null } })).toBe('Само преглед + 1 право');
    expect(rightsHeadline('coordinator', { grants: {} })).toBe('Координатор');
    expect(templateLabel('school_partner')).toBe('Партньор от училище');
    expect(templateLabel(null)).toBeNull();
  });

  it('sums up the selected groups by activity and names the place when they share one', () => {
    const groups = [
      { id: 'g-1', name: 'Група 1', activity_id: 'a-1', activity_title: 'Шахмат', location_name: 'СУ „Христо Ботев“' },
      { id: 'g-2', name: 'Група 2', activity_id: 'a-1', activity_title: 'Шахмат', location_name: 'СУ „Христо Ботев“' },
      { id: 'g-3', name: 'Начинаещи', activity_id: 'a-3', activity_title: 'Баскетбол', location_name: 'СУ „Христо Ботев“' },
      { id: 'g-4', name: 'Група 3', activity_id: 'a-2', activity_title: 'Рисуване', location_name: 'Зала Център' },
    ];
    expect(selectionSummary(['group:g-1', 'group:g-2', 'group:g-3'], groups, {})).toEqual({
      place: 'СУ „Христо Ботев“', text: 'Баскетбол — Начинаещи · Шахмат — Група 1, Група 2', count: 3,
    });
    expect(selectionSummary(['group:g-1', 'activity:a-2'], groups, {})).toMatchObject({ place: null, text: 'Рисуване — цялата дейност · Шахмат — Група 1' });
    expect(selectionSummary(['group:x'], undefined, { 'group:x': 'Шах · Вторник' }).text).toBe('Шах · Вторник');
    expect(groupPlaces(groups)).toEqual([{ place: 'Зала Център', ids: ['g-4'] }, { place: 'СУ „Христо Ботев“', ids: ['g-1', 'g-2', 'g-3'] }]);
  });

  it('shows what a new role takes away before it is confirmed', () => {
    expect(roleChangeLines({
      revoke: [{ ability: 'enrollment.enroll', reason: 'role_changed' }, { ability: 'schedule.manage', reason: 'scope_changed' }],
      clear_scope: true, remove_groups: 3, clear_template: true, loses_finance: false,
    }, 'coordinator')).toEqual([
      '„Записва деца“ отпада като добавено — новата роля вече го дава.',
      '„Променя графика“ отпада — дава се само с „Цялата организация“.',
      '„За кои групи“ става „Цялата организация“.',
      'Избраните групи (3) отпадат.',
      'Етикетът „Партньор от училище“ отпада.',
    ]);
    expect(roleChangeLines({ revoke: [], clear_scope: false, remove_groups: 0, clear_template: false, loses_finance: false }, 'viewer')).toEqual([]);
  });

  it('writes the history of the rights in words, with the last day of an added right', () => {
    // The end is kept as the start of the next day in Sofia.
    expect(lastDayOf('2027-06-30T21:00:00+00:00')).toBe('2027-06-30');
    expect(longDay('2027-06-30')).toBe('30 юни 2027');
    const at = '2026-10-31T09:04:00+00:00';
    expect(rightsHistoryText({ action: 'team.access_grant_added', at, by: 'Мария Колева', group_label: null, ability: 'attendance.mark', expires_at: '2027-06-30T21:00:00+00:00' }))
      .toBe('Добавено: Отбелязва присъствия, до 30 юни 2027');
    expect(rightsHistoryText({ action: 'team.access_grant_revoked', at, by: null, group_label: null, ability: 'contacts.view', reason: 'role_changed' }))
      .toBe('Отпадна със смяната на ролята: Вижда телефона и имейла');
    expect(rightsHistoryText({ action: 'team.access_scope_changed', at, by: null, group_label: null, from: 'all', to: 'groups' })).toBe('За кои групи: Избрани групи');
    expect(rightsHistoryText({ action: 'catalog.assignment_added', at, by: null, group_label: 'Шахмат · Група 1', role: 'assistant' })).toBe('Добавена група: Шахмат · Група 1, помощник');
    expect(rightsHistoryText({ action: 'enrollment.contact_revealed', at, by: null, group_label: 'Шахмат · Група 1' })).toBe('Показан телефон на родител · Шахмат · Група 1');
    expect(rightsHistoryText({ action: 'something.new', at, by: null, group_label: null })).toBe('Промяна на правата');
  });

  it('puts the API\'s field errors on the right they belong to', () => {
    const payload = { grants: [{ ability: 'contacts.view', expires_on: null }, { ability: 'attendance.mark', expires_on: '2026-10-01' }] };
    expect(rightsErrors({ 'grants.1.expires_on': ['Крайната дата трябва да е след днес.'], 'groups.0.group_id': ['Групата не е намерена.'], member: ['Човекът е в архива.'] }, payload))
      .toEqual({ rights: { 'attendance.mark': 'Крайната дата трябва да е след днес.' }, groups: 'Групата не е намерена.', general: 'Човекът е в архива.' });
  });

  it('allows an end from tomorrow to three years ahead and names a person by the first name', () => {
    expect(endLimits('2026-10-31')).toEqual({ min: '2026-11-01', max: '2029-10-31' });
    expect(firstName('Галина Тодорова')).toBe('Галина');
  });

  it('never shows a key: every right has a name and a sentence in Bulgarian', () => {
    for (const [key, text] of Object.entries(rightTexts)) {
      expect(text.label).toMatch(/[А-Яа-я]/);
      expect(text.label).not.toContain(key);
      expect(text.hint).toMatch(/[А-Яа-я]/);
    }
  });
});
