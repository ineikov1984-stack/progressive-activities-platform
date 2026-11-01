import { afterEach, expect, it, vi } from 'vitest';
import { AttendanceQueue } from '../src/features/attendance/queue';
import { AttendanceError, type Answer, type Change } from '../src/features/attendance/api';
afterEach(() => vi.useRealTimers());
const answer = { applied: [] } as unknown as Answer;
it('serializes clicks and binds the next version after the previous answer', async () => {
  let resolve!: (value: Answer) => void; let version = 0;
  const send = vi.fn().mockImplementationOnce(() => new Promise<Answer>((r) => { resolve = r; })).mockResolvedValue(answer);
  const q = new AttendanceQueue(send); const build = () => ({ action: 'mark', marks: [{ enrollment_id: 'child', status: 'present', version }] } as Change);
  q.add('one', build, () => { version = 1; }); q.add('two', build, () => {});
  expect(send).toHaveBeenCalledTimes(1); resolve(answer); await vi.waitFor(() => expect(send).toHaveBeenCalledTimes(2));
  expect(send.mock.calls[1][0].marks[0].version).toBe(1); expect(q.pending).toBe(0); q.dispose();
});
it('retries transient failures with the identical body and key, without rebuilding versions', async () => {
  vi.useFakeTimers(); const send = vi.fn().mockRejectedValueOnce(new TypeError('offline')).mockRejectedValueOnce(new AttendanceError('busy', 409, { retry: true })).mockResolvedValue(answer);
  const build = vi.fn((): Change => ({ action: 'all_present' })); const done = vi.fn(); const q = new AttendanceQueue(send);
  q.add('stable', build, done); await vi.advanceTimersByTimeAsync(1000); await vi.advanceTimersByTimeAsync(2000);
  expect(send).toHaveBeenCalledTimes(3); expect(build).toHaveBeenCalledTimes(1); expect(send.mock.calls[0]).toEqual(send.mock.calls[2]); expect(send.mock.calls[0][0]).toBe(send.mock.calls[2][0]); expect(done).toHaveBeenCalledWith(answer); q.dispose();
});
it('stops after bounded retry delays and manual/online retry keeps the failed request', async () => {
  vi.useFakeTimers(); const send = vi.fn().mockRejectedValue(new AttendanceError('limited', 429)); const q = new AttendanceQueue(send);
  q.add('same', () => ({ action: 'topic', topic: 'Мат' }), () => {});
  for (const ms of [1000, 2000, 4000, 8000, 16000]) await vi.advanceTimersByTimeAsync(ms);
  expect(q.failed).toBe(true); expect(q.pending).toBe(1); expect(send).toHaveBeenCalledTimes(6);
  send.mockResolvedValue(answer); q.retry(); await vi.advanceTimersByTimeAsync(0); expect(q.pending).toBe(0); expect(send.mock.calls[6]).toEqual(send.mock.calls[0]); q.dispose();
});
it('delivers conflicts and authorization errors once and continues the queue', async () => {
  const error = new AttendanceError('changed', 409, { conflicts: ['child'] }); const done = vi.fn(); const send = vi.fn().mockRejectedValueOnce(error).mockResolvedValue(answer); const q = new AttendanceQueue(send);
  q.add('conflict', () => ({ action: 'all_present' }), done); q.add('next', () => ({ action: 'topic', topic: 'Мат' }), () => {});
  await vi.waitFor(() => expect(q.pending).toBe(0)); expect(done).toHaveBeenCalledWith(undefined, error); expect(send).toHaveBeenCalledTimes(2); q.dispose();
});
it('drops callbacks and retries when the organization/session component is disposed', async () => {
  let resolve!: (value: Answer) => void; const done = vi.fn(); const q = new AttendanceQueue(() => new Promise((r) => { resolve = r; }));
  q.add('old', () => ({ action: 'all_present' }), done); q.dispose(); resolve(answer); await Promise.resolve(); expect(done).not.toHaveBeenCalled(); expect(q.pending).toBe(0);
});
