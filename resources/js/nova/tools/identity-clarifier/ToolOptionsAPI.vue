<template>
  <div class="identity-clarifier-wrapper" :key="'clarifier-' + updateKey" style="min-height: 100px; position: relative;">
    <!-- DEBUG PANEL -->
    <div style="position: absolute; top: 0; right: 0; background: #f0f0f0; padding: 8px; font-size: 10px; z-index: 9999; border: 1px solid #ccc;">
      <strong>DEBUG (Options API):</strong><br>
      reactivity: {{ reactivityTest }}<br>
      updateKey: {{ updateKey }}<br>
      loading: {{ loading }}<br>
      needsClarification: {{ needsClarification }}<br>
      hasCandidates: {{ hasCandidates }}<br>
      taskId: {{ taskId }}<br>
      examId: {{ computedExamId }}
    </div>

    <div v-if="loading" class="flex justify-center items-center p-8" style="min-height: 100px; background: rgba(0,0,0,0.02);">
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
      <p class="text-gray-500 dark:text-gray-500 text-sm mt-2">
        Identity verification is complete or not yet started
      </p>
    </div>

    <div v-else class="p-6 bg-white dark:bg-gray-800 rounded-lg shadow-sm">
      <!-- Show candidates selector if we have candidates -->
      <candidate-selector
        v-if="hasCandidates && taskId && computedExamId"
        :candidates="candidates"
        :task-id="taskId"
        :exam-id="computedExamId"
        @selected="handleCandidateSelected"
      />

      <!-- Debug info if data is missing -->
      <div v-else-if="hasCandidates && (!taskId || !computedExamId)" class="text-center p-6 bg-yellow-50 dark:bg-yellow-900 rounded">
        <p class="text-yellow-800 dark:text-yellow-200 mb-2">
          ⚠️ Data loading issue
        </p>
        <p class="text-sm text-gray-600 dark:text-gray-400">
          hasCandidates: {{ hasCandidates }}, taskId: {{ taskId }}, examId: {{ computedExamId }}
        </p>
      </div>

      <!-- Placeholder for future: questions form -->
      <div v-else-if="hasFollowups" class="text-center p-6">
        <p class="text-gray-600 dark:text-gray-400 mb-4">
          📋 The exam requires additional information
        </p>
        <div class="text-sm text-gray-500 dark:text-gray-500 bg-gray-100 dark:bg-gray-700 p-4 rounded">
          <p class="font-semibold mb-2">Questions to answer:</p>
          <ul class="list-disc list-inside text-left">
            <li v-for="(question, index) in followups" :key="index">
              {{ typeof question === 'string' ? question : question.q || question }}
            </li>
          </ul>
          <p class="mt-3 text-xs italic">
            (Answer form coming soon - use "Provide Answers" action for now)
          </p>
        </div>
      </div>

      <!-- Placeholder for future: fields form -->
      <div v-else-if="hasNeedFields" class="text-center p-6">
        <p class="text-gray-600 dark:text-gray-400 mb-4">
          📝 Missing required information
        </p>
        <div class="text-sm text-gray-500 dark:text-gray-500 bg-gray-100 dark:bg-gray-700 p-4 rounded">
          <p class="font-semibold mb-2">Required fields:</p>
          <ul class="list-disc list-inside text-left">
            <li v-for="field in needFields" :key="field">
              {{ field }}
            </li>
          </ul>
          <p class="mt-3 text-xs italic">
            (Input form coming soon - use "Provide Answers" action for now)
          </p>
        </div>
      </div>

      <!-- Unknown state -->
      <div v-else class="text-center p-6">
        <p class="text-yellow-600 dark:text-yellow-400">
          ⚠ Task is pending but no clear action available
        </p>
        <p class="text-sm text-gray-500 dark:text-gray-500 mt-2">
          Please check the task status manually
        </p>
      </div>
    </div>
  </div>
</template>

<script>
// CandidateSelector is registered globally in tool.js
// No need to import or register locally

export default {
  name: 'IdentityClarifier',

  // Don't register components locally - they're global
  // components: {},

  props: {
    resourceName: {
      type: String,
      required: false,
    },
    resourceId: {
      type: [String, Number],
      required: false,
    },
    examId: {
      type: [String, Number],
      required: false,
    },
  },

  data() {
    return {
      loading: true,
      task: null,
      identity: null,
      refreshInterval: null,
      reactivityTest: 0,
      updateKey: 0, // Force re-render key
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

    hasFollowups() {
      return this.identity?.followups?.length > 0
    },

    hasNeedFields() {
      return this.identity?.need_fields?.length > 0
    },

    candidates() {
      return this.identity?.candidates || []
    },

    followups() {
      return this.identity?.followups || []
    },

    needFields() {
      return this.identity?.need_fields || []
    },

    taskId() {
      return this.task?.id
    },

    computedExamId() {
      return this.examId || this.resourceId
    },
  },

  mounted() {
    console.log('[Identity Clarifier Options API] Component mounted', {
      props: this.$props,
      resourceId: this.resourceId,
      examId: this.examId,
      isReactive: this.$data && typeof this.$data === 'object',
    })

    // Start reactivity test - IMMEDIATELY increment to test
    this.reactivityTest = 1
    this.$forceUpdate()

    setInterval(() => {
      this.reactivityTest++
      console.log('[Identity Clarifier Options API] Reactivity test incremented to:', this.reactivityTest)
    }, 1000)

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

      console.log('[Identity Clarifier Options API] fetchTask called', {
        resourceId: this.resourceId,
        examId: this.examId,
        actualExamId: actualExamId,
      })

      if (!actualExamId) {
        console.error('[Identity Clarifier Options API] No exam ID available')
        this.loading = false
        return
      }

      try {
        const url = `/api/exams/${actualExamId}/pending-task`
        console.log('[Identity Clarifier Options API] Fetching:', url)

        const response = await Nova.request().get(url)

        console.log('[Identity Clarifier Options API] Response received:', response.data)

        this.task = response.data.task
        this.identity = this.task?.result?.identity || null

        // Increment update key to force re-render
        this.updateKey++

        console.log('[Identity Clarifier Options API] Data set:', {
          task: this.task,
          identity: this.identity,
          needsClarification: this.needsClarification,
          hasCandidates: this.hasCandidates,
          hasFollowups: this.hasFollowups,
          hasNeedFields: this.hasNeedFields,
          candidates: this.candidates,
          taskId: this.taskId,
          examId: this.computedExamId,
        })

        // If task status changed to non-pending, reload the page to update Nova UI
        if (this.task && !this.needsClarification) {
          // Task completed, stop auto-refresh
          if (this.refreshInterval) {
            clearInterval(this.refreshInterval)
            this.refreshInterval = null
          }
        }
      } catch (error) {
        console.error('[Identity Clarifier Options API] Failed to fetch task:', error)
      } finally {
        this.loading = false
        console.log('[Identity Clarifier Options API] Loading set to false')

        // Force Vue to update the DOM
        this.$forceUpdate()

        console.log('[Identity Clarifier Options API] Forced update. Current state:', {
          loading: this.loading,
          needsClarification: this.needsClarification,
          hasCandidates: this.hasCandidates,
        })
      }
    },

    handleCandidateSelected() {
      Nova.success('Exam variant selected! Pipeline continues.')

      // Reload page after 2 seconds to show updated status
      setTimeout(() => {
        location.reload()
      }, 2000)
    },
  },
}
</script>
