import { describe, expect, it } from 'vitest'
import { calculateGridLayout } from '../app/composables/useResponsiveGridColumns'

describe('calculateGridLayout', () => {
  it('returns 3 columns below the sm breakpoint (640px viewport)', () => {
    const result = calculateGridLayout(375, 375, 12)
    expect(result.columns).toBe(3)
  })

  it('returns 4 columns between sm (640px) and md (768px) viewport', () => {
    const result = calculateGridLayout(700, 700, 12)
    expect(result.columns).toBe(4)
  })

  it('returns 5 columns at or above the md breakpoint (768px) viewport', () => {
    const result = calculateGridLayout(1024, 1024, 12)
    expect(result.columns).toBe(5)
  })

  it('returns 5 columns exactly at the md breakpoint boundary', () => {
    const result = calculateGridLayout(768, 768, 12)
    expect(result.columns).toBe(5)
  })

  it('returns 4 columns exactly at the sm breakpoint boundary', () => {
    const result = calculateGridLayout(640, 640, 12)
    expect(result.columns).toBe(4)
  })

  it('bases column count on viewport width, not container width', () => {
    // 桌機視窗（1440px，遠超過 md 斷點），但容器因為頁面版面（header、
    // padding）比視窗窄，落在 sm~md 區間（700px）——欄數判斷必須看
    // viewport（回傳 5 欄），不能誤判成容器寬度對應的 4 欄。這是實際
    // 生產環境發生過的回歸案例：容器寬度被誤用來判斷欄數，導致桌機
    // 寬螢幕下只顯示 4 欄而非 5 欄。
    const result = calculateGridLayout(1440, 700, 12)
    expect(result.columns).toBe(5)
  })

  it('calculates itemSize from container width, not viewport width', () => {
    // viewport=1440（用於欄數判斷 → 5 欄），但容器只有 700px 寬
    // （用於 itemSize 計算）。
    // total gap = 12 * (5-1) = 48
    // columnWidth = (700 - 48) / 5 = 130.4
    // itemSize = 130.4 * 4/3 = 173.866...
    const result = calculateGridLayout(1440, 700, 12)
    expect(result.columns).toBe(5)
    expect(result.itemSize).toBeCloseTo(173.8666, 3)
  })

  it('calculates itemSize as columnWidth * 4/3 (aspect-3/4 ratio)', () => {
    // viewport=containerWidth=1024, gap=12, columns=5
    // total gap width = 12 * (5-1) = 48
    // columnWidth = (1024 - 48) / 5 = 195.2
    // itemSize = 195.2 * 4/3 = 260.266...
    const result = calculateGridLayout(1024, 1024, 12)
    expect(result.columns).toBe(5)
    expect(result.itemSize).toBeCloseTo(260.2666, 3)
  })

  it('calculates itemSize correctly for 3-column layout with zero gap', () => {
    // viewport=containerWidth=300, gap=0, columns=3
    // columnWidth = 300 / 3 = 100
    // itemSize = 100 * 4/3 = 133.333...
    const result = calculateGridLayout(300, 300, 0)
    expect(result.columns).toBe(3)
    expect(result.itemSize).toBeCloseTo(133.3333, 3)
  })

  it('returns columnWidth distinct from itemSize (regression: WindowScroller needs both)', () => {
    // WindowScroller 的 grid mode 在沒收到 item-secondary-size 時，會
    // fallback 用 item-size 當作每欄寬度（見 vue-virtual-scroller 原始碼
    // useRecycleScroller 的 `itemSecondarySize || itemSize`）。itemSize
    // 是「寬度 * 4/3」算出的列高，數值遠大於單欄實際寬度，若元件沒有把
    // columnWidth 傳給 item-secondary-size，每欄會被誤判成 itemSize 那
    // 麼寬，5 欄的總寬會超出容器，多出的欄位被推到可視範圍外——畫面上
    // 看起來就像少了一欄。這個測試確保 columnWidth 與 itemSize 是兩個
    // 不同的值，防止未來又漏接 item-secondary-size。
    const result = calculateGridLayout(1024, 1024, 12)
    expect(result.columnWidth).toBeCloseTo(195.2, 3)
    expect(result.itemSize).toBeCloseTo(260.2666, 3)
    expect(result.columnWidth).not.toBeCloseTo(result.itemSize, 0)
  })
})
