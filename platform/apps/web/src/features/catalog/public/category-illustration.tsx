'use client';

import Image from 'next/image';
import { useState } from 'react';
import { publicMediaUrl } from './api';
import styles from './catalog.module.css';

function categoryKind(category: string) {
  const value = category.toLocaleLowerCase('bg');
  if (/шах|chess/.test(value)) return 'chess';
  if (/спорт|sport|танц|dance|футбол|плуване/.test(value)) return 'sport';
  if (/изкуств|рисув|art|творч/.test(value)) return 'arts';
  if (/език|language|англий|немски/.test(value)) return 'languages';
  if (/техно|програм|робот|tech|coding|stem/.test(value)) return 'technology';
  if (/музик|music|пиано|китар|пеене/.test(value)) return 'music';
  return 'education';
}

/** Decorative, deterministic artwork. Geometry is shared with published photography. */
export function CategoryIllustration({ category }: { category: string }) {
  const kind = categoryKind(category);
  return <svg className={styles.categoryArt} viewBox="0 0 800 600" aria-hidden="true" focusable="false" data-category-art={kind}>
    <rect width="800" height="600" fill="#f4eee3" />
    <circle cx="620" cy="110" r="190" fill="#e7be91" opacity=".45" />
    <circle cx="95" cy="510" r="190" fill="var(--catalog-accent)" opacity=".16" />
    <rect x="175" y="90" width="450" height="425" rx="100" fill="#fffaf3" transform="rotate(-8 400 300)" />
    <path d="M90 190h34m-17-17v34M665 440h34m-17-17v34" stroke="#c16a34" strokeWidth="8" strokeLinecap="round" />
    <circle cx="658" cy="273" r="12" fill="var(--catalog-accent)" />
    <circle cx="144" cy="350" r="8" fill="#e3823f" />
    <ellipse cx="400" cy="476" rx="160" ry="19" fill="#183a5a" opacity=".09" />
    <g stroke="#183a5a" strokeWidth="12" strokeLinejoin="round" strokeLinecap="round">
      {kind === 'chess' && <><path d="M300 425h200l24 39H276z" fill="#183a5a" /><path d="M330 410h140l-29-127h-82z" fill="#e3823f" /><circle cx="400" cy="218" r="63" fill="#183a5a" /><path d="M347 297h106" /><path d="M394 180c-15 0-27 13-27 27" stroke="#fff9f4" strokeWidth="8" /></>}
      {kind === 'sport' && <><circle cx="400" cy="305" r="135" fill="#e3823f" /><path d="m400 245 57 42-22 65h-70l-22-65zM400 170v75M271 263l72 24M318 414l47-62M483 414l-48-62M529 263l-72 24" fill="#fff9f4" /></>}
      {kind === 'arts' && <><path d="M300 198c-128 100-43 266 90 250 60-7 66-50 35-71-31-23-8-45 32-35 85 20 121-97 12-150-63-31-122-31-169 6z" fill="#fff9f4" /><circle cx="303" cy="291" r="21" fill="#e3823f" stroke="none" /><circle cx="346" cy="228" r="21" fill="var(--catalog-accent)" stroke="none" /><circle cx="425" cy="228" r="21" fill="#183a5a" stroke="none" /><path d="m432 422 100-211" stroke="#e3823f" strokeWidth="25" /><path d="m530 215 22-49" /></>}
      {kind === 'languages' && <><path d="M235 200h270v164H350l-65 52v-52h-50z" fill="#fff9f4" /><path d="M405 310h160v125h-35v41l-55-41h-70z" fill="#e3823f" /><path d="m292 313 36-77 36 77m-59-25h47M450 356h68m-34-18v18m-23 0c6 25 21 42 44 51m-1-51c-7 23-20 40-42 51" strokeWidth="9" /></>}
      {kind === 'technology' && <><rect x="260" y="225" width="280" height="210" rx="42" fill="#fff9f4" /><path d="M400 225v-54M260 295h-28v72h28m280-72h28v72h-28" /><circle cx="400" cy="155" r="20" fill="#e3823f" /><circle cx="340" cy="303" r="22" fill="var(--catalog-accent)" /><circle cx="460" cy="303" r="22" fill="var(--catalog-accent)" /><path d="M350 380h100" /></>}
      {kind === 'music' && <><path d="M355 397V202l172-40v195M355 252l172-40" fill="none" /><ellipse cx="313" cy="407" rx="43" ry="32" fill="#e3823f" transform="rotate(-20 313 407)" /><ellipse cx="485" cy="367" rx="43" ry="32" fill="var(--catalog-accent)" transform="rotate(-20 485 367)" /></>}
      {kind === 'education' && <><path d="M400 243c-47-39-109-48-165-25v206c60-22 116-14 165 23 49-37 105-45 165-23V218c-56-23-118-14-165 25z" fill="#fff9f4" /><path d="M400 243v204M278 279l76 21m-76 38 76 21m92-59 76-21m-76 80 76-21" /><path d="m400 145 13 27 30 5-22 21 5 30-26-14-26 14 5-30-22-21 30-5z" fill="#e3823f" stroke="none" /></>}
    </g>
  </svg>;
}

export function PublicMedia({ url, title, category, priority = false }: { url?: string | null; title: string; category: string; priority?: boolean }) {
  const src = publicMediaUrl(url);
  return src ? <MediaImage key={src} src={src} title={title} category={category} priority={priority} /> : <CategoryIllustration category={category} />;
}

function MediaImage({ src, title, category, priority }: { src: string; title: string; category: string; priority: boolean }) {
  const [failed, setFailed] = useState(false);
  return failed ? <CategoryIllustration category={category} /> : <Image src={src} alt={title} fill priority={priority} sizes="(max-width: 600px) 100vw, (max-width: 900px) 75vw, 50vw" unoptimized onError={() => setFailed(true)} />;
}
