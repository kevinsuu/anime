<script setup lang="ts">
import { ref } from 'vue'
import { WindowScroller } from 'vue-virtual-scroller'
import 'vue-virtual-scroller/dist/vue-virtual-scroller.css'
import { useResponsiveGridColumns } from '../composables/useResponsiveGridColumns'
import type { Anime } from '../utils/normalize'

const props = withDefaults(defineProps<{
  items: Anime[]
  gapPx?: number
}>(), {
  gapPx: 12
})

const containerRef = ref<HTMLElement | null>(null)
const { columns, itemSize, columnWidth } = useResponsiveGridColumns(containerRef, props.gapPx)

defineSlots<{
  default(props: { item: Anime; index: number }): unknown
}>()
</script>

<template>
  <div ref="containerRef">
    <ClientOnly>
      <WindowScroller
        v-if="itemSize > 0"
        :items="items"
        :item-size="itemSize"
        :item-secondary-size="columnWidth"
        :grid-items="columns"
        key-field="id"
      >
        <template #default="{ item, index }">
          <slot :item="item" :index="index" />
        </template>
      </WindowScroller>

      <template #fallback>
        <div class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5">
          <slot
            v-for="(item, index) in items"
            :key="item.id"
            :item="item"
            :index="index"
          />
        </div>
      </template>
    </ClientOnly>
  </div>
</template>
