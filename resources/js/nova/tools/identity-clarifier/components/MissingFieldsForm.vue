<template>
  <div class="missing-fields-form p-6 bg-white dark:bg-gray-800 rounded-lg shadow-sm">
    <div class="mb-4">
      <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
        📝 Missing Fields
      </h3>
      <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
        The research is running but we need more information to improve identity verification.
        Please fill in the missing fields below.
      </p>
    </div>

    <!-- Progress -->
    <div class="mb-6">
      <div class="flex justify-between text-sm mb-1">
        <span class="text-gray-700 dark:text-gray-300">Completion</span>
        <span class="font-semibold text-gray-900 dark:text-gray-100">
          {{ checkResult.completion_percentage }}%
        </span>
      </div>
      <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
        <div
          class="bg-blue-600 h-2 rounded-full transition-all duration-300"
          :style="{ width: checkResult.completion_percentage + '%' }"
        ></div>
      </div>
    </div>

    <!-- Form -->
    <form @submit.prevent="handleSubmit" class="space-y-4">
      <!-- Title -->
      <div v-if="shouldShowField('title')">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
          Exam Title
          <span v-if="isFieldCritical('title')" class="text-red-600">*</span>
        </label>
        <input
          v-model="formData.title"
          type="text"
          class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md dark:bg-gray-700 dark:text-gray-100"
          placeholder="e.g., IELTS Academic"
        />
      </div>

      <!-- Language of Test -->
      <div v-if="shouldShowField('language_of_test')">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
          Language of Test
          <span v-if="isFieldCritical('language_of_test')" class="text-red-600">*</span>
        </label>
        <input
          v-model="formData.language_of_test"
          type="text"
          class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md dark:bg-gray-700 dark:text-gray-100"
          placeholder="e.g., English, German, French"
        />
      </div>

      <!-- Level -->
      <div v-if="shouldShowField('level')">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
          Level (CEFR)
          <span v-if="isFieldCritical('level')" class="text-red-600">*</span>
        </label>
        <select
          v-model="formData.level"
          class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md dark:bg-gray-700 dark:text-gray-100"
        >
          <option value="">Select level...</option>
          <option value="A1">A1</option>
          <option value="A2">A2</option>
          <option value="B1">B1</option>
          <option value="B2">B2</option>
          <option value="C1">C1</option>
          <option value="C2">C2</option>
        </select>
      </div>

      <!-- Exam Family -->
      <div v-if="shouldShowField('exam_family')">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
          Exam Family
          <span v-if="isFieldCritical('exam_family')" class="text-red-600">*</span>
        </label>
        <input
          v-model="formData.exam_family"
          type="text"
          class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md dark:bg-gray-700 dark:text-gray-100"
          placeholder="e.g., IELTS, TOEFL, Goethe-Zertifikat"
        />
      </div>

      <!-- Exam Provider -->
      <div v-if="shouldShowField('exam_provider')">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
          Exam Provider
          <span v-if="isFieldCritical('exam_provider')" class="text-red-600">*</span>
        </label>
        <input
          v-model="formData.exam_provider"
          type="text"
          class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md dark:bg-gray-700 dark:text-gray-100"
          placeholder="e.g., Cambridge, ETS, Goethe-Institut"
        />
      </div>

      <!-- Description -->
      <div v-if="shouldShowField('description')">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
          Exam Description
          <span v-if="isFieldCritical('description')" class="text-red-600">*</span>
        </label>
        <textarea
          v-model="formData.description"
          rows="3"
          class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md dark:bg-gray-700 dark:text-gray-100"
          placeholder="Brief description of the exam..."
        ></textarea>
      </div>

      <!-- Document/User Input Note -->
      <div v-if="shouldShowField('has_document_or_input')" class="bg-yellow-50 dark:bg-yellow-900 p-4 rounded-md">
        <p class="text-sm text-yellow-800 dark:text-yellow-200">
          ⚠️ No document or user input found. Please upload a document or provide additional information in the exam's user_input field.
        </p>
      </div>

      <!-- Buttons -->
      <div class="flex gap-3 pt-4">
        <button
          type="submit"
          :disabled="submitting"
          class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {{ submitting ? 'Saving...' : 'Save & Continue' }}
        </button>

        <button
          type="button"
          @click="handleDismiss"
          class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-md hover:bg-gray-300 dark:hover:bg-gray-600"
        >
          Dismiss
        </button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  examId: {
    type: [String, Number],
    required: true,
  },
  checkResult: {
    type: Object,
    required: true,
  },
})

const emit = defineEmits(['saved', 'dismissed'])

const submitting = ref(false)
const formData = ref({
  title: '',
  language_of_test: '',
  level: '',
  exam_family: '',
  exam_provider: '',
  description: '',
})

const missingFields = computed(() => {
  return [
    ...props.checkResult.missing_critical || [],
    ...props.checkResult.missing_recommended || [],
  ]
})

const shouldShowField = (fieldName) => {
  return missingFields.value.includes(fieldName)
}

const isFieldCritical = (fieldName) => {
  return props.checkResult.missing_critical?.includes(fieldName) || false
}

const handleSubmit = async () => {
  submitting.value = true

  try {
    // Build update payload with only filled fields
    const updates = {}
    const userInputUpdates = {}

    for (const [key, value] of Object.entries(formData.value)) {
      if (!value) continue // Skip empty fields

      // Map fields to exam attributes or user_input
      switch (key) {
        case 'title':
        case 'level':
        case 'description':
          updates[key] = value
          break
        case 'language_of_test':
        case 'exam_family':
        case 'exam_provider':
          userInputUpdates[key] = value
          break
      }
    }

    // Add user_input updates if any
    if (Object.keys(userInputUpdates).length > 0) {
      updates.user_input_updates = userInputUpdates
    }

    // Send to backend
    await Nova.request().post(`/api/exams/${props.examId}/update-fields`, updates)

    Nova.success('Fields updated successfully!')
    emit('saved')

    // Reload page to show updated data
    setTimeout(() => {
      location.reload()
    }, 1000)
  } catch (error) {
    console.error('Failed to update fields:', error)
    Nova.error('Failed to update fields: ' + (error.response?.data?.message || error.message))
  } finally {
    submitting.value = false
  }
}

const handleDismiss = () => {
  emit('dismissed')
}
</script>
