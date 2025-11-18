<template>
  <Card class="px-6 py-4">
    <!-- Loading state -->
    <div v-if="loading" class="text-sm text-gray-500">
      Загружаю статус экзамена...
    </div>

    <!-- Error state -->
    <div v-else-if="error" class="text-sm text-red-600">
      {{ error }}
    </div>

    <!-- Main content based on viewMode -->
    <div v-else>
      <!-- Progress Bar Section (always visible at top) -->
      <div v-if="status.progress" class="mb-4 pb-4 border-b border-gray-200 dark:border-gray-700">
        <!-- Overall progress bar -->
        <div class="mb-3">
          <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2.5">
            <div
              class="bg-blue-500 h-2.5 rounded-full transition-all duration-300"
              :style="{ width: status.progress.overall_percentage + '%' }"
            ></div>
          </div>
          <div class="text-xs text-gray-600 dark:text-gray-400 mt-1 text-right">
            {{ status.progress.overall_percentage }}% готовности
          </div>
        </div>

        <!-- Stages -->
        <div class="flex justify-between items-start mb-3">
          <div
            v-for="(stage, index) in status.progress.stages"
            :key="stage.id"
            class="flex-1 text-center px-1"
          >
            <div class="text-2xl mb-1" :title="getStageStatusText(stage.status)">
              {{ getStageIcon(stage.status) }}
            </div>
            <div class="text-xs font-semibold text-gray-900 dark:text-gray-100">
              {{ stage.label }}
            </div>
            <div class="text-xs text-gray-600 dark:text-gray-400">
              {{ formatStageMetrics(stage) }}
            </div>
            <div v-if="stage.duration" class="text-xs text-gray-500 dark:text-gray-500 italic">
              {{ formatDuration(stage.duration) }}
            </div>
          </div>
        </div>

        <!-- Current task -->
        <div v-if="status.progress.current_task" class="text-sm text-blue-700 dark:text-blue-400 mb-2 flex items-center">
          <span class="animate-pulse mr-2">🔄</span>
          <span>
            {{ status.progress.current_task.message }}
            <span class="text-xs text-gray-600 dark:text-gray-400">
              (task #{{ status.progress.current_task.id }}, {{ status.progress.current_task.elapsed_seconds }}s)
            </span>
          </span>
        </div>

        <!-- Latest error (compact with expand) -->
        <div v-if="status.progress.latest_error" class="text-sm">
          <div class="flex items-start p-2 bg-red-50 dark:bg-red-900/20 rounded border border-red-200 dark:border-red-800">
            <span class="text-red-600 dark:text-red-400 mr-2">⚠️</span>
            <div class="flex-1">
              <div class="text-red-700 dark:text-red-300">
                <strong>Ошибка (task #{{ status.progress.latest_error.task_id }}):</strong>
                {{ status.progress.latest_error.message }}
              </div>
              <button
                @click="toggleErrorDetails"
                class="mt-1 text-xs text-red-600 dark:text-red-400 underline hover:no-underline"
              >
                {{ showErrorDetails ? 'Скрыть ▲' : 'Детали ▼' }}
              </button>
              <div v-if="showErrorDetails" class="mt-2 p-2 bg-red-100 dark:bg-red-900/40 rounded text-xs font-mono text-red-900 dark:text-red-200 whitespace-pre-wrap">
                {{ status.progress.latest_error.full_error }}
              </div>
            </div>
          </div>
        </div>
      </div>
      <!-- MODE 1: Missing Fields (highest priority) -->
      <div v-if="viewMode === 'missing_fields'" class="bg-white dark:bg-gray-800 rounded-lg p-4">
        <h3 class="text-base font-bold mb-3 text-yellow-700 dark:text-yellow-500">
          ⚠️ Не хватает обязательных полей
        </h3>
        <p class="text-sm mb-3">
          Для запуска исследования нужно заполнить критичные поля.
          <strong>Completion: {{ status.quick_check.completion_percentage }}%</strong>
        </p>
        <div class="space-y-1 mb-4">
          <div
            v-for="field in status.quick_check.missing_critical"
            :key="field"
            class="text-sm text-red-600"
          >
            ✖ {{ getFieldLabel(field) }} <span class="text-xs">[CRITICAL]</span>
          </div>
          <div
            v-for="field in status.quick_check.missing_recommended"
            :key="field"
            class="text-sm text-orange-600"
          >
            ✖ {{ getFieldLabel(field) }}
          </div>
        </div>
        <p class="text-xs text-gray-600">
          Заполните недостающие поля в форме редактирования экзамена.
        </p>
      </div>

      <!-- MODE 2: Pending Confirmation (candidates) -->
      <div v-else-if="viewMode === 'pending_confirmation'" class="bg-white dark:bg-gray-800 rounded-lg p-4">
        <h3 class="text-base font-bold mb-3 text-blue-700 dark:text-blue-400">
          🔍 Требуется подтверждение идентичности
        </h3>
        <p class="text-sm mb-3 text-gray-700 dark:text-gray-300">
          Система нашла {{ status.pending_task.candidates.length }} вариант(ов) экзамена.
          Выберите подходящий или отклоните все.
        </p>

        <!-- Candidates list with radio buttons -->
        <div class="space-y-2 mb-4">
          <label
            v-for="(candidate, index) in status.pending_task.candidates"
            :key="index"
            class="flex items-start border border-gray-300 dark:border-gray-600 rounded p-3 text-sm cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition"
            :class="{ 'bg-blue-50 dark:bg-blue-900 border-blue-500': selectedCandidateIndex === index }"
          >
            <input
              type="radio"
              name="candidate"
              :value="index"
              :checked="selectedCandidateIndex === index"
              class="mt-1 mr-3"
              @change="selectedCandidateIndex = index"
            />
            <div class="flex-1">
              <div class="font-semibold text-gray-900 dark:text-gray-100">{{ candidate.name || 'Unnamed' }}</div>
              <div class="text-xs text-gray-600 dark:text-gray-400">
                Provider: {{ candidate.provider || '—' }} |
                Family: {{ candidate.family || '—' }} |
                Score: {{ Math.round((candidate.score || 0) * 100) }}%
              </div>
            </div>
          </label>
        </div>

        <!-- Buttons -->
        <div class="flex gap-2">
          <button
            @click="confirmIdentity"
            :disabled="actionLoading || selectedCandidateIndex === null"
            :style="{
              padding: '0.5rem 1rem',
              backgroundColor: (actionLoading || selectedCandidateIndex === null) ? '#d1d5db' : '#1f2937',
              color: '#ffffff',
              borderRadius: '0.375rem',
              fontSize: '0.875rem',
              fontWeight: '500',
              cursor: (actionLoading || selectedCandidateIndex === null) ? 'not-allowed' : 'pointer',
              opacity: (actionLoading || selectedCandidateIndex === null) ? '0.5' : '1',
              border: 'none'
            }"
          >
            ✓ Confirm Selected
          </button>
          <button
            @click="rejectIdentity"
            :disabled="actionLoading"
            :style="{
              padding: '0.5rem 1rem',
              backgroundColor: actionLoading ? '#d1d5db' : '#4b5563',
              color: '#ffffff',
              borderRadius: '0.375rem',
              fontSize: '0.875rem',
              fontWeight: '500',
              cursor: actionLoading ? 'not-allowed' : 'pointer',
              opacity: actionLoading ? '0.5' : '1',
              border: 'none'
            }"
          >
            ↻ Reject & Re-run
          </button>
          <button
            @click="rejectAll"
            :disabled="actionLoading"
            :style="{
              padding: '0.5rem 1rem',
              backgroundColor: actionLoading ? '#d1d5db' : '#4b5563',
              color: '#ffffff',
              borderRadius: '0.375rem',
              fontSize: '0.875rem',
              fontWeight: '500',
              cursor: actionLoading ? 'not-allowed' : 'pointer',
              opacity: actionLoading ? '0.5' : '1',
              border: 'none'
            }"
          >
            ❌ Reject All
          </button>
        </div>

        <p v-if="actionLoading" class="text-xs text-gray-600 dark:text-gray-400 mt-2">
          Обрабатываю запрос...
        </p>
      </div>

      <!-- MODE 3: Pending Clarification (followups/need_fields) -->
      <div v-else-if="viewMode === 'pending_clarification'" class="bg-white dark:bg-gray-800 rounded-lg p-4">
        <h3 class="text-base font-bold mb-3 text-purple-700 dark:text-purple-400">
          ❓ Нужны уточнения
        </h3>
        <p class="text-sm mb-3">
          Системе нужна дополнительная информация для уверенного определения экзамена.
          Пожалуйста, ответьте на вопросы ниже.
        </p>

        <!-- Followup questions with input fields -->
        <div v-if="status.pending_task.followups && status.pending_task.followups.length > 0" class="mb-4 space-y-3">
          <div
            v-for="(question, index) in status.pending_task.followups"
            :key="index"
            class="border border-gray-300 dark:border-gray-600 rounded-lg p-3"
          >
            <label class="block text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2">
              {{ getQuestionText(question) }}
            </label>
            <div v-if="getQuestionWhy(question)" class="text-xs text-gray-600 dark:text-gray-400 mb-2 italic">
              {{ getQuestionWhy(question) }}
            </div>
            <textarea
              :value="clarificationAnswers[index]"
              @input="updateClarificationAnswer(index, $event.target.value)"
              rows="2"
              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-sm focus:ring-purple-500 focus:border-purple-500 dark:bg-gray-700 dark:text-gray-100"
              :placeholder="'Введите ваш ответ...'"
            ></textarea>
          </div>
        </div>

        <!-- Need fields with input fields -->
        <div v-if="status.pending_task.need_fields && status.pending_task.need_fields.length > 0" class="mb-4 space-y-3">
          <div class="font-semibold text-sm mb-2">Недостающие поля:</div>
          <div
            v-for="(field, index) in status.pending_task.need_fields"
            :key="'field-' + index"
            class="border border-gray-300 dark:border-gray-600 rounded-lg p-3"
          >
            <label class="block text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2">
              {{ field }}
            </label>
            <input
              :value="missingFieldsValues[field]"
              @input="updateMissingFieldValue(field, $event.target.value)"
              type="text"
              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-sm focus:ring-purple-500 focus:border-purple-500 dark:bg-gray-700 dark:text-gray-100"
              :placeholder="'Введите значение для ' + field"
            />
          </div>
        </div>

        <!-- Submit Button -->
        <div class="flex gap-2">
          <button
            @click="submitClarification"
            class="px-4 py-2 text-white rounded text-sm font-medium transition-colors"
            :style="{
              backgroundColor: (actionLoading || !canSubmitClarification) ? '#c084fc' : '#9333ea',
              cursor: (actionLoading || !canSubmitClarification) ? 'not-allowed' : 'pointer',
              opacity: (actionLoading || !canSubmitClarification) ? '0.6' : '1'
            }"
            :disabled="actionLoading || !canSubmitClarification"
            @mouseenter="!actionLoading && canSubmitClarification && ($event.target.style.backgroundColor = '#7e22ce')"
            @mouseleave="!actionLoading && canSubmitClarification && ($event.target.style.backgroundColor = '#9333ea')"
          >
            ✓ Submit Answers
          </button>
        </div>

        <p v-if="actionLoading" class="text-xs text-gray-600 dark:text-gray-400 mt-2">
          Отправляю ответы...
        </p>
      </div>

      <!-- MODE 4: Fields Changed -->
      <div v-else-if="viewMode === 'fields_changed'" class="bg-white dark:bg-gray-800 rounded-lg p-4">
        <h3 class="text-base font-bold mb-3 text-orange-700 dark:text-orange-400">
          🔄 Поля изменились после подтверждения
        </h3>
        <p class="text-sm mb-3">
          Следующие поля были изменены после подтверждения идентичности экзамена:
        </p>
        <ul class="list-disc list-inside space-y-1 mb-4">
          <li
            v-for="field in status.confirmed_identity.changed_fields"
            :key="field"
            class="text-sm text-orange-600"
          >
            {{ field }}
          </li>
        </ul>
        <p class="text-xs text-gray-600">
          Рекомендуется перезапустить Identity stage для проверки.
          Используйте Nova Action "Research Exam" для повторной проверки.
        </p>
      </div>

      <!-- MODE 5: Stalled Task -->
      <div v-else-if="viewMode === 'stalled'" class="bg-white dark:bg-gray-800 rounded-lg p-4">
        <h3 class="text-base font-bold mb-3 text-red-700 dark:text-red-400">
          ⏸️ Задача зависла
        </h3>
        <p class="text-sm mb-3">
          Задача #{{ status.stalled_task.id }} ({{ status.stalled_task.type }})
          не отправляла heartbeat {{ status.stalled_task.stalled_since }}.
        </p>
        <p class="text-xs text-gray-600 mb-3">
          Last heartbeat: {{ status.stalled_task.last_heartbeat || 'Never' }}
        </p>
        <p class="text-xs text-gray-600">
          Используйте Nova Action "Cancel Stalled Task" для отмены и перезапуска.
        </p>
      </div>

      <!-- MODE 6: Status (default) -->
      <div v-else class="bg-white dark:bg-gray-800 rounded-lg p-4">
        <h3 class="text-base font-bold mb-3 text-gray-700 dark:text-gray-300">
          📊 Exam Workflow Status
        </h3>
        <p class="text-sm mb-2">
          <span class="font-semibold">Research status:</span>
          <span :class="researchStatusClass" class="ml-2 px-2 py-0.5 text-xs rounded">
            {{ status.research_status || 'idle' }}
          </span>
        </p>

        <div v-if="status.latest_task" class="text-sm mb-2">
          <span class="font-semibold">Последняя задача:</span>
          {{ status.latest_task.type }} ({{ status.latest_task.status }})
        </div>

        <p class="text-xs text-gray-600">
          Обновится автоматически при изменении статуса.
        </p>
      </div>
    </div>
  </Card>
