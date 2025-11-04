// Try inline template version (no SFC compilation)
import Tool from './ToolInline.js'

console.log('[Identity Clarifier] tool.js loading INLINE version...')

Nova.booting((app, store) => {
  console.log('[Identity Clarifier] Nova booting, registering INLINE component...', { app, store })

  // Register main components
  app.component('identity-clarifier', Tool)
  app.component('identity-clarifier-card', Tool)

  console.log('[Identity Clarifier] Inline components registered:', {
    componentName: Tool.name,
    hasTemplate: !!Tool.template,
    hasMounted: !!Tool.mounted,
    templateLength: Tool.template?.length,
  })
})
