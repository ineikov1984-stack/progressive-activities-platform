import { describe, expect, it } from 'vitest';
import { catalogErrorMessage } from '../src/features/catalog/admin/api';

describe('catalogErrorMessage', () => {
  it('translates known API messages', () => {
    expect(catalogErrorMessage('Only drafts can be edited.')).toMatch(/^Публикуваната дейност/);
    expect(catalogErrorMessage('Activity version conflict.')).toMatch(/Презаредете/);
  });

  it('keeps Bulgarian messages and hides unknown English or empty ones', () => {
    expect(catalogErrorMessage('Полето е задължително за публикуване.')).toBe('Полето е задължително за публикуване.');
    expect(catalogErrorMessage('Server Error')).toBe('Действието не беше изпълнено. Опитайте отново или се свържете с поддръжката.');
    expect(catalogErrorMessage(undefined)).toBe('Заявката към каталога не беше изпълнена.');
  });
});
