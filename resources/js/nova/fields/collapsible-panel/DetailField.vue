<template>
  <div class="collapsible-panel-field">
    <details
      :open="!isCollapsed"
      class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden"
      @toggle="handleToggle"
    >
      <summary
        class="px-4 py-3 cursor-pointer select-none font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-750 flex items-center justify-between transition-colors"
      >
        <span>{{ heading || field.name }}</span>
        <svg
          class="w-5 h-5 transform transition-transform duration-200"
          :class="{'rotate-180': !isCollapsed}"
          xmlns="http://www.w3.org/2000/svg"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
        >
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
        </svg>
      </summary>

      <div class="px-4 py-4 border-t border-gray-200 dark:border-gray-700">
        <div v-if="typeof content === 'string'" v-html="content" class="prose dark:prose-invert max-w-none"></div>
        <div v-else-if="Array.isArray(content)">
          <div v-for="(item, index) in content" :key="index" class="mb-3 last:mb-0">
            <div v-html="item"></div>
          </div>
        </div>
        <div v-else class="text-gray-600 dark:text-gray-400">
          {{ content || field.value }}
        </div>
      </div>
    </details>
  </div>
</template>

<script>
export default {
  props: {
    resourceName: String,
    resourceId: [Number, String],
    field: {
      type: Object,
      required: true,
    },
  },

  data() {
    return {
      isCollapsed: true,
    }
  },

  computed: {
    heading() {
      return this.field.heading || null
    },

    content() {
      return this.field.content || this.field.value || ''
    },
  },

  mounted() {
    // Initialize collapsed state from meta
    if (this.field.collapsed !== undefined) {
      this.isCollapsed = this.field.collapsed
    }
  },

  methods: {
    handleToggle(event) {
      this.isCollapsed = !event.target.open
    },
  },
}
</script>

<style scoped>
/* Custom styling for the collapsible panel */
details summary::-webkit-details-marker {
  display: none;
}

details summary::marker {
  display: none;
}

.collapsible-panel-field {
  margin-bottom: 1.5rem;
}
</style>
