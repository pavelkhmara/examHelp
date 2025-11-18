export default {
  name: 'IdentityClarifier',

  template: `
    <div v-if="task || loading || cardType === 'candidates' || cardType === 'followup_questions'" class="identity-clarifier-wrapper" style="min-height: 100px; position: relative;">
      <div v-if="loading" class="flex justify-center items-center p-8" style="min-height: 100px;">
        <svg class="animate-spin h-8 w-8 text-primary-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
      </div>

      <div v-else class="p-6 bg-white dark:bg-gray-800 rounded-lg shadow-sm">
        <!-- Inline candidate selector -->
        <div v-if="cardType === 'candidates' && hasCandidates && computedTaskId && computedExamId">
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
              v-for="(candidate, index) in getCandidates()"
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
                    {{ candidate.family || candidate.name || candidate.title || 'Unknown Exam' }}
                  </div>
                  <div v-if="candidate.name && candidate.family && candidate.name !== candidate.family" class="text-sm text-gray-700 mt-1">
                    Name: {{ candidate.name }}
                  </div>
                  <div v-if="candidate.title && candidate.title !== candidate.family && candidate.title !== candidate.name" class="text-sm text-gray-700 mt-1">
                    Title: {{ candidate.title }}
                  </div>
                  <div class="text-sm text-gray-600 mt-1">
                    Provider: {{ candidate.provider || 'Unknown Provider' }}
                  </div>
                  <div v-if="candidate.level" class="text-sm text-gray-600 mt-1">
                    Level: {{ candidate.level }}
                  </div>
                  <div v-if="candidate.score || candidate.confidence" class="text-sm text-blue-600 mt-1">
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
              class="px-4 py-2 rounded-lg font-medium transition-colors"
              :class="{
                'bg-blue-600 hover:bg-blue-700 text-white': selectedIndex !== null && !submitting,
                'bg-gray-300 text-gray-600 cursor-not-allowed': selectedIndex === null || submitting,
              }">
              {{ submitting ? 'Submitting...' : '✓ Confirm Selected' }}
            </button>
          </div>

          <!-- Debug info -->
          <div class="text-xs text-gray-500 mt-2">
            Debug: selectedIndex = {{ selectedIndex }}, submitting = {{ submitting }}, disabled = {{ selectedIndex === null || submitting }}
          </div>

          <div v-if="successMessage" class="mt-4 p-3 bg-green-100 border border-green-300 rounded-lg">
            <p class="text-green-800">✓ {{ successMessage }}</p>
          </div>

          <div v-if="errorMessage" class="mt-4 p-3 bg-red-100 border border-red-300 rounded-lg">
            <p class="text-red-800">✗ {{ errorMessage }}</p>
          </div>
        </div>

        <!-- Followup questions and/or Need fields -->
        <div v-else-if="cardType === 'followup_questions' && (hasFollowups || hasNeedFields)">
          <div class="mb-4">
            <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100 mb-2">
              📋 Additional Information Required
            </h3>
            <p class="text-gray-600 dark:text-gray-400">
              Please provide the following information to continue:
            </p>
          </div>

          <!-- Questions Form -->
          <div v-if="hasFollowups && card.followups?.length > 0" class="mb-6">
            <p class="font-semibold mb-3 text-gray-900 dark:text-gray-100">Questions to answer:</p>
            <div v-for="(question, index) in card.followups" :key="'q-' + index" class="mb-4">
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                {{ index + 1 }}. {{ typeof question === 'string' ? question : question.q || question }}
              </label>
              <textarea
                v-model="answerInputs[index]"
                :placeholder="'Your answer to question ' + (index + 1)"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100"
                rows="3"
              ></textarea>
            </div>
          </div>

          <!-- Fields Form -->
          <div v-if="hasNeedFields && card.needFields?.length > 0" class="mb-6">
            <p class="font-semibold mb-3 text-gray-900 dark:text-gray-100">Required fields:</p>
            <div v-for="field in card.needFields" :key="'f-' + field" class="mb-4">
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                <code class="bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded text-sm">{{ field }}</code>
              </label>
              <input
                v-model="fieldInputs[field]"
                :placeholder="'Provide value for ' + field"
                type="text"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100"
              />
            </div>
          </div>

          <!-- Submit Button -->
          <div class="flex gap-3">
            <button
              @click="submitClarification"
              :disabled="submitting"
              class="px-4 py-2 rounded-lg font-medium transition-colors"
              :style="{
                backgroundColor: !submitting ? '#3b82f6' : '#d1d5db',
                color: !submitting ? '#ffffff' : '#6b7280',
                cursor: submitting ? 'not-allowed' : 'pointer',
                opacity: submitting ? '0.6' : '1',
              }">
              {{ submitting ? 'Submitting...' : 'Submit Answers' }}
            </button>
          </div>

          <div v-if="successMessage" class="mt-4 p-3 bg-green-100 border border-green-300 rounded-lg">
            <p class="text-green-800">✓ {{ successMessage }}</p>
          </div>

          <div v-if="errorMessage" class="mt-4 p-3 bg-red-100 border border-red-300 rounded-lg">
            <p class="text-red-800">✗ {{ errorMessage }}</p>
          </div>
        </div>

        <!-- Fallback -->
        <div v-else class="text-center p-6">
          <p class="text-yellow-600">⚠ No candidates or missing data</p>
          <pre class="text-xs mt-2">{{ { hasCandidates, hasFollowups, hasNeedFields, taskId: computedTaskId, examId: computedExamId, cardType } }}</pre>
        </div>
      </div>
    </div>
  `,

  props: {
    resourceName: String,
    resourceId: [String, Number],
    examId: [String, Number],
    taskId: Number,
    candidates: Array,
    followups: Array,
    needFields: Array,
    checkResult: Object,
    card: Object, // Nova passes card data through this prop
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
      answerInputs: {}, // Ответы на вопросы
      fieldInputs: {},  // Значения полей
    }
  },

  watch: {
    selectedIndex(newVal, oldVal) {
      console.log('[ToolInline] selectedIndex changed:', { old: oldVal, new: newVal })
    },
  },

  computed: {
    // Get data from card prop (Nova passes it here)
    cardData() {
      return this.card || {}
    },

    cardType() {
      return this.card?.cardType
    },

    needsClarification() {
      return (
        this.task?.status === 'pending_confirmation' ||
        this.task?.status === 'pending_clarification'
      )
    },

    hasCandidates() {
      // Priority: card prop > direct props > task result
      const fromCard = this.cardData.candidates?.length > 0
      const fromProps = this.$props.candidates?.length > 0
      const fromTask = this.identity?.candidates?.length > 0

      if (this.cardType === 'candidates') {
        return fromCard || fromProps
      }
      return fromTask
    },

    candidates() {
      // NOTE: This computed property may cache undefined if accessed in mounted()
      // Use getCandidates() method instead for v-for to avoid caching issues
      if (this.cardType === 'candidates') {
        return this.card?.candidates || this.$props.candidates || []
      }
      return this.identity?.candidates || []
    },

    hasFollowups() {
      // Only show followups for followup_questions card type
      if (this.cardType === 'followup_questions') {
        const fromCard = this.card?.followups?.length > 0
        const fromProps = this.$props.followups?.length > 0
        return fromCard || fromProps
      }
      return false
    },

    followups() {
      // Priority: card prop > direct props > task result
      if (this.cardType === 'followup_questions') {
        return this.card?.followups || this.$props.followups || []
      }
      return this.identity?.followups || []
    },

    hasNeedFields() {
      // Only show needFields for followup_questions card type
      if (this.cardType === 'followup_questions') {
        const fromCard = this.card?.needFields?.length > 0
        const fromProps = this.$props.needFields?.length > 0
        return fromCard || fromProps
      }
      return false
    },

    needFields() {
      // Priority: card prop > direct props > task result
      if (this.cardType === 'followup_questions') {
        return this.card?.needFields || this.$props.needFields || []
      }
      return this.identity?.need_fields || []
    },

    computedTaskId() {
      // Priority: card prop > direct props > task
      return this.card?.taskId || this.$props.taskId || this.task?.id
    },

    computedExamId() {
      return this.card?.examId || this.$props.examId || this.resourceId
    },
  },

  mounted() {
    // Don't log or access this.candidates here - it causes Vue to cache undefined result

    // Если это карточка с конкретным типом (candidates или followup_questions)
    // и данные уже переданы через card prop, не загружаем task
    if ((this.cardType === 'candidates' && this.hasCandidates) ||
        (this.cardType === 'followup_questions' && (this.hasFollowups || this.hasNeedFields))) {
      console.log('[ToolInline] mounted() - using card data, skipping fetchTask')
      this.loading = false
      return
    }

    this.fetchTask()

    // Auto-refresh every 5 seconds
    // Но не обновляем, если это статичная карточка с данными из card prop
    if (this.cardType !== 'candidates' && this.cardType !== 'followup_questions') {
      this.refreshInterval = setInterval(() => {
        this.fetchTask()
      }, 5000)
    }
  },

  beforeUnmount() {
    if (this.refreshInterval) {
      clearInterval(this.refreshInterval)
    }
  },

  methods: {
    getCandidates() {
      if (this.cardType === 'candidates') {
        // Access candidates from card prop (Nova passes data here)
        return this.card?.candidates || this.$props.candidates || []
      }
      return this.identity?.candidates || []
    },

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

        // Stop polling if no task (research completed or cancelled)
        if (!this.task && this.refreshInterval) {
          console.log('[Identity Clarifier Inline] No task found, stopping polling')
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
        const candidates = this.getCandidates()
        const response = await Nova.request().post(
          `/api/exams/${this.computedExamId}/research/${this.computedTaskId}/clarify`,
          {
            clarification_type: 'select_candidate',
            selected_candidate: candidates[this.selectedIndex],
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

    async submitClarification() {
      this.submitting = true
      this.errorMessage = ''
      this.successMessage = ''

      try {
        const baseUrl = `/api/exams/${this.computedExamId}/research/${this.computedTaskId}/clarify`

        // Submit answers to questions if any
        if (this.hasFollowups && Object.keys(this.answerInputs).length > 0) {
          // Convert answerInputs object to array, preserving question order
          const answers = []
          for (let i = 0; i < this.card.followups.length; i++) {
            const answer = this.answerInputs[i]
            if (answer && answer.trim()) {
              answers.push(answer.trim())
            } else {
              // Empty answer - still include it to maintain order
              answers.push('')
            }
          }

          await Nova.request().post(baseUrl, {
            clarification_type: 'answer_questions',
            answers: answers,
          })
        }

        // Submit field values if any
        if (this.hasNeedFields && Object.keys(this.fieldInputs).length > 0) {
          // Filter out empty values
          const userInputUpdates = {}
          for (const [field, value] of Object.entries(this.fieldInputs)) {
            if (value && value.trim()) {
              userInputUpdates[field] = value.trim()
            }
          }

          if (Object.keys(userInputUpdates).length > 0) {
            await Nova.request().post(baseUrl, {
              clarification_type: 'provide_fields',
              user_input_updates: userInputUpdates,
            })
          }
        }

        this.successMessage = 'Information submitted successfully!'

        setTimeout(() => {
          location.reload()
        }, 2000)
      } catch (error) {
        console.error('Failed to submit clarification:', error)
        this.errorMessage = error.response?.data?.message || 'Failed to submit information.'
      } finally {
        this.submitting = false
      }
    },
  },
}
