import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Autosave, type AutosaveState } from '../src/features/catalog/admin/wizard/autosave';

describe('Autosave', () => {
  beforeEach(() => { vi.useFakeTimers(); });
  afterEach(() => { vi.useRealTimers(); });

  it('debounces and sends only the latest value', async () => {
    const save = vi.fn().mockResolvedValue(undefined);
    const states: AutosaveState[] = [];
    const autosave = new Autosave<string>({ delayMs: 1000, save, onState: (state) => states.push(state) });
    autosave.schedule('a');
    autosave.schedule('ab');
    await vi.advanceTimersByTimeAsync(1000);
    expect(save).toHaveBeenCalledTimes(1);
    expect(save).toHaveBeenCalledWith('ab');
    expect(states.at(-1)).toBe('saved');
  });

  it('never runs two saves at once and saves changes made during a save', async () => {
    let release: () => void = () => undefined;
    const save = vi.fn().mockImplementationOnce(() => new Promise<void>((resolve) => { release = resolve; })).mockResolvedValue(undefined);
    const autosave = new Autosave<number>({ delayMs: 10, save, onState: () => undefined });
    autosave.schedule(1);
    await vi.advanceTimersByTimeAsync(10);
    autosave.schedule(2);
    const flushed = autosave.flush();
    expect(save).toHaveBeenCalledTimes(1);
    release();
    await flushed;
    expect(save).toHaveBeenNthCalledWith(2, 2);
  });

  it('keeps a failed value for the next attempt without retrying in a loop', async () => {
    const save = vi.fn().mockRejectedValueOnce(new Error('offline')).mockResolvedValue(undefined);
    const states: AutosaveState[] = [];
    const autosave = new Autosave<string>({ delayMs: 10, save, onState: (state) => states.push(state) });
    autosave.schedule('x');
    await vi.advanceTimersByTimeAsync(10);
    expect(states.at(-1)).toBe('error');
    expect(save).toHaveBeenCalledTimes(1);
    await autosave.flush();
    expect(save).toHaveBeenNthCalledWith(2, 'x');
    expect(states.at(-1)).toBe('saved');
  });
});
