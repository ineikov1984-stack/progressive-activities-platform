'use client';

import { useEffect, useRef, useState } from 'react';
import { contextualTopic, helpTopics, type HelpTopic } from './topics';
import styles from './context-help.module.css';

/** Local explanation only: no navigation, requests or persistent state. The shell keys it by workspace context. */
export function ContextHelp({ pathname, permissions }: { pathname: string; permissions: readonly string[] }) {
  const topics = helpTopics(permissions);
  const context = contextualTopic(pathname, topics);
  const [opened, setOpened] = useState(false);
  const opener = useRef<HTMLButtonElement>(null);
  if (!topics.length) return null;
  const close = () => {
    setOpened(false);
    requestAnimationFrame(() => { if (opener.current?.isConnected) opener.current.focus(); });
  };
  return <div className={styles.entry}>
    <button ref={opener} type="button" className={styles.trigger} aria-haspopup="dialog" onClick={() => setOpened(true)}>
      <span className={styles.info} aria-hidden="true">i</span>{context ? 'Помощ за този екран' : 'Помощ и обучение'}
    </button>
    {opened ? <HelpDialog topics={topics} initialId={context?.id ?? topics[0].id} onClose={close} /> : null}
  </div>;
}

function HelpDialog({ topics, initialId, onClose }: { topics: HelpTopic[]; initialId: HelpTopic['id']; onClose: () => void }) {
  const dialog = useRef<HTMLDialogElement>(null);
  const heading = useRef<HTMLHeadingElement>(null);
  const [selected, setSelected] = useState(initialId);
  const [index, setIndex] = useState(0);
  const topic = topics.find((item) => item.id === selected) ?? topics[0];
  const step = topic.steps[index];
  useEffect(() => {
    const node = dialog.current;
    if (node && !node.open) node.showModal();
    heading.current?.focus();
    const overflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => { document.body.style.overflow = overflow; };
  }, []);
  const finish = () => dialog.current?.close();
  return <dialog ref={dialog} className={styles.dialog} aria-labelledby="workspace-help-title" onClose={onClose} onKeyDown={(event) => {
    if (event.key !== 'Tab') return;
    const targets = Array.from(event.currentTarget.querySelectorAll<HTMLElement>('button:not([disabled]),select:not([disabled]),a[href]')).filter((node) => node.getClientRects().length > 0);
    const first = targets[0], last = targets.at(-1);
    if (event.shiftKey && (document.activeElement === first || document.activeElement === heading.current)) { event.preventDefault(); last?.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
  }} onClick={(event) => { if (event.target === dialog.current) finish(); }}>
    <div className={styles.panel}>
      <header className={styles.header}>
        <h2 ref={heading} id="workspace-help-title" tabIndex={-1}>Помощ и обучение</h2>
        <button type="button" className={styles.close} aria-label="Затвори помощта" onClick={finish}>×</button>
      </header>
      <div className={styles.body}>
        <label className={styles.field} htmlFor="workspace-help-topic">Какво искате да направите?
          <select id="workspace-help-topic" value={topic.id} onChange={(event) => { setSelected(event.target.value as HelpTopic['id']); setIndex(0); }}>
            {topics.map((item) => <option key={item.id} value={item.id}>{item.label}</option>)}
          </select>
        </label>
        <div className={styles.progress} aria-hidden="true">{topic.steps.map((item, at) => <span key={item.title} className={at <= index ? styles.reached : ''} />)}</div>
        <section className={styles.step} aria-live="polite" aria-atomic="true">
          <p className={styles.count}>Стъпка {index + 1} от {topic.steps.length}</p>
          <h3>{step.title}</h3><p>{step.text}</p>
          <div className={styles.example}><strong>Пример</strong><p>{step.example}</p></div>
        </section>
        <p className={styles.note}>Помощта обяснява стъпките. Не променя данни и не извършва действия вместо вас. Може да я затворите и да продължите работата си.</p>
      </div>
      <footer className={styles.actions}>
        <button type="button" className={styles.secondary} disabled={index === 0} onClick={() => setIndex((value) => value - 1)}>Назад</button>
        <button type="button" className={styles.primary} onClick={() => { if (index === topic.steps.length - 1) finish(); else setIndex((value) => value + 1); }}>{index === topic.steps.length - 1 ? 'Готово' : 'Напред'}</button>
      </footer>
    </div>
  </dialog>;
}
