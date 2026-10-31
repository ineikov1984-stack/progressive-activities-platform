import type { Metadata } from 'next';
import { InvitePage } from '@/features/team/invite-page';

// Public and outside the dashboard; not for search engines. The token stays in the fragment (never sent anywhere).
export const metadata: Metadata = {
  title: 'Покана за екипа | Progressive Activities',
  robots: { index: false, follow: false },
  referrer: 'no-referrer',
};

export default function Page() { return <InvitePage />; }
