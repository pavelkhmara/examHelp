import DetailField from './DetailField.vue'

Nova.booting((app, store) => {
  app.component('detail-collapsible-panel', DetailField)
})
