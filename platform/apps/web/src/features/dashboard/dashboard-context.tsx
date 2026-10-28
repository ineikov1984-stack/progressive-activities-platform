'use client';

import { createContext, useContext } from 'react';
import type { DashboardOrganization, DashboardUser } from './api';

export type DashboardContextValue = {
  user: DashboardUser;
  organization: DashboardOrganization;
  organizations: DashboardOrganization[];
};

export const DashboardContext = createContext<DashboardContextValue | undefined>(undefined);

export function useDashboardContext(): DashboardContextValue {
  const value = useContext(DashboardContext);
  if (!value) throw new Error('DashboardContext is available only inside DashboardShell.');
  return value;
}
