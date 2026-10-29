'use client';

import Image, { type ImageProps } from 'next/image';
import { useEffect, useState } from 'react';
import { privateMediaUrl } from './api';

/**
 * Private catalog media needs the X-Organization-Id header, which an <img> tag cannot send.
 * Fetch it with the header and show it from a temporary object URL instead.
 */
export function usePrivateMedia(organizationId?: string, path?: string | null): string | undefined {
  const [loaded, setLoaded] = useState<{ key: string; url: string }>();
  const key = organizationId && path ? `${organizationId}:${path}` : undefined;

  useEffect(() => {
    if (!key || !organizationId || !path) return;
    const source = privateMediaUrl(path);
    if (!source) return;
    let active = true;
    let objectUrl: string | undefined;
    fetch(source, { credentials: 'include', headers: { 'X-Organization-Id': organizationId } })
      .then((response) => (response.ok ? response.blob() : Promise.reject(new Error('media'))))
      .then((blob) => {
        objectUrl = URL.createObjectURL(blob);
        if (active) setLoaded({ key, url: objectUrl });
        else URL.revokeObjectURL(objectUrl);
      })
      .catch(() => undefined);
    return () => {
      active = false;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [key, organizationId, path]);

  return loaded && loaded.key === key ? loaded.url : undefined;
}

type PrivateImageProps = Omit<ImageProps, 'src' | 'unoptimized'> & { organizationId?: string; path?: string | null };

export function PrivateImage({ organizationId, path, alt, ...props }: PrivateImageProps) {
  const src = usePrivateMedia(organizationId, path);
  if (!src) return null;
  return <Image {...props} src={src} alt={alt} unoptimized />;
}
