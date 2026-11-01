'use client';
import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { useDashboardContext } from '../dashboard/dashboard-context';
import { AttendanceSessionPage } from './session-page';
import styles from './attendance.module.css';

/** The existing editor, within the current screen; its own queue guards every way of closing. */
export function AttendanceSessionButton({sessionId,children,className,onClosed}:{sessionId:string;children:ReactNode;className?:string;onClosed?:()=>void}) {
  const {organization}=useDashboardContext();
  const [opened,setOpened]=useState(false);const button=useRef<HTMLButtonElement>(null);
  const after=useRef(onClosed);useEffect(()=>{after.current=onClosed;});
  const close=useCallback(()=>{setOpened(false);after.current?.();requestAnimationFrame(()=>button.current?.focus());},[]);
  return <><button ref={button} data-attendance-session={sessionId} className={className??styles.primary} onClick={()=>setOpened(true)} aria-haspopup="dialog">{children}</button>{opened?<SessionDialog key={`${organization.id}:${sessionId}`} sessionId={sessionId} onClose={close}/>:null}</>;
}
function SessionDialog({sessionId,onClose}:{sessionId:string;onClose:()=>void}) {
  const dialog=useRef<HTMLDialogElement>(null);const closeRequestRef=useRef<(()=>void)|null>(null);
  useEffect(()=>{dialog.current?.showModal();},[]);
  const close=()=>{if(closeRequestRef.current)closeRequestRef.current();else onClose();};
  return <dialog ref={dialog} className={styles.sessionDialog} aria-label="Присъствия за заниманието" onCancel={(e)=>{e.preventDefault();close();}} onClick={(e)=>{if(e.target===dialog.current)close();}}><AttendanceSessionPage sessionId={sessionId} onClose={onClose} closeRequestRef={closeRequestRef}/></dialog>;
}
