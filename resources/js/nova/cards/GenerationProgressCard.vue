<template>
  <Card class="px-6 py-4">
    <h3 class="text-base font-bold mb-4">🔄 Generation Progress</h3>

    <div v-if="card.plans.length === 0" class="text-sm text-gray-500">
      No generation plans created yet
    </div>

    <div v-else class="space-y-3">
      <!-- Plan entries -->
      <div v-for="plan in card.plans" :key="plan.id" class="border-b border-gray-200 pb-2 last:border-0">
        <div class="flex justify-between items-start">
          <div>
            <span class="font-medium">{{ plan.section_name }}</span>
            <span :class="statusBadgeClass(plan.status)" class="ml-2 px-2 py-0.5 text-xs rounded">
              {{ statusLabel(plan.status) }}
            </span>
          </div>
          <span class="text-sm text-gray-600">
            {{ plan.generated_count }} / {{ plan.expected_count }}
          </span>
        </div>
        <div class="text-xs text-gray-500 mt-1">
          → {{ plan.generated_count }} questions generated
        </div>
      </div>

      <!-- Total summary -->
      <div class="mt-4 pt-3 border-t-2 border-gray-300">
        <div class="flex justify-between items-center mb-2">
          <span class="font-bold">Total:</span>
          <span class="font-bold">{{ card.total_generated }} / {{ card.total_expected }} questions</span>
        </div>

        <!-- Progress bar -->
        <div class="w-full bg-gray-200 rounded-full h-6 overflow-hidden">
          <div
            class="bg-blue-500 h-full flex items-center justify-center text-white text-xs font-semibold transition-all duration-300"
            :style="{ width: card.progress + '%' }"
          >
            <span v-if="card.progress > 10">{{ card.progress }}%</span>
          </div>
        </div>
        <div v-if="card.progress <= 10" class="text-center text-xs text-gray-600 mt-1">
          {{ card.progress }}%
        </div>
      </div>
    </div>
  </Card>
</template>

<script>
export default {
  props: ['card'],
  methods: {
    statusLabel(status) {
      const labels = {
        'pending': 'Pending',
        'in_progress': 'In Progress',
        'completed': 'Completed',
        'attached': 'Attached',
        'failed': 'Failed'
      };
      return labels[status] || status;
    },
    statusBadgeClass(status) {
      const classes = {
        'pending': 'bg-gray-200 text-gray-700',
        'in_progress': 'bg-yellow-100 text-yellow-800',
        'completed': 'bg-blue-100 text-blue-800',
        'attached': 'bg-green-100 text-green-800',
        'failed': 'bg-red-100 text-red-800'
      };
      return classes[status] || 'bg-gray-100 text-gray-700';
    }
  }
}
</script>
