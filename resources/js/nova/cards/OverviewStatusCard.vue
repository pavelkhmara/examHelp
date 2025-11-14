<template>
  <Card class="px-6 py-4">
    <h3 class="text-base font-bold mb-4">📋 Overview Status</h3>

    <div class="space-y-3">
      <!-- Phase A -->
      <div class="flex items-start">
        <svg v-if="card.phaseA.completed" class="w-5 h-5 text-green-500 mr-2 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
        </svg>
        <svg v-else class="w-5 h-5 text-gray-400 mr-2 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
        </svg>
        <div>
          <span class="font-medium">Phase A: Skeleton</span>
          <div v-if="card.phaseA.completed" class="text-sm text-gray-600 ml-0 mt-1">
            → {{ card.phaseA.sections_count }} sections generated
            <span v-if="card.phaseA.generated_at" class="text-xs text-gray-500">
              ({{ formatDate(card.phaseA.generated_at) }})
            </span>
          </div>
        </div>
      </div>

      <!-- Phase B -->
      <div class="flex items-start">
        <svg v-if="card.phaseB.completed" class="w-5 h-5 text-green-500 mr-2 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
        </svg>
        <svg v-else class="w-5 h-5 text-gray-400 mr-2 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
        </svg>
        <div>
          <span class="font-medium">Phase B: Assembly Plan</span>
          <div v-if="card.phaseB.completed" class="text-sm text-gray-600 ml-0 mt-1">
            → Mode: <span class="font-mono bg-gray-100 px-1 rounded">{{ card.phaseB.assembly_mode }}</span><br>
            → {{ card.phaseB.tasks_count }} task placeholders
          </div>
        </div>
      </div>
    </div>
  </Card>
</template>

<script>
export default {
  props: ['card'],
  methods: {
    formatDate(dateString) {
      if (!dateString) return '';
      const date = new Date(dateString);
      return date.toLocaleString('en-GB', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
      });
    }
  }
}
</script>
