export default defineAppConfig({
  ui: {
    toaster: {
      slots: {
        viewport: 'max-sm:right-3 max-sm:top-3 max-sm:w-64'
      }
    },
    toast: {
      slots: {
        root: 'max-sm:gap-2 max-sm:rounded-md max-sm:p-2.5 max-sm:shadow-md',
        title: 'max-sm:text-xs max-sm:font-semibold max-sm:leading-4',
        description: 'max-sm:text-xs max-sm:leading-4',
        icon: 'max-sm:size-4',
        close: 'max-sm:[&>span]:size-3.5',
        progress: 'max-sm:h-0.5'
      }
    }
  }
})
