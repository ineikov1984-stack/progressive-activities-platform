import styles from '@/features/catalog/public/catalog.module.css';

export default function PublicCatalogLoading() {
  return <main className={styles.publicShell} aria-busy="true"><section className={styles.emptyState}>
    <p className={styles.eyebrow}>ДЕЙНОСТИ</p><h1>Зареждаме страницата</h1>
    <p role="status">Още малко — подготвяме актуалните предложения.</p>
  </section></main>;
}
