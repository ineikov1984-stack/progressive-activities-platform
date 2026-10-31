import type { Metadata } from 'next';
import { MemberRights } from '@/features/team/member-rights';

export const metadata: Metadata = { title: 'Права | Progressive Activities' };
export default async function MemberRightsPage({ params }: { params: Promise<{ memberId: string }> }) {
  const { memberId } = await params;
  return <MemberRights memberId={memberId} />;
}
