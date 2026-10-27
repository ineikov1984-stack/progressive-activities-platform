import type { Metadata } from 'next';
import './globals.css';

export const metadata: Metadata = {
  title: 'Progressive Activities',
  description: 'Извънкласни дейности за всяко дете',
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="bg">
      <body>{children}</body>
    </html>
  );
}
