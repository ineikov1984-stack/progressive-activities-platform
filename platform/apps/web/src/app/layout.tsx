import type { Metadata } from 'next';
import { connection } from 'next/server';
import { Onest } from 'next/font/google';
import './globals.css';

const onest = Onest({
  subsets: ['latin', 'cyrillic'],
  variable: '--font-onest',
  display: 'swap',
});

export const metadata: Metadata = {
  title: 'Progressive Activities',
  description: 'Извънкласни дейности за всяко дете',
};

export default async function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  // A nonce belongs to one request, so HTML containing it must never be prerendered.
  await connection();
  return (
    <html lang="bg" className={onest.variable}>
      <body>{children}</body>
    </html>
  );
}
