import { type Ref, ref, onMounted, onBeforeUnmount } from 'vue'

/**
 * Tailwind 4 預設斷點（這個專案用 @import "tailwindcss"，沒有自訂
 * tailwind.config.js），對應現有卡片網格的
 * grid-cols-3 sm:grid-cols-4 md:grid-cols-5。
 */
const SM_BREAKPOINT_PX = 640
const MD_BREAKPOINT_PX = 768

/** 卡片的高寬比（aspect-3/4：寬 3 高 4）。 */
const CARD_ASPECT_HEIGHT_OVER_WIDTH = 4 / 3

export interface GridLayout {
  columns: number
  /** 列高（grid 主軸尺寸），對應 WindowScroller 的 item-size prop。 */
  itemSize: number
  /**
   * 單欄寬度（grid 次軸尺寸），對應 WindowScroller 的
   * item-secondary-size prop。vue-virtual-scroller 在沒有收到這個
   * prop 時會直接 fallback 用 itemSize 當次軸尺寸（見套件原始碼
   * useRecycleScroller 的 `itemSecondarySize || itemSize`），這會讓
   * 每欄寬度被誤設成列高的值——因為 itemSize 是「寬度 * 4/3」算出來的
   * 列高，數值遠大於單欄實際寬度，5 欄的總寬會超出容器，多出來的欄位
   * 被推到可視範圍外，畫面上看起來就像少了一欄。
   */
  columnWidth: number
}

/**
 * 給定「視窗寬度」與「容器寬度」算出目前應該顯示幾欄、每欄寬度
 * （columnWidth）、以及虛擬滾動需要的固定列高（itemSize）。純函式，
 * 不依賴 DOM，方便單獨測試。
 *
 * 欄數判斷刻意依據 viewportWidth（對應 Tailwind sm:/md: 這種 CSS media
 * query 的行為，永遠比對瀏覽器視窗寬度），不是 containerWidth——容器
 * 因為頁面版面（header、padding）通常比視窗窄，若拿容器寬度去比對斷點，
 * 會在視窗明明是桌機寬度時，容器卻落在 sm~md 區間，誤判成 4 欄而非 5 欄。
 * columnWidth/itemSize 則必須用 containerWidth 計算，因為卡片實際佔用
 * 的寬度是容器內的可用空間，不是整個視窗寬度。
 */
export function calculateGridLayout(viewportWidth: number, containerWidth: number, gapPx: number): GridLayout {
  const columns = viewportWidth >= MD_BREAKPOINT_PX
    ? 5
    : viewportWidth >= SM_BREAKPOINT_PX
      ? 4
      : 3

  const totalGap = gapPx * (columns - 1)
  const columnWidth = (containerWidth - totalGap) / columns
  const itemSize = columnWidth * CARD_ASPECT_HEIGHT_OVER_WIDTH

  return { columns, itemSize, columnWidth }
}

/**
 * 監聽 containerRef 的實際寬度變化，回傳響應式的欄數與列高，供
 * AnimeVirtualGrid 傳給 WindowScroller 的 grid-items / item-size。
 */
export function useResponsiveGridColumns(containerRef: Ref<HTMLElement | null>, gapPx: number) {
  const columns = ref(3)
  const itemSize = ref(0)
  const columnWidth = ref(0)

  if (!import.meta.client) {
    return { columns, itemSize, columnWidth }
  }

  let observer: ResizeObserver | null = null

  function recalculate() {
    const el = containerRef.value
    if (!el) return
    const layout = calculateGridLayout(window.innerWidth, el.clientWidth, gapPx)
    columns.value = layout.columns
    itemSize.value = layout.itemSize
    columnWidth.value = layout.columnWidth
  }

  onMounted(() => {
    const el = containerRef.value
    if (!el) return

    // 容器寬度變化（例如側邊欄收合、字體縮放）時重算 itemSize。
    observer = new ResizeObserver(() => recalculate())
    observer.observe(el)

    // 視窗寬度變化才會改變欄數判斷（見 calculateGridLayout 的說明），
    // ResizeObserver 觀察的是容器本身，不會在單純視窗變寬、容器寬度
    // 不變的情況下觸發，所以額外監聽 window resize。
    window.addEventListener('resize', recalculate)

    // 立即算一次初始值，不等第一次 callback（跟 useLazyLoad 的既有慣例
    // 一致：同步做一次初始檢查，不完全依賴非同步 callback）。
    recalculate()
  })

  onBeforeUnmount(() => {
    observer?.disconnect()
    window.removeEventListener('resize', recalculate)
  })

  return { columns, itemSize, columnWidth }
}
