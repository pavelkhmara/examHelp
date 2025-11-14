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
      <!-- MODE 1: Missing Fields (highest priority) -->
      <div v-if="viewMode === 'missing_fields'">
        <h3 class="text-base font-bold mb-3 text-yellow-700">
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
      <div v-else-if="viewMode === 'pending_confirmation'">
        <h3 class="text-base font-bold mb-3 text-blue-700">
          🔍 Требуется подтверждение идентичности
        </h3>
        <p class="text-sm mb-3">
          Система нашла {{ status.pending_task.candidates.length }} вариант(ов) экзамена.
          Выберите подходящий или отклоните все.
        </p>

        <!-- Candidates list -->
        <div class="space-y-2 mb-4">
          <div
            v-for="(candidate, index) in status.pending_task.candidates"
            :key="index"
            class="border border-gray-300 rounded p-2 text-sm"
          >
            <div class="font-semibold">{{ candidate.canonical?.name || 'Unnamed' }}</div>
            <div class="text-xs text-gray-600">
              Provider: {{ candidate.canonical?.provider || '—' }} |
              Variant: {{ candidate.canonical?.variant || '—' }} |
              Confidence: {{ Math.round((candidate.confidence || 0) * 100) }}%
            </div>
          </div>
        </div>

        <!-- Buttons -->
        <div class="flex gap-2">
          <button
            @click="confirmIdentity"
            class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 text-sm"
            :disabled="actionLoading"
          >
            ✓ Confirm Identity
          </button>
          <button
            @click="rejectIdentity"
            class="px-4 py-2 bg-yellow-600 text-white rounded hover:bg-yellow-700 text-sm"
            :disabled="actionLoading"
          >
            ↻ Reject & Re-run
          </button>
          <button
            @click="rejectAll"
            class="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700 text-sm"
            :disabled="actionLoading"
          >
            ❌ Reject All
          </button>
        </div>
        <p v-if="actionLoading" class="text-xs text-gray-600 mt-2">
          Обрабатываю запрос...
        </p>
      </div>

      <!-- MODE 3: Pending Clarification (followups/need_fields) -->
      <div v-else-if="viewMode === 'pending_clarification'">
        <h3 class="text-base font-bold mb-3 text-purple-700">
          ❓ Нужны уточнения
        </h3>
        <p class="text-sm mb-3">
          Системе нужна дополнительная информация для уверенного определения экзамена.
        </p>

        <!-- Followup questions -->
        <div v-if="status.pending_task.followups && status.pending_task.followups.length > 0" class="mb-3">
          <div class="font-semibold text-sm mb-2">Вопросы:</div>
          <ol class="list-decimal list-inside space-y-1">
            <li
              v-for="(question, index) in status.pending_task.followups"
              :key="index"
              class="text-sm text-gray-700"
            >
              {{ question }}
            </li>
          </ol>
        </div>

        <!-- Need fields -->
        <div v-if="status.pending_task.need_fields && status.pending_task.need_fields.length > 0" class="mb-3">
          <div class="font-semibold text-sm mb-2">Недостающие поля:</div>
          <ul class="list-disc list-inside space-y-1">
            <li
              v-for="(field, index) in status.pending_task.need_fields"
              :key="index"
              class="text-sm text-gray-700"
            >
              {{ field }}
            </li>
          </ul>
        </div>

        <p class="text-xs text-gray-600 mb-3">
          Используйте Nova Action "Provide Answers to AI Questions" чтобы ответить на вопросы.
        </p>

        <!-- Button -->
        <div class="flex gap-2">
          <button
            @click="openProvideAnswers"
            class="px-4 py-2 bg-purple-600 text-white rounded hover:bg-purple-700 text-sm"
          >
            📝 Provide Answers
          </button>
        </div>
      </div>

      <!-- MODE 4: Fields Changed -->
      <div v-else-if="viewMode === 'fields_changed'">
        <h3 class="text-base font-bold mb-3 text-orange-700">
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
      <div v-else-if="viewMode === 'stalled'">
        <h3 class="text-base font-bold mb-3 text-red-700">
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
      <div v-else>
        <h3 class="text-base font-bold mb-3 text-gray-700">
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
      },
      pollHandle: null,
      actionLoading: false,
    }
  },

  computed: {
    examId() {
      return this.card.examId
    },

    // Determine viewMode based on priority: missing > pending_* > fields_changed > stalled > status
    viewMode() {
      // 1. Missing fields (highest priority)
      if (this.status.quick_check && !this.status.quick_check.ready) {
        return 'missing_fields'
      }

      // 2. Pending confirmation
      if (this.status.pending_task && this.status.pending_task.status === 'pending_confirmation') {
        return 'pending_confirmation'
      }

      // 3. Pending clarification
      if (this.status.pending_task && this.status.pending_task.status === 'pending_clarification') {
        return 'pending_clarification'
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
        running_overview: 'bg-yellow-100 text-yellow-800',
        completed: 'bg-green-100 text-green-800',
        failed: 'bg-red-100 text-red-800',
        need_info: 'bg-orange-100 text-orange-800',
      }
      return classes[status] || 'bg-gray-100 text-gray-700'
    },
  },

  mounted() {
    this.fetchStatus()
    this.pollHandle = setInterval(this.fetchStatus, 5000) // Poll every 5 seconds
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
          `/nova-vendor/exam-status/${this.examId}`
        )

        this.status = data
      } catch (e) {
        console.error('[ExamWorkflowHub] Fetch status error:', e)
        this.error = 'Не удалось загрузить статус экзамена'
      } finally {
        this.loading = false
      }
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
      if (!this.status.pending_task) return

      this.actionLoading = true
      try {
        await Nova.request().post(
          `/exams/${this.examId}/research/${this.status.pending_task.id}/confirm-identity`,
          {
            confirmed: true,
            notes: 'Confirmed via ExamWorkflowHub',
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
          `/exams/${this.examId}/research/${this.status.pending_task.id}/confirm-identity`,
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
          `/exams/${this.examId}/research/${this.status.pending_task.id}/clarify`,
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

    openProvideAnswers() {
      Nova.$toasted.show(
        'Please use Nova Action "Provide Answers to AI Questions" from the Actions dropdown.',
        { type: 'info', duration: 5000 }
      )
    },
  },
}
</script>
