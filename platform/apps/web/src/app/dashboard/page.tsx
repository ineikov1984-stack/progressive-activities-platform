import type { Metadata } from 'next';
import { Dashboard } from '@/features/dashboard/dashboard';

export const metadata: Metadata = {
  title: 'Администрация | Progressive Activities',
};

export default function DashboardPage() {
  return <Dashboard />;
}
