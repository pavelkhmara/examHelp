<template>
  <div class="candidate-selector">
    <div class="mb-4">
      <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100 mb-2">
        🔍 Select Exam Variant
      </h3>
      <p class="text-gray-600 dark:text-gray-400">
        AI found multiple possible exams. Please select the correct one:
      </p>
    </div>

    <div class="space-y-3 mb-6">
      <label
        v-for="(candidate, index) in candidates"
        :key="index"
        class="block p-4 border-2 rounded-lg cursor-pointer transition-all duration-200 hover:shadow-md"
        :class="{
          'border-primary-500 bg-primary-50 dark:bg-gray-800': selectedIndex === index,
          'border-gray-300 dark:border-gray-600 hover:border-primary-300': selectedIndex !== index,
        }"
      >
        <div class="flex items-start">
          <input
            type="radio"
            :value="index"
            v-model="selectedIndex"
            class="mt-1 mr-3 h-4 w-4 text-primary-600 focus:ring-primary-500"
          />
          <div class="flex-1">
            <div class="font-semibold text-gray-900 dark:text-gray-100">
              {{ candidate.family || candidate.name || candidate.title || 'Unknown Exam' }}
            </div>
            <div v-if="candidate.name && candidate.family && candidate.name !== candidate.family" class="text-sm text-gray-700 dark:text-gray-300 mt-1">
              Name: {{ candidate.name }}
            </div>
            <div v-if="candidate.title && candidate.title !== candidate.family && candidate.title !== candidate.name" class="text-sm text-gray-700 dark:text-gray-300 mt-1">
              Title: {{ candidate.title }}
            </div>
            <div class="text-sm text-gray-600 dark:text-gray-400 mt-1">
              Provider: {{ candidate.provider || 'Unknown Provider' }}
            </div>
            <div v-if="candidate.level" class="text-sm text-gray-600 dark:text-gray-400 mt-1">
              Level: {{ candidate.level }}
            </div>
            <div v-if="candidate.score || candidate.confidence" class="text-sm text-blue-600 dark:text-blue-400 mt-1">
              Confidence: {{ Math.round((candidate.score || candidate.confidence) * 100) }}%
            </div>
          </div>
        </div>
      </label>
    </div>

    <div class="flex gap-3">
      <button
        @click="confirmSelection"
        :disabled="selectedIndex === null || submitting"
        class="btn btn-primary px-4 py-2 rounded-lg font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
        :class="{
          'bg-primary-500 hover:bg-primary-600 text-white': selectedIndex !== null && !submitting,
          'bg-gray-300 text-gray-500': selectedIndex === null || submitting,
        }"
      >
        {{ submitting ? 'Submitting...' : 'Confirm Selection' }}
      </button>
    </div>

    <!-- Success message -->
    <div
      v-if="successMessage"
      class="mt-4 p-3 bg-green-100 dark:bg-green-900 border border-green-300 dark:border-green-700 rounded-lg"
    >
      <p class="text-green-800 dark:text-green-200">
        ✓ {{ successMessage }}
      </p>
    </div>

    <!-- Error message -->
    <div
      v-if="errorMessage"
      class="mt-4 p-3 bg-red-100 dark:bg-red-900 border border-red-300 dark:border-red-700 rounded-lg"
    >
      <p class="text-red-800 dark:text-red-200">
        ✗ {{ errorMessage }}
      </p>
    </div>
  </div>
</template>

<script>
export default {
  name: 'CandidateSelector',

  props: {
    candidates: {
      type: Array,
      required: true,
    },
    taskId: {
      type: Number,
      required: false,
    },
    examId: {
      type: [String, Number],
      required: false,
    },
  },

  emits: ['selected'],

  data() {
    return {
      selectedIndex: null,
      submitting: false,
      successMessage: '',
      errorMessage: '',
    }
  },

  mounted() {
    console.log('[CandidateSelector Options API] Mounted with props:', {
      taskId: this.taskId,
      examId: this.examId,
      candidatesCount: this.candidates?.length,
    })
  },

  methods: {
    async confirmSelection() {
      if (this.selectedIndex === null) return

      this.submitting = true
      this.errorMessage = ''
      this.successMessage = ''

      try {
        const response = await Nova.request().post(
          `/api/exams/${this.examId}/research/${this.taskId}/clarify`,
          {
            clarification_type: 'select_candidate',
            selected_candidate: this.candidates[this.selectedIndex],
          }
        )

        this.successMessage = response.data.message || 'Selection confirmed! Pipeline continues.'

        // Emit event to parent to refresh
        setTimeout(() => {
          this.$emit('selected', response.data)
        }, 1500)
      } catch (error) {
        console.error('Failed to select candidate:', error)
        this.errorMessage = error.response?.data?.message || 'Failed to select candidate. Please try again.'
      } finally {
        this.submitting = false
      }
    },
  },
}
</script>

<style scoped>
.candidate-selector {
  /* Additional custom styles if needed */
}
</style>
