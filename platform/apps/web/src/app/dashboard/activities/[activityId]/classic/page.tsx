import type { Metadata } from 'next';
import { ActivityEditor } from '@/features/catalog/admin/activity-editor';

// The previous all-in-one editor. Steps 5–7 of the wizard still link here until they are rebuilt.
export const metadata: Metadata = { title: 'Редактор на дейност | Progressive Activities' };
export default async function ClassicActivityPage({ params }: { params: Promise<{ activityId: string }> }) { const { activityId } = await params; return <ActivityEditor activityId={activityId}/>; }
