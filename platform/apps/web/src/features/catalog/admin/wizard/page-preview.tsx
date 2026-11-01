'use client';

import { useEffect, useRef, useState } from 'react';
import type { ActivityDraft, ActivityGroup, CatalogProfile, InstructorProfile, Location } from '../api';
import { ActivityPage } from '../../public/activity-page';
import { CategoryIllustration } from '../../public/category-illustration';
import { PrivateImage } from '../private-image';
import { pagePreviewData } from './page-preview-data';
import { Icon, icons } from './ui';
import styles from './page-preview.module.css';

type Props = { activity:ActivityDraft; groups:ActivityGroup[]; locations:Location[]; instructors:InstructorProfile[]; profile:CatalogProfile|null; organizationId:string; organizationName:string };

export function PagePreview(props:Props) {
  const [open,setOpen] = useState(false);
  const trigger = useRef<HTMLButtonElement>(null);
  return <><button ref={trigger} type="button" className={styles.trigger} aria-haspopup="dialog" onClick={()=>setOpen(true)}><Icon path={icons.eye}/>Преглед на страницата</button>
    {open && <PreviewDialog {...props} onClose={()=>{setOpen(false);requestAnimationFrame(()=>trigger.current?.focus());}}/>}</>;
}

function PreviewDialog({onClose,...props}:Props & {onClose:()=>void}) {
  const ref = useRef<HTMLDialogElement>(null);
  const heading = useRef<HTMLHeadingElement>(null);
  const data = pagePreviewData(props.activity,props.groups,props.locations,props.instructors,props.profile,props.organizationName);
  const sections = props.activity.sections;
  const missing = [!sections.about?.trim() && 'подробно описание', !sections.benefits?.some(x=>x.trim()) && 'ползи', !sections.methodology?.trim() && 'подход', !props.activity.hero_image_path && 'снимка', !sections.faq?.some(x=>x.question.trim()&&x.answer.trim()) && 'въпроси и отговори'].filter(Boolean);
  useEffect(()=>{
    const node=ref.current;
    if(node&&!node.open)node.showModal();
    heading.current?.focus();
    const previous=document.body.style.overflow;
    document.body.style.overflow='hidden';
    return ()=>{document.body.style.overflow=previous;};
  },[]);
  return <dialog ref={ref} className={styles.dialog} aria-labelledby="activity-page-preview-title" onClose={onClose} onClick={event=>{if(event.target===ref.current)ref.current?.close();}} onKeyDown={event=>{
    if(event.key!=='Tab')return;
    const targets=Array.from(event.currentTarget.querySelectorAll<HTMLElement>('button,a[href],summary')).filter(node=>node.checkVisibility());
    const first=targets[0],last=targets.at(-1);
    if(event.shiftKey&&(document.activeElement===first||document.activeElement===heading.current)){event.preventDefault();last?.focus();}
    else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first?.focus();}
  }}>
    <div className={styles.panel}>
      <header className={styles.header}><div><h2 id="activity-page-preview-title" ref={heading} tabIndex={-1}>Преглед на страницата</h2><p>Текущото съдържание от редактора · без публикуване</p></div><button type="button" className={styles.close} aria-label="Затвори прегледа" onClick={()=>ref.current?.close()}>×</button></header>
      <div className={styles.body}>
        <aside className={styles.note} aria-label="Насоки за съдържанието"><p>Това е преглед на съдържанието. Записването, свободните места и специалните посещения се показват на публичния сайт след публикуване.</p>{missing.length>0?<details><summary>Идеи за по-пълно представяне · {missing.length}</summary><p>Може да добавите {missing.join(', ')}. Конкретните обяснения помагат на родителите да изберат и дават повече съдържание на търсачките. Не са задължителни за публикуване.</p></details>:<p>Имате описание, ползи, подход, снимка и въпроси с отговори.</p>}</aside>
        <ActivityPage response={data} preview renderMedia={(path,title,category)=>path?<div className={styles.media}><PrivateImage organizationId={props.organizationId} path={path} alt={title} fill sizes="(max-width: 600px) 100vw, 50vw"/></div>:<CategoryIllustration category={category}/>}/>
      </div>
      <footer className={styles.footer}><span>За промяна се върнете в редактора. Прегледът използва последните въведени данни.</span><button type="button" className={styles.trigger} onClick={()=>ref.current?.close()}>Обратно към редактора</button></footer>
    </div>
  </dialog>;
}
