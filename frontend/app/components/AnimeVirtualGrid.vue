<script setup lang="ts">
import { ref } from 'vue'
import { WindowScroller } from 'vue-virtual-scroller'
import 'vue-virtual-scroller/dist/vue-virtual-scroller.css'
import { VIRTUAL_RENDER_BUFFER_PX } from '../composables/useLazyLoad'
import { useResponsiveGridColumns } from '../composables/useResponsiveGridColumns'
import type { AnimeCardData } from '../utils/normalize'

const props = withDefaults(defineProps<{
  items: AnimeCardData[]
  gapPx?: number
}>(), {
  gapPx: 12
})

const containerRef = ref<HTMLElement | null>(null)
const { columns, itemSize, columnWidth } = useResponsiveGridColumns(containerRef, props.gapPx)
const SSR_FALLBACK_CARD_COUNT = 12
const fallbackItems = computed(() => props.items.slice(0, SSR_FALLBACK_CARD_COUNT))

defineSlots<{
  default(props: { item: AnimeCardData; index: number }): unknown
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
        :buffer="VIRTUAL_RENDER_BUFFER_PX"
        key-field="id"
      >
        <template #default="{ item, index }">
          <!-- 每個 item 的寬度是整欄 stride（columnWidth），gutter 由這層
               左右各半個 gap 的內邊距形成：相鄰卡片內容之間剛好間隔一個
               完整 gap，最外側各留半個 gap，讓整排格線在容器內置中、右緣
               與上方篩選卡片對齊。 -->
          <div :style="{ paddingLeft: `${gapPx / 2}px`, paddingRight: `${gapPx / 2}px` }">
            <slot :item="item" :index="index" />
          </div>
        </template>
      </WindowScroller>

      <template #fallback>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-5">
          <div v-for="(item, index) in fallbackItems" :key="item.id">
            <slot :item="item" :index="index" />
          </div>
        </div>
      </template>
    </ClientOnly>
  </div>
</template>
