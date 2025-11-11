# Identity Clarifier Component

## Active Component

**Currently using:** `ToolInline.js` (inline template version)

This component is registered in `tool.js` and used for both:
- `identity-clarifier` (Nova ResourceTool)
- `identity-clarifier-card` (Nova Card)

## File Status

| File | Status | Notes |
|------|--------|-------|
| `ToolInline.js` | ✅ **ACTIVE** | Inline template version, currently used |
| `tool.js` | ✅ **ACTIVE** | Registers ToolInline.js with Nova |
| `Tool.vue` | ⚠️ Not used | Composition API version (kept for future reference) |
| `ToolOptionsAPI.vue` | ⚠️ Not used | Options API version (kept for future reference) |
| `components/CandidateSelector.vue` | ⚠️ Not used | Used by Tool.vue (not by ToolInline.js) |

## Important Logic

### Visibility Rules

The component will **hide itself completely** when:
- `task === null` (research completed or not started)
- After initial loading (`loading === false`)

This is controlled by: `v-if="task || loading"` in the template root.

### Polling Behavior

The component polls `/api/exams/{examId}/pending-task` every 5 seconds.

**Auto-stop conditions:**
- When `task === null` (no pending tasks)
- The interval is cleared and component hides

### Backend Integration

Backend controls card visibility in `app/Nova/Exam.php::cards()`:
```php
// Only show if task is pending AND research not completed
if ($task &&
    in_array($task->status, ['pending_confirmation', 'pending_clarification'], true) &&
    $exam->research_status !== 'completed') {
    $cards[] = new IdentityClarifierCard();
}
```

## Recent Changes

**2025-11-08:** Fixed issue where "No clarification needed" message was showing on completed exams.

**Changes made:**
1. Added `v-if="task || loading"` to hide component when no task
2. Changed polling stop logic from `!needsClarification` to `!task`
3. Removed "No clarification needed" section from template
4. Component now fully hides instead of showing success message

This ensures the component is invisible when research is completed.
