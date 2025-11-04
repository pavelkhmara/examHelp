// Direct Nova tool registration (no build step needed)
document.addEventListener('DOMContentLoaded', function() {
  console.log('[Identity Clarifier] DOM loaded, waiting for Nova...');

  // Wait for Nova to be available
  const waitForNova = setInterval(() => {
    if (typeof Nova !== 'undefined' && Nova.booting) {
      clearInterval(waitForNova);
      console.log('[Identity Clarifier] Nova found, registering component...');

      // Import Vue components dynamically
      import('./nova/tools/identity-clarifier/tool.js')
        .then(() => {
          console.log('[Identity Clarifier] Component registered successfully');
        })
        .catch(err => {
          console.error('[Identity Clarifier] Failed to load component:', err);
        });
    }
  }, 100);

  // Timeout after 10 seconds
  setTimeout(() => {
    clearInterval(waitForNova);
    if (typeof Nova === 'undefined') {
      console.error('[Identity Clarifier] Nova not found after 10 seconds');
    }
  }, 10000);
});
