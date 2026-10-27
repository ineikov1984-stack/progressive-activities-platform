import type { Metadata } from 'next';
import { ActivityEditor } from '@/features/catalog/admin/activity-editor';

export const metadata: Metadata = { title: 'Редактор на дейност | Progressive Activities' };
export default async function ActivityPage({ params }: { params: Promise<{ activityId: string }> }) { const { activityId } = await params; return <ActivityEditor activityId={activityId}/>; }
