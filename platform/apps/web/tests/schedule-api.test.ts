import { afterEach, describe, expect, it, vi } from 'vitest';
import { loadChildCard, loadSchedule, loadSessionPanel, ScheduleApiError, searchChildren } from '../src/features/schedule/api';

const jsonResponse = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

describe('schedule API', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('reads the week in the selected organization, and abilities stay an object by group', async () => {
    const fetchMock = vi.fn().mockResolvedValueOnce(jsonResponse({ sessions: [], activities: [], groups: [], locations: [], instructors: [], abilities: [] }));
    vi.stubGlobal('fetch', fetchMock);

    const board = await loadSchedule('org-1', 'from=2026-11-16&to=2026-11-22&group%5B%5D=g-1');

    expect(board.abilities).toEqual({});
    expect(fetchMock).toHaveBeenCalledWith('/backend/api/v1/schedule?from=2026-11-16&to=2026-11-22&group%5B%5D=g-1', expect.objectContaining({
      credentials: 'include',
      headers: expect.objectContaining({ Accept: 'application/json', 'X-Organization-Id': 'org-1' }),
    }));
  });

  it('reads the panel of a session', async () => {
    const fetchMock = vi.fn().mockResolvedValueOnce(jsonResponse({ session: { id: 's-1' }, group: { id: 'g-1' }, activity: { id: 'a-1' }, location: null, abilities: { children: false, manage: false } }));
    vi.stubGlobal('fetch', fetchMock);

    const panel = await loadSessionPanel('org-1', 's-1');

    expect(panel.session.id).toBe('s-1');
    expect(fetchMock).toHaveBeenCalledWith('/backend/api/v1/schedule/sessions/s-1', expect.objectContaining({ headers: expect.objectContaining({ 'X-Organization-Id': 'org-1' }) }));
  });

  it('says what went wrong in Bulgarian, with the API\'s own words for a wrong period', async () => {
    vi.stubGlobal('fetch', vi.fn()
      .mockResolvedValueOnce(jsonResponse({ message: 'The to field…', errors: { to: ['Изберете период до 42 дни.'] } }, 422))
      .mockResolvedValueOnce(jsonResponse({ message: 'Заниманието не е намерено.' }, 404))
      .mockResolvedValueOnce(new Response('oops', { status: 500 })));

    const failure = async (run: () => Promise<unknown>) => { try { await run(); } catch (error) { return error as ScheduleApiError; } throw new Error('no error'); };
    const tooLong = await failure(() => loadSchedule('org-1', 'from=2026-11-01&to=2026-12-31'));
    expect(tooLong.message).toBe('Изберете период до 42 дни.');
    expect(tooLong.status).toBe(422);
    expect((await failure(() => loadSessionPanel('org-1', 's-x'))).message).toBe('Заниманието не е намерено. Може да е преместено или да не е във вашия график.');
    expect((await failure(() => loadSchedule('org-1', 'from=2026-11-16&to=2026-11-22'))).message).toBe('Графикът не се зареди. Проверете връзката и опитайте пак.');
  });

  it('searches the enrolled children by name in the selected organization', async () => {
    const fetchMock = vi.fn().mockResolvedValueOnce(jsonResponse({ children: [{ id: 'c-1', name: 'Ана Иванова', class: '3. клас' }] }));
    vi.stubGlobal('fetch', fetchMock);

    const children = await searchChildren('org-1', 'Ана И');

    expect(children).toEqual([{ id: 'c-1', name: 'Ана Иванова', class: '3. клас' }]);
    expect(fetchMock).toHaveBeenCalledWith('/backend/api/v1/enrollment/children?q=%D0%90%D0%BD%D0%B0+%D0%98', expect.objectContaining({
      credentials: 'include',
      headers: expect.objectContaining({ Accept: 'application/json', 'X-Organization-Id': 'org-1' }),
    }));
  });

  it('says why the search found nothing to show, in Bulgarian', async () => {
    vi.stubGlobal('fetch', vi.fn()
      .mockResolvedValueOnce(jsonResponse({ message: 'The q field…', errors: { q: ['Напишете поне 2 букви от името.'] } }, 422))
      .mockResolvedValueOnce(jsonResponse({ message: 'This action is unauthorized.' }, 403))
      .mockResolvedValueOnce(jsonResponse({ message: 'Too Many Attempts.' }, 429))
      .mockResolvedValueOnce(jsonResponse({ children: 'nope' })));

    const failure = async (run: () => Promise<unknown>) => { try { await run(); } catch (error) { return error as ScheduleApiError; } throw new Error('no error'); };
    expect((await failure(() => searchChildren('org-1', 'А'))).message).toBe('Напишете поне 2 букви от името.');
    expect((await failure(() => searchChildren('org-1', 'Ан'))).message).toBe('Нямате достъп до децата в графика.');
    expect((await failure(() => searchChildren('org-1', 'Ан'))).message).toBe('Търсихте много пъти за кратко. Изчакайте минута и опитайте пак.');
    // An answer of another shape finds no one, rather than breaking the filter.
    expect(await searchChildren('org-1', 'Ан')).toEqual([]);
  });

  it('reads the card of a child in the selected organization, and keeps its shape', async () => {
    const group = { enrollment_id: 'e-1', group_id: 'g-1', activity: 'Шах', group: 'Вторник', version: 2, state: 'active', paused_until: null, ended_on: null, abilities: { contacts: true, skip: false, pause: false } };
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse({ id: 'c-1', name: 'Ана Иванова', class: '3. клас', age: 9, groups: [group] }))
      .mockResolvedValueOnce(jsonResponse({ id: 'c-1', name: 'Ана Иванова', class: null, groups: 'nope' }));
    vi.stubGlobal('fetch', fetchMock);

    expect(await loadChildCard('org-1', 'c-1')).toEqual({ id: 'c-1', name: 'Ана Иванова', class: '3. клас', age: 9, groups: [group] });
    expect(fetchMock).toHaveBeenCalledWith('/backend/api/v1/enrollment/children/c-1', expect.objectContaining({
      credentials: 'include', headers: expect.objectContaining({ 'X-Organization-Id': 'org-1' }),
    }));
    expect(await loadChildCard('org-1', 'c-1')).toEqual({ id: 'c-1', name: 'Ана Иванова', class: null, age: null, groups: [] });
  });

  it('says a card that is not seen is not there, without telling more', async () => {
    vi.stubGlobal('fetch', vi.fn()
      .mockResolvedValueOnce(jsonResponse({ message: 'Детето не е намерено.' }, 404))
      .mockResolvedValueOnce(new Response('oops', { status: 500 })));

    const failure = async (run: () => Promise<unknown>) => { try { await run(); } catch (error) { return error as ScheduleApiError; } throw new Error('no error'); };
    const missing = await failure(() => loadChildCard('org-1', 'c-x'));
    expect(missing.status).toBe(404);
    expect(missing.message).toBe('Картата на детето не е достъпна — детето не е в групите, в които виждате децата.');
    expect((await failure(() => loadChildCard('org-1', 'c-1'))).message).toBe('Картата не се зареди. Проверете връзката и опитайте пак.');
  });
});
