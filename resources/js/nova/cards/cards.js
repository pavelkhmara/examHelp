// Import Nova Card components
import OverviewStatusCard from './OverviewStatusCard.vue';
import GenerationProgressCard from './GenerationProgressCard.vue';
import ExamWorkflowHub from './ExamWorkflowHub.vue';

console.log('[Nova Cards] Registering v2 cards...');

Nova.booting((app, store) => {
  console.log('[Nova Cards] Nova booting, registering card components...');

  // Register card components
  app.component('overview-status-card', OverviewStatusCard);
  app.component('generation-progress-card', GenerationProgressCard);
  app.component('exam-workflow-hub', ExamWorkflowHub);

  console.log('[Nova Cards] Cards registered successfully:', {
    overviewStatusCard: !!app.component('overview-status-card'),
    generationProgressCard: !!app.component('generation-progress-card'),
    examWorkflowHub: !!app.component('exam-workflow-hub'),
  });
});
