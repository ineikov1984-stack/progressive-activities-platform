import {describe,expect,it} from 'vitest';
import {contextualTopic,helpTopics} from '../src/features/help/topics';
describe('workspace help',()=>{
 it('only explains available areas',()=>{expect(helpTopics([])).toEqual([]);expect(helpTopics(['attendance.mark']).map(t=>t.id)).toEqual(['attendance']);expect(helpTopics(['enrollment.view_own'])).toEqual([]);});
 it('uses review instructions when editing is unavailable',()=>{const topic=helpTopics(['terms.view'])[0];expect(topic.label).toContain('прегледам');expect(topic.steps.at(-1)?.text).not.toContain('натиснете „Запази');expect(helpTopics(['attendance.view_month'])[0].label).toContain('прегледам');});
 it('explains saving and marking only with the corresponding permission',()=>{expect(helpTopics(['terms.view','terms.manage'])[0].steps.at(-1)?.text).toContain('Запази условията');expect(helpTopics(['attendance.mark'])[0].label).toContain('отбележа');});
 it('prefers a matching available topic and keeps unrelated pages neutral',()=>{const topics=helpTopics(['terms.view','enrollment.view','attendance.mark']);expect(contextualTopic('/dashboard/settings/payment-terms',topics)?.id).toBe('terms');expect(contextualTopic('/dashboard/enrollments',topics)?.id).toBe('groups');expect(contextualTopic('/dashboard/my/schedule',topics)?.id).toBe('attendance');expect(contextualTopic('/dashboard',topics)).toBeUndefined();expect(contextualTopic('/dashboard/enrollments',helpTopics(['terms.view']))).toBeUndefined();});
});
