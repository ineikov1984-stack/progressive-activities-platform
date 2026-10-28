'use client';

import styles from '@/features/catalog/public/catalog.module.css';

export default function PublicCatalogError({ reset }: { reset: () => void }) {
  return <main className={styles.publicShell}><section className={styles.emptyState}>
    <p className={styles.eyebrow}>ВРЕМЕННО НЕДОСТЪПНО</p><h1>Не успяхме да заредим страницата</h1>
    <p>Каталогът временно не е достъпен. Опитайте отново след малко.</p>
    <button className={styles.primaryButton} type="button" onClick={reset}>Опитай отново</button>
  </section></main>;
}
