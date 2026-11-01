import {afterEach,expect,it,vi} from 'vitest';
import {AttendanceError,saveAttendance,loadSheet} from '../src/features/attendance/api';
afterEach(()=>vi.unstubAllGlobals());
it('keeps organization, CSRF, exact body and idempotency key on attendance writes',async()=>{
 vi.stubGlobal('document',{cookie:'XSRF-TOKEN=csrf%20value'}); const fetch=vi.fn().mockResolvedValue(new Response(JSON.stringify({applied:[]}),{status:200})); vi.stubGlobal('fetch',fetch);
 const change={action:'mark' as const,marks:[{enrollment_id:'child',status:'present' as const,version:2}]}; await saveAttendance('org','session',change,'stable');
 expect(fetch).toHaveBeenCalledWith('/backend/api/v1/attendance/sessions/session/changes',expect.objectContaining({credentials:'include',cache:'no-store',body:JSON.stringify(change),headers:expect.objectContaining({'X-Organization-Id':'org','X-XSRF-TOKEN':'csrf value','Idempotency-Key':'stable'})}));
});
it('retains conflict roster and retry flag for the queue',async()=>{
 vi.stubGlobal('fetch',vi.fn().mockResolvedValue(new Response(JSON.stringify({message:'Променено',children:[],conflicts:['child']}),{status:409})));
 try {await loadSheet('org','session'); throw new Error('expected conflict');} catch(e) {expect(e).toBeInstanceOf(AttendanceError);expect((e as AttendanceError).body.conflicts).toEqual(['child']);}
});
it('treats non-JSON server errors as retryable errors instead of parsing failures',async()=>{
 vi.stubGlobal('fetch',vi.fn().mockResolvedValue(new Response('unavailable',{status:503}))); await expect(loadSheet('org','session')).rejects.toMatchObject({status:503});
});
