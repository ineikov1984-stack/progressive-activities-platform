import type { Metadata } from 'next';
import { OperatorConsole } from '@/features/organizations/operator/operator-console';

export const metadata: Metadata = { title: 'Кандидатури | Progressive Activities' };

export default function OperatorOrganizationsPage() {
  return <OperatorConsole />;
}
