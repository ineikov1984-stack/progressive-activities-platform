export type AutosaveState = 'idle' | 'pending' | 'saving' | 'saved' | 'error';

type Options<T> = {
  delayMs: number;
  save: (value: T) => Promise<void>;
  onState: (state: AutosaveState, error?: unknown) => void;
};

/**
 * Debounced, strictly sequential saving: only the latest value is sent, never two
 * requests at once (the API uses optimistic versions, so parallel saves would conflict).
 */
export class Autosave<T> {
  private latest: T | undefined;
  private hasLatest = false;
  private timer: ReturnType<typeof setTimeout> | undefined;
  private running: Promise<void> | undefined;

  constructor(private readonly options: Options<T>) {}

  schedule(value: T): void {
    this.latest = value;
    this.hasLatest = true;
    this.options.onState('pending');
    if (this.timer) clearTimeout(this.timer);
    this.timer = setTimeout(() => { this.timer = undefined; void this.flush(); }, this.options.delayMs);
  }

  /** Saves anything pending now and resolves when nothing is left to save. */
  async flush(): Promise<void> {
    if (this.timer) { clearTimeout(this.timer); this.timer = undefined; }
    while (this.running) await this.running;
    if (!this.hasLatest) return;
    const value = this.latest as T;
    this.hasLatest = false;
    this.options.onState('saving');
    let failed = false;
    this.running = this.options.save(value)
      .then(() => { if (!this.hasLatest && !this.timer) this.options.onState('saved'); })
      .catch((error: unknown) => {
        failed = true;
        // Keep the unsaved value so the next change or an explicit flush retries it.
        if (!this.hasLatest) { this.latest = value; this.hasLatest = true; }
        this.options.onState('error', error);
      })
      .finally(() => { this.running = undefined; });
    await this.running;
    if (!failed && this.hasLatest && !this.timer) await this.flush();
  }

  cancel(): void {
    if (this.timer) clearTimeout(this.timer);
    this.timer = undefined;
    this.hasLatest = false;
  }
}
