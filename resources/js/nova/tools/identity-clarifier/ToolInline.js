export default {
  name: 'IdentityClarifier',

  template: `
    <div class="identity-clarifier-wrapper" style="min-height: 100px; position: relative;">
      <div v-if="loading" class="flex justify-center items-center p-8" style="min-height: 100px;">
        <svg class="animate-spin h-8 w-8 text-primary-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
      </div>

      <div v-else-if="!needsClarification" class="text-center p-8">
        <div class="text-green-500 dark:text-green-400 text-3xl mb-3">✓</div>
        <p class="text-gray-600 dark:text-gray-400 text-lg">
          No clarification needed
        </p>
      </div>

      <div v-else class="p-6 bg-white dark:bg-gray-800 rounded-lg shadow-sm">
        <!-- Inline candidate selector -->
        <div v-if="hasCandidates && taskId && computedExamId">
          <div class="mb-4">
            <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100 mb-2">
              🔍 Select Exam Variant (Inline)
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
                'border-blue-500 bg-blue-50': selectedIndex === index,
                'border-gray-300 hover:border-blue-300': selectedIndex !== index,
              }">
              <div class="flex items-start">
                <input
                  type="radio"
                  :value="index"
                  v-model="selectedIndex"
                  class="mt-1 mr-3 h-4 w-4"
                />
                <div class="flex-1">
                  <div class="font-semibold text-gray-900">
                    {{ candidate.family }}
                    <span v-if="candidate.variant" class="text-gray-600">
                      - {{ candidate.variant }}
                    </span>
                  </div>
                  <div class="text-sm text-gray-600 mt-1">
                    {{ candidate.provider || 'Unknown Provider' }}
                  </div>
                  <div v-if="candidate.description" class="text-sm text-gray-500 mt-1">
                    {{ candidate.description }}
                  </div>
                </div>
              </div>
            </label>
          </div>

          <div class="flex gap-3">
            <button
              @click="confirmSelection"
              :disabled="selectedIndex === null || submitting"
              class="px-4 py-2 rounded-lg font-medium transition-colors"
              :style="{
                backgroundColor: selectedIndex !== null && !submitting ? '#3b82f6' : '#d1d5db',
                color: selectedIndex !== null && !submitting ? '#ffffff' : '#6b7280',
                cursor: selectedIndex === null || submitting ? 'not-allowed' : 'pointer',
                opacity: selectedIndex === null || submitting ? '0.6' : '1',
              }">
              {{ submitting ? 'Submitting...' : 'Confirm Selection' }}
            </button>
          </div>

          <div v-if="successMessage" class="mt-4 p-3 bg-green-100 border border-green-300 rounded-lg">
            <p class="text-green-800">✓ {{ successMessage }}</p>
          </div>

          <div v-if="errorMessage" class="mt-4 p-3 bg-red-100 border border-red-300 rounded-lg">
            <p class="text-red-800">✗ {{ errorMessage }}</p>
          </div>
        </div>

        <div v-else class="text-center p-6">
          <p class="text-yellow-600">⚠ No candidates or missing data</p>
          <pre class="text-xs mt-2">{{ { hasCandidates, taskId, examId: computedExamId } }}</pre>
        </div>
      </div>
    </div>
  `,

  props: {
    resourceName: String,
    resourceId: [String, Number],
    examId: [String, Number],
  },

  data() {
    return {
      loading: true,
      task: null,
      identity: null,
      refreshInterval: null,
      selectedIndex: null,
      submitting: false,
      successMessage: '',
      errorMessage: '',
    }
  },

  computed: {
    needsClarification() {
      return (
        this.task?.status === 'pending_confirmation' ||
        this.task?.status === 'pending_clarification'
      )
    },

    hasCandidates() {
      return this.identity?.candidates?.length > 0
    },

    candidates() {
      return this.identity?.candidates || []
    },

    taskId() {
      return this.task?.id
    },

    computedExamId() {
      return this.examId || this.resourceId
    },
  },

  mounted() {
    console.log('[Identity Clarifier] Component mounted')

    this.fetchTask()

    // Auto-refresh every 5 seconds
    this.refreshInterval = setInterval(() => {
      this.fetchTask()
    }, 5000)
  },

  beforeUnmount() {
    if (this.refreshInterval) {
      clearInterval(this.refreshInterval)
    }
  },

  methods: {
    async fetchTask() {
      const actualExamId = this.computedExamId

      if (!actualExamId) {
        this.loading = false
        return
      }

      try {
        const url = `/api/exams/${actualExamId}/pending-task`
        const response = await Nova.request().get(url)

        this.task = response.data.task
        this.identity = this.task?.result?.identity || null

        // If task completed, stop auto-refresh
        if (this.task && !this.needsClarification && this.refreshInterval) {
          clearInterval(this.refreshInterval)
          this.refreshInterval = null
        }
      } catch (error) {
        console.error('[Identity Clarifier] Failed to fetch task:', error)
      } finally {
        this.loading = false
      }
    },

    async confirmSelection() {
      if (this.selectedIndex === null) return

      this.submitting = true
      this.errorMessage = ''
      this.successMessage = ''

      try {
        const response = await Nova.request().post(
          `/api/exams/${this.computedExamId}/research/${this.taskId}/clarify`,
          {
            clarification_type: 'select_candidate',
            selected_candidate: this.candidates[this.selectedIndex],
          }
        )

        this.successMessage = response.data.message || 'Selection confirmed!'

        setTimeout(() => {
          location.reload()
        }, 2000)
      } catch (error) {
        console.error('Failed to select candidate:', error)
        this.errorMessage = error.response?.data?.message || 'Failed to select candidate.'
      } finally {
        this.submitting = false
      }
    },
  },
}