</template>

<script>
export default {
  props: ['card'],

  data() {
    return {
      loading: true,
      error: null,
      status: {
        research_status: null,
        latest_task: null,
        pending_task: null,
        quick_check: null,
        confirmed_identity: null,
        stalled_task: null,
        progress: null,
      },
      pollHandle: null,
      actionLoading: false,
      selectedCandidateIndex: null,
      clarificationAnswers: {}, // Index-based answers for followup questions
      missingFieldsValues: {}, // Field-based values for missing fields
      showErrorDetails: false, // For progress bar error expansion
    }
  },

  computed: {
    examId() {
      return this.card.examId
    },

    // Determine viewMode based on priority: pending_* > missing > fields_changed > stalled > status
    viewMode() {
      // 1. Pending confirmation (highest priority)
      if (this.status.pending_task && this.status.pending_task.status === 'pending_confirmation') {
        return 'pending_confirmation'
      }

      // 2. Pending clarification
      if (this.status.pending_task && this.status.pending_task.status === 'pending_clarification') {
        return 'pending_clarification'
      }

      // 3. Missing fields
      if (this.status.quick_check && !this.status.quick_check.ready) {
        return 'missing_fields'
      }

      // 4. Fields changed
      if (this.status.confirmed_identity && this.status.confirmed_identity.has_fields_changed) {
        return 'fields_changed'
      }

      // 5. Stalled task
      if (this.status.stalled_task) {
        return 'stalled'
      }

      // 6. Default status
      return 'status'
    },

    researchStatusClass() {
      const status = this.status.research_status || 'idle'
      const classes = {
        idle: 'bg-gray-200 text-gray-700',
        queued: 'bg-blue-100 text-blue-700',
        running: 'bg-yellow-100 text-yellow-800',
        running_overview: 'bg-yellow-100 text-yellow-800',
        running_phase_a: 'bg-yellow-100 text-yellow-800',
        running_phase_b: 'bg-yellow-100 text-yellow-800',
        running_categories: 'bg-yellow-100 text-yellow-800',
        running_examples: 'bg-yellow-100 text-yellow-800',
        running_rubrics: 'bg-yellow-100 text-yellow-800',
        completed: 'bg-green-100 text-green-800',
        failed: 'bg-red-100 text-red-800',
        need_info: 'bg-orange-100 text-orange-800',
        pending_clarification: 'bg-purple-100 text-purple-800',
      }
      return classes[status] || 'bg-gray-100 text-gray-700'
    },

    canSubmitClarification() {
      const hasFollowups = this.status.pending_task?.followups?.length > 0
      const hasNeedFields = this.status.pending_task?.need_fields?.length > 0

      if (!hasFollowups && !hasNeedFields) {
        console.log('[canSubmitClarification] No followups or fields')
        return false
      }

      // Check if at least one answer is provided
      const answerValues = Object.values(this.clarificationAnswers)
      const fieldValues = Object.values(this.missingFieldsValues)

      const hasAnswers = answerValues.some(a => a && a.trim())
      const hasFieldValues = fieldValues.some(v => v && v.trim())

      console.log('[canSubmitClarification]', {
        clarificationAnswers: this.clarificationAnswers,
        missingFieldsValues: this.missingFieldsValues,
        answerValues,
        fieldValues,
        hasAnswers,
        hasFieldValues,
        result: hasAnswers || hasFieldValues
      })

      return hasAnswers || hasFieldValues
    },
  },

  mounted() {
    this.fetchStatus()
    this.pollHandle = setInterval(this.fetchStatus, 5000) // Poll every 5 seconds
  },

  watch: {
    'status.pending_task.candidates': {
      handler(newCandidates, oldCandidates) {
        // Only reset selection if candidates actually changed (not just re-fetched)
        if (JSON.stringify(newCandidates) !== JSON.stringify(oldCandidates)) {
          // Auto-select first candidate if only one exists
          if (newCandidates && newCandidates.length === 1) {
            this.selectedCandidateIndex = 0
          } else {
            this.selectedCandidateIndex = null
          }
        }
      },
      immediate: true,
    },

    'status.pending_task.followups': {
      handler(newFollowups, oldFollowups) {
        // Only reinitialize if followups actually changed (not just re-fetched)
        if (JSON.stringify(newFollowups) !== JSON.stringify(oldFollowups)) {
          // Initialize clarificationAnswers with empty strings for each question
          // to ensure Vue reactivity tracks changes
          if (newFollowups && newFollowups.length > 0) {
            const answers = {}
            newFollowups.forEach((_, index) => {
              answers[index] = this.clarificationAnswers[index] || ''
            })
            this.clarificationAnswers = answers
          } else {
            this.clarificationAnswers = {}
          }
        }
      },
      immediate: true,
    },

    'status.pending_task.need_fields': {
      handler(newFields, oldFields) {
        // Only reinitialize if fields actually changed (not just re-fetched)
        if (JSON.stringify(newFields) !== JSON.stringify(oldFields)) {
          // Initialize missingFieldsValues with empty strings for each field
          // to ensure Vue reactivity tracks changes
          if (newFields && newFields.length > 0) {
            const values = {}
            newFields.forEach(field => {
              values[field] = this.missingFieldsValues[field] || ''
            })
            this.missingFieldsValues = values
          } else {
            this.missingFieldsValues = {}
          }
        }
      },
      immediate: true,
    },
  },

  beforeUnmount() {
    if (this.pollHandle) {
      clearInterval(this.pollHandle)
    }
  },

  methods: {
    async fetchStatus() {
      if (!this.examId) return

      this.error = null

      try {
        const { data } = await Nova.request().get(
          `/api/nova-vendor/exam-status/${this.examId}`
        )

        this.status = data

        // Initialize reactive objects for followups and fields if first time loading
        if (this.status.pending_task) {
          // Initialize clarificationAnswers if not already initialized
          if (this.status.pending_task.followups && this.status.pending_task.followups.length > 0) {
            if (Object.keys(this.clarificationAnswers).length === 0) {
              const answers = {}
              this.status.pending_task.followups.forEach((_, index) => {
                answers[index] = ''
              })
              this.clarificationAnswers = answers
            }
          }

          // Initialize missingFieldsValues if not already initialized
          if (this.status.pending_task.need_fields && this.status.pending_task.need_fields.length > 0) {
            if (Object.keys(this.missingFieldsValues).length === 0) {
              const values = {}
              this.status.pending_task.need_fields.forEach(field => {
                values[field] = ''
              })
              this.missingFieldsValues = values
            }
          }
        }
      } catch (e) {
        console.error('[ExamWorkflowHub] Fetch status error:', e)
        this.error = 'Не удалось загрузить статус экзамена'
      } finally {
        this.loading = false
      }
    },

    updateClarificationAnswer(index, value) {
      console.log('[updateClarificationAnswer]', index, value)
      // Create new object to trigger reactivity
      this.clarificationAnswers = {
        ...this.clarificationAnswers,
        [index]: value
      }
      console.log('[updateClarificationAnswer] Updated:', this.clarificationAnswers)
    },

    updateMissingFieldValue(field, value) {
      console.log('[updateMissingFieldValue]', field, value)
      // Create new object to trigger reactivity
      this.missingFieldsValues = {
        ...this.missingFieldsValues,
        [field]: value
      }
      console.log('[updateMissingFieldValue] Updated:', this.missingFieldsValues)
    },

    getFieldLabel(field) {
      const labels = {
        title: 'Exam Title',
        language_of_test: 'Language of Test',
        level: 'Level',
        has_document_or_input: 'Document or User Input',
        exam_family: 'Exam Family',
        exam_provider: 'Exam Provider',
        description: 'Exam Description',
      }
      return labels[field] || field
    },

    async confirmIdentity() {
      if (!this.status.pending_task || this.selectedCandidateIndex === null) return

      const selectedCandidate = this.status.pending_task.candidates[this.selectedCandidateIndex]

      this.actionLoading = true
      try {
        await Nova.request().post(
          `/api/exams/${this.examId}/research/${this.status.pending_task.id}/confirm-identity`,
          {
            confirmed: true,
            candidate_index: this.selectedCandidateIndex,
            notes: `Confirmed "${selectedCandidate.name}" via ExamWorkflowHub`,
          }
        )

        Nova.$toasted.show('Identity confirmed! Pipeline will continue.', { type: 'success' })
        await this.fetchStatus() // Refresh status
      } catch (e) {
        console.error('[ExamWorkflowHub] Confirm identity error:', e)
        Nova.$toasted.show('Failed to confirm identity', { type: 'error' })
      } finally {
        this.actionLoading = false
      }
    },

    async rejectIdentity() {
      if (!this.status.pending_task) return

      this.actionLoading = true
      try {
        await Nova.request().post(
          `/api/exams/${this.examId}/research/${this.status.pending_task.id}/confirm-identity`,
          {
            confirmed: false,
            notes: 'Rejected via ExamWorkflowHub - re-run identity',
          }
        )

        Nova.$toasted.show('Identity rejected. Re-running verification...', { type: 'info' })
        await this.fetchStatus() // Refresh status
      } catch (e) {
        console.error('[ExamWorkflowHub] Reject identity error:', e)
        Nova.$toasted.show('Failed to reject identity', { type: 'error' })
      } finally {
        this.actionLoading = false
      }
    },

    async rejectAll() {
      if (!this.status.pending_task) return

      const notes = prompt('Optional: Explain why none of the variants match your exam:')

      this.actionLoading = true
      try {
        await Nova.request().post(
          `/api/exams/${this.examId}/research/${this.status.pending_task.id}/clarify`,
          {
            clarification_type: 'reject_all',
            notes: notes || '',
          }
        )

        Nova.$toasted.show('All variants rejected. Research cancelled.', { type: 'warning' })
        await this.fetchStatus() // Refresh status
      } catch (e) {
        console.error('[ExamWorkflowHub] Reject all error:', e)
        Nova.$toasted.show('Failed to reject all variants', { type: 'error' })
      } finally {
        this.actionLoading = false
      }
    },

    getQuestionText(question) {
      if (typeof question === 'string') {
        return question
      }
      if (typeof question === 'object' && question !== null) {
        return question.q || question.question || 'Unknown question'
      }
      return 'Unknown question'
    },

    getQuestionWhy(question) {
      if (typeof question === 'object' && question !== null) {
        return question.why || question.reason || null
      }
      return null
    },

    async submitClarification() {
      if (!this.status.pending_task) return

      this.actionLoading = true
      try {
        const hasFollowups = this.status.pending_task.followups && this.status.pending_task.followups.length > 0
        const hasNeedFields = this.status.pending_task.need_fields && this.status.pending_task.need_fields.length > 0

        // Determine which clarification type to use
        let payload = {}

        if (hasFollowups && Object.values(this.clarificationAnswers).some(a => a && a.trim())) {
          // Send answer_questions if there are answered followup questions
          const answers = []
          this.status.pending_task.followups.forEach((question, index) => {
            const answer = this.clarificationAnswers[index]
            answers.push(answer && answer.trim() ? answer.trim() : '')
          })

          payload = {
            clarification_type: 'answer_questions',
            answers: answers,
          }

          await Nova.request().post(
            `/api/exams/${this.examId}/research/${this.status.pending_task.id}/clarify`,
            payload
          )
        }

        if (hasNeedFields && Object.values(this.missingFieldsValues).some(v => v && v.trim())) {
          // Send provide_fields if there are filled missing fields
          const userInputUpdates = {}
          this.status.pending_task.need_fields.forEach(field => {
            const value = this.missingFieldsValues[field]
            if (value && value.trim()) {
              userInputUpdates[field] = value.trim()
            }
          })

          payload = {
            clarification_type: 'provide_fields',
            user_input_updates: userInputUpdates,
          }

          await Nova.request().post(
            `/api/exams/${this.examId}/research/${this.status.pending_task.id}/clarify`,
            payload
          )
        }

        Nova.$toasted.show('Answers submitted successfully! Continuing verification...', { type: 'success' })

        // Reset forms
        this.clarificationAnswers = {}
        this.missingFieldsValues = {}

        await this.fetchStatus() // Refresh status
      } catch (e) {
        console.error('[ExamWorkflowHub] Submit clarification error:', e)
        const errorMsg = e.response?.data?.message || 'Failed to submit answers'
        Nova.$toasted.show(errorMsg, { type: 'error' })
      } finally {
        this.actionLoading = false
      }
    },

    // ========== Progress Bar Methods ==========

    getStageIcon(status) {
      const icons = {
        completed: '✓',
        in_progress: '●',
        pending: '○',
        error: '✗',
      }
      return icons[status] || '○'
    },

    getStageStatusText(status) {
      const texts = {
        completed: 'Завершено',
        in_progress: 'В процессе',
        pending: 'Не начат',
        error: 'Ошибка',
      }
      return texts[status] || 'Неизвестно'
    },

    formatStageMetrics(stage) {
      // Форматирование метрик для каждого этапа
      if (stage.id === 'data') {
        return stage.metrics.completion || '0%'
      }
      if (stage.id === 'identity') {
        return stage.metrics.confirmed ? 'Confirmed' : 'Нет'
      }
      if (stage.id === 'structure') {
        const count = stage.metrics.categories || 0
        return count > 0 ? `${count} кат.` : 'Нет'
      }
      if (stage.id === 'examples') {
        const count = stage.metrics.examples || 0
        return count > 0 ? `${count} прим.` : 'Нет'
      }
      if (stage.id === 'questions') {
        const count = stage.metrics.questions || 0
        return count > 0 ? `${count} вопр.` : 'Нет'
      }
      return ''
    },

    formatDuration(seconds) {
      if (!seconds || seconds === 0) return ''
      const minutes = Math.floor(seconds / 60)
      const secs = seconds % 60
      if (minutes > 0) {
        return `${minutes} мин ${secs} с`
      }
      return `${secs} с`
    },

    toggleErrorDetails() {
      this.showErrorDetails = !this.showErrorDetails
    },
  },
}

</script>
