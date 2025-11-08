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
})

const loading = ref(true)
const task = ref(null)
const identity = ref(null)
let refreshInterval = null

const needsClarification = computed(() => {
  return (
    task.value?.status === 'pending_confirmation' ||
    task.value?.status === 'pending_clarification'
  )
})

const hasCandidates = computed(() => {
  return identity.value?.candidates?.length > 0
})

const hasFollowups = computed(() => {
  return identity.value?.followups?.length > 0
})

const hasNeedFields = computed(() => {
  return identity.value?.need_fields?.length > 0
})

const candidates = computed(() => {
  return identity.value?.candidates || []
})

const followups = computed(() => {
  return identity.value?.followups || []
})

const needFields = computed(() => {
  return identity.value?.need_fields || []
})

const taskId = computed(() => {
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
  })

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
  refreshInterval = setInterval(() => {
    fetchTask()
  }, 5000)
})

onUnmounted(() => {
  if (refreshInterval) {
    clearInterval(refreshInterval)
  }
})
</script>
