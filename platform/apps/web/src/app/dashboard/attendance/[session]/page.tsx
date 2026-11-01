import { Suspense } from 'react';
import { AttendanceSessionPage } from '@/features/attendance/session-page';
export const metadata = { title: 'Присъствия | Progressive Activities' };
export default async function Page({ params }: { params: Promise<{ session: string }> }) {
  const { session } = await params;
  return <Suspense><AttendanceSessionPage sessionId={session} /></Suspense>;
}
