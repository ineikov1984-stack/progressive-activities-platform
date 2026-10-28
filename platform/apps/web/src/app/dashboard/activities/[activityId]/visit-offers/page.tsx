import type { Metadata } from 'next';
import { VisitOfferEditor } from '@/features/catalog/admin/visit-offer-editor';

export const metadata: Metadata = { title: 'Предложения за посещение | Progressive Activities' };
export default async function VisitOffersPage({ params }: { params: Promise<{ activityId: string }> }) {
  const { activityId } = await params;
  return <VisitOfferEditor activityId={activityId}/>;
}
