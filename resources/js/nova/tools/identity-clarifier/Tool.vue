<template>
  <!-- Hide component entirely when no task (research completed or not started) -->
  <div v-if="task || loading" class="identity-clarifier-wrapper" style="min-height: 100px; position: relative;">
    <div v-if="loading" class="flex justify-center items-center p-8" style="min-height: 100px; background: rgba(0,0,0,0.02);">
      <svg class="animate-spin h-8 w-8 text-primary-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
      </svg>
    </div>

  <div v-else class="p-6 bg-white dark:bg-gray-800 rounded-lg shadow-sm">
    <!-- Show candidates selector if we have candidates -->
    <CandidateSelector
      v-if="hasCandidates && taskId && examId"
      :candidates="candidates"
      :task-id="taskId"
      :exam-id="examId"
      @selected="handleCandidateSelected"
    />

    <!-- Debug info if data is missing -->
    <div v-else-if="hasCandidates && (!taskId || !examId)" class="text-center p-6 bg-yellow-50 dark:bg-yellow-900 rounded">
      <p class="text-yellow-800 dark:text-yellow-200 mb-2">
        ⚠️ Data loading issue
      </p>
      <p class="text-sm text-gray-600 dark:text-gray-400">
        hasCandidates: {{ hasCandidates }}, taskId: {{ taskId }}, examId: {{ examId }}
      </p>
    </div>

    <!-- Followup questions and/or Need fields form -->
    <div v-else-if="hasFollowups || hasNeedFields">
      <div class="mb-4">
        <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100 mb-2">
          📋 Additional Information Required
        </h3>
        <p class="text-gray-600 dark:text-gray-400">
          Please provide the following information to continue:
        </p>
      </div>

      <!-- Questions Form -->
      <div v-if="hasFollowups && followups.length > 0" class="mb-6">
        <p class="font-semibold mb-3 text-gray-900 dark:text-gray-100">Questions to answer:</p>
        <div v-for="(question, index) in followups" :key="'q-' + index" class="mb-4">
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
      <div v-if="hasNeedFields && needFields.length > 0" class="mb-6">
        <p class="font-semibold mb-3 text-gray-900 dark:text-gray-100">Required fields:</p>
        <div v-for="field in needFields" :key="'f-' + field" class="mb-4">
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

<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
import CandidateSelector from './components/CandidateSelector.vue'

const props = defineProps({
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
  cardType: {
    type: String,
    required: false,
  },
  taskId: {
    type: Number,
    required: false,
  },
  candidates: {
    type: Array,
    required: false,
  },
  followups: {
    type: Array,
    required: false,
  },
  needFields: {
    type: Array,
    required: false,
  },
})

const loading = ref(true)
const task = ref(null)
const identity = ref(null)
let refreshInterval = null

// Form inputs for followup questions and fields
const answerInputs = ref({})
const fieldInputs = ref({})
const submitting = ref(false)
const successMessage = ref('')
const errorMessage = ref('')

const needsClarification = computed(() => {
  return (
    task.value?.status === 'pending_confirmation' ||
    task.value?.status === 'pending_clarification'
  )
})

const hasCandidates = computed(() => {
  // Если cardType === 'candidates', используем props
  if (props.cardType === 'candidates') {
    return props.candidates?.length > 0
  }
  // Иначе проверяем task result
  return identity.value?.candidates?.length > 0
})

const hasFollowups = computed(() => {
  // Если cardType === 'followup_questions', используем props
  if (props.cardType === 'followup_questions') {
    return props.followups?.length > 0
  }
  // Иначе проверяем task result
  return identity.value?.followups?.length > 0
})

const hasNeedFields = computed(() => {
  // Если cardType === 'followup_questions', используем props
  if (props.cardType === 'followup_questions') {
    return props.needFields?.length > 0
  }
  // Иначе проверяем task result
  return identity.value?.need_fields?.length > 0
})

const candidates = computed(() => {
  // Если cardType === 'candidates', используем props
  if (props.cardType === 'candidates') {
    return props.candidates || []
  }
  // Иначе берём из task result
  return identity.value?.candidates || []
})

const followups = computed(() => {
  // Если cardType === 'followup_questions', используем props
  if (props.cardType === 'followup_questions') {
    return props.followups || []
  }
  // Иначе берём из task result
  return identity.value?.followups || []
})

