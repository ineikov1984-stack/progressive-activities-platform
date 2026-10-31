import { afterEach, describe, expect, it, vi } from 'vitest';
import { loadSchedule, loadSessionPanel, ScheduleApiError } from '../src/features/schedule/api';

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
});
