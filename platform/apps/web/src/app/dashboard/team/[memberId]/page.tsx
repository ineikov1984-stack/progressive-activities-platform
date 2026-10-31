import type { Metadata } from 'next';
import { Suspense } from 'react';
import { MemberProfile } from '@/features/team/member-profile';

export const metadata: Metadata = { title: 'Екип | Progressive Activities' };
export default async function MemberPage({ params }: { params: Promise<{ memberId: string }> }) {
  const { memberId } = await params;
  // The profile reads ?tab= and ?invite= (useSearchParams), which needs a Suspense boundary.
  return <Suspense><MemberProfile memberId={memberId} /></Suspense>;
}