const needFields = computed(() => {
  // Если cardType === 'followup_questions', используем props
  if (props.cardType === 'followup_questions') {
    return props.needFields || []
  }
  // Иначе берём из task result
  return identity.value?.need_fields || []
})

const taskId = computed(() => {
  // Если передан taskId через props, используем его
  if (props.taskId) {
    return props.taskId
  }
  // Иначе берём из task
  return task.value?.id
})

const examId = computed(() => {
  return props.examId || props.resourceId
})

const fetchTask = async () => {
  const actualExamId = examId.value

  console.log('[Identity Clarifier] fetchTask called', {
    resourceId: props.resourceId,
    examId: props.examId,
    actualExamId: actualExamId,
    cardType: props.cardType,
  })

  // Если это карточка с конкретным типом (candidates, followup_questions)
  // и данные уже переданы через props, не загружаем task
  if ((props.cardType === 'candidates' && props.candidates?.length > 0) ||
      (props.cardType === 'followup_questions' && (props.followups?.length > 0 || props.needFields?.length > 0))) {
    console.log('[Identity Clarifier] Using props data, skipping fetch')
    loading.value = false
    return
  }

  if (!actualExamId) {
    console.error('[Identity Clarifier] No exam ID available')
    loading.value = false
    return
  }

  try {
    const url = `/api/exams/${actualExamId}/pending-task`
    console.log('[Identity Clarifier] Fetching:', url)

    const response = await Nova.request().get(url)

    console.log('[Identity Clarifier] Response received:', response.data)

    task.value = response.data.task
    identity.value = task.value?.result?.identity || null

    // Force Vue to update
    await nextTick()

    console.log('[Identity Clarifier] Data set:', {
      task: task.value,
      needsClarification: needsClarification.value,
    })

    // Stop polling if no task (research completed or cancelled)
    if (!task.value) {
      console.log('[Identity Clarifier] No task found, stopping polling')
      if (refreshInterval) {
        clearInterval(refreshInterval)
        refreshInterval = null
      }
    }
  } catch (error) {
    console.error('[Identity Clarifier] Failed to fetch task:', error)
    console.error('[Identity Clarifier] Error details:', {
      message: error.message,
      response: error.response?.data,
      status: error.response?.status,
    })
  } finally {
    loading.value = false
    console.log('[Identity Clarifier] Loading set to false')
  }
}

const handleCandidateSelected = () => {
  Nova.success('Exam variant selected! Pipeline continues.')

  // Reload page after 2 seconds to show updated status
  setTimeout(() => {
    location.reload()
  }, 2000)
}

const submitClarification = async () => {
  submitting.value = true
  errorMessage.value = ''
  successMessage.value = ''

  try {
    const baseUrl = `/api/exams/${examId.value}/research/${taskId.value}/clarify`

    // Submit answers to questions if any
    if (hasFollowups.value && Object.keys(answerInputs.value).length > 0) {
      // Convert answerInputs object to array, preserving question order
      const answers = []
      for (let i = 0; i < followups.value.length; i++) {
        const answer = answerInputs.value[i]
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
    if (hasNeedFields.value && Object.keys(fieldInputs.value).length > 0) {
      // Filter out empty values
      const userInputUpdates = {}
      for (const [field, value] of Object.entries(fieldInputs.value)) {
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

    successMessage.value = 'Information submitted successfully!'

    setTimeout(() => {
      location.reload()
    }, 2000)
  } catch (error) {
    console.error('Failed to submit clarification:', error)
    errorMessage.value = error.response?.data?.message || 'Failed to submit information.'
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  console.log('[Identity Clarifier] Component mounted', {
    initialLoading: loading.value,
    props: props,
    allProps: Object.keys(props),
  })

  // Log all attributes passed to the component
  console.log('[Identity Clarifier] Component $attrs:', arguments)

  fetchTask()

  // Auto-refresh every 5 seconds
  // Но не обновляем, если это статичная карточка с данными из props
  if (props.cardType !== 'candidates' && props.cardType !== 'followup_questions') {
    refreshInterval = setInterval(() => {
      fetchTask()
    }, 5000)
  }
})

onUnmounted(() => {
  if (refreshInterval) {
    clearInterval(refreshInterval)
  }
})
</script>
