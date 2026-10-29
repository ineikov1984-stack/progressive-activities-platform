import { describe, expect, it } from 'vitest';
import { privateMediaUrl } from '../src/features/catalog/admin/api';

describe('privateMediaUrl', () => {
  it('points at the authenticated media endpoint without the storage prefix', () => {
    expect(privateMediaUrl('catalog/abc.jpg')).toBe('/backend/api/v1/catalog/media/abc.jpg');
    expect(privateMediaUrl(null)).toBeUndefined();
  });
});
