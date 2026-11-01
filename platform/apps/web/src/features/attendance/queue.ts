import { AttendanceError, type Answer, type Change } from './api';
type Job = { key: string; build: () => Change; body?: Change; done: (answer?: Answer, error?: unknown) => void };
/** In-memory FIFO. Bind versions once on first send; every retry reuses the exact key and body. */
export class AttendanceQueue {
  private jobs: Job[] = [];
  private busy = false;
  private timer?: ReturnType<typeof setTimeout>;
  private failures = 0;
  private disposed = false;
  public failed = false;
  constructor(private send: (body: Change, key: string) => Promise<Answer>, private changed: () => void = () => {}) {}
  get retrying() { return this.failures > 0; }
  get pending() { return this.jobs.length; }
  add(key: string, build: () => Change, done: Job['done']) { if (this.disposed) return; this.jobs.push({ key, build, done }); this.changed(); void this.run(); }
  retry() { if (this.busy || this.disposed) return; clearTimeout(this.timer); this.timer = undefined; this.failures = 0; this.failed = false; void this.run(); }
  dispose() { this.disposed = true; clearTimeout(this.timer); this.jobs = []; }
  private async run() {
    if (this.busy || this.timer || this.failed || this.disposed || !this.jobs.length) return;
    this.busy = true;
    const job = this.jobs[0]; job.body ??= job.build();
    try {
      const answer = await this.send(job.body, job.key);
      if (this.disposed) return;
      this.jobs.shift(); this.failures = 0; job.done(answer);
    } catch (error) {
      if (this.disposed) return;
      const retry = !(error instanceof AttendanceError) || error.status >= 500 || error.status === 429 || (error.status === 409 && error.body.retry);
      if (retry) {
        const delay = [1000, 2000, 4000, 8000, 16000][this.failures++];
        if (delay === undefined) this.failed = true;
        else this.timer = setTimeout(() => { this.timer = undefined; void this.run(); }, delay);
      } else { this.jobs.shift(); this.failures = 0; job.done(undefined, error); }
    } finally { this.busy = false; if (!this.disposed) { this.changed(); void this.run(); } }
  }
}
