import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  createMobileCardGestureHandlers,
  MOBILE_CARD_DOUBLE_TAP_DELAY_MS,
  MOBILE_CARD_LONG_PRESS_DELAY_MS
} from '../app/utils/mobileCardGestures'

function pointerEvent(overrides: Partial<PointerEvent> = {}) {
  return {
    button: 0,
    clientX: 20,
    clientY: 30,
    isPrimary: true,
    pointerType: 'touch',
    ...overrides
  } as PointerEvent
}

function clickEvent() {
  return {
    preventDefault: vi.fn(),
    stopPropagation: vi.fn()
  } as unknown as MouseEvent
}

describe('mobile card gestures', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-08-06T00:00:00Z'))
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  function setup() {
    const callbacks = {
      isEnabled: vi.fn(() => true),
      onDoubleTap: vi.fn(),
      onLongPress: vi.fn(),
      onNavigate: vi.fn(),
      haptic: vi.fn()
    }
    return { callbacks, handlers: createMobileCardGestureHandlers(callbacks) }
  }

  function tap(handlers: ReturnType<typeof createMobileCardGestureHandlers>, pointerType: 'touch' | 'mouse' = 'touch') {
    handlers.onPointerDown(pointerEvent({ pointerType }))
    handlers.onPointerUp()
    // Mobile browsers may dispatch pointerleave after releasing the pointer
    // but before the synthesized click.
    handlers.onPointerLeave()
    const event = clickEvent()
    handlers.onClick(event)
    return event
  }

  it('keeps a single tap as navigation after the double-tap window', () => {
    const { callbacks, handlers } = setup()
    const event = tap(handlers)

    expect(event.preventDefault).toHaveBeenCalledOnce()
    expect(callbacks.onNavigate).not.toHaveBeenCalled()
    vi.advanceTimersByTime(MOBILE_CARD_DOUBLE_TAP_DELAY_MS)
    expect(callbacks.onNavigate).toHaveBeenCalledOnce()
    expect(callbacks.onDoubleTap).not.toHaveBeenCalled()
  })

  it('uses a second tap for favorite without navigating', () => {
    const { callbacks, handlers } = setup()
    tap(handlers)
    vi.advanceTimersByTime(120)
    tap(handlers)
    vi.runAllTimers()

    expect(callbacks.onDoubleTap).toHaveBeenCalledOnce()
    expect(callbacks.onNavigate).not.toHaveBeenCalled()
    expect(callbacks.haptic).toHaveBeenCalledOnce()
  })

  it('uses a stationary long press for watched and suppresses the following click', () => {
    const { callbacks, handlers } = setup()
    handlers.onPointerDown(pointerEvent())
    vi.advanceTimersByTime(MOBILE_CARD_LONG_PRESS_DELAY_MS)
    handlers.onPointerUp()
    handlers.onClick(clickEvent())
    vi.runAllTimers()

    expect(callbacks.onLongPress).toHaveBeenCalledOnce()
    expect(callbacks.onNavigate).not.toHaveBeenCalled()
    expect(callbacks.onDoubleTap).not.toHaveBeenCalled()
    expect(callbacks.haptic).toHaveBeenCalledOnce()
  })

  it('measures the double-tap gap from when the finger tapped, not when the handler ran', () => {
    // Right after an action the phone is busy (toast, re-render, request), so
    // the second click can be dispatched late. Its event timestamp still says
    // when the tap actually happened.
    const { callbacks, handlers } = setup()
    handlers.onPointerDown(pointerEvent())
    handlers.onPointerUp()
    handlers.onClick({ ...clickEvent(), timeStamp: 1000 } as unknown as MouseEvent)
    vi.advanceTimersByTime(MOBILE_CARD_DOUBLE_TAP_DELAY_MS - 20)
    handlers.onPointerDown(pointerEvent())
    handlers.onPointerUp()
    // Handler runs late; the tap itself was only 200ms after the first one.
    vi.setSystemTime(Date.now() + 400)
    handlers.onClick({ ...clickEvent(), timeStamp: 1200 } as unknown as MouseEvent)
    vi.runAllTimers()

    expect(callbacks.onDoubleTap).toHaveBeenCalledOnce()
    expect(callbacks.onNavigate).not.toHaveBeenCalled()
  })

  it('holds the pending navigation once a second press starts, even if its click is late', () => {
    const { callbacks, handlers } = setup()
    handlers.onPointerDown(pointerEvent())
    handlers.onPointerUp()
    handlers.onClick(clickEvent())
    vi.advanceTimersByTime(150)
    handlers.onPointerDown(pointerEvent())
    handlers.onPointerUp()
    // The single-tap timer would have fired by now if it were still armed.
    vi.advanceTimersByTime(MOBILE_CARD_DOUBLE_TAP_DELAY_MS)
    expect(callbacks.onNavigate).not.toHaveBeenCalled()
    handlers.onClick({ ...clickEvent(), timeStamp: 0 } as unknown as MouseEvent)
    vi.runAllTimers()

    expect(callbacks.onDoubleTap).toHaveBeenCalledOnce()
    expect(callbacks.onNavigate).not.toHaveBeenCalled()
  })

  it('drops the first tap navigation when the second press turns into a scroll', () => {
    const { callbacks, handlers } = setup()
    tap(handlers)
    vi.advanceTimersByTime(100)
    handlers.onPointerDown(pointerEvent())
    handlers.onPointerMove(pointerEvent({ clientY: 200 }))
    handlers.onPointerCancel()
    vi.runAllTimers()

    expect(callbacks.onNavigate).not.toHaveBeenCalled()
    expect(callbacks.onDoubleTap).not.toHaveBeenCalled()
  })

  it('treats the platform long-press contextmenu as watched before the timer fires', () => {
    // Android Chrome fires contextmenu (≈400–500ms) before our timer; if the
    // browser then starts its own drag it sends pointercancel and the timer
    // would never fire, so the long press silently did nothing.
    const { callbacks, handlers } = setup()
    handlers.onPointerDown(pointerEvent())
    vi.advanceTimersByTime(420)
    const menu = { preventDefault: vi.fn() } as unknown as MouseEvent
    handlers.onContextMenu(menu)
    handlers.onPointerCancel()
    vi.runAllTimers()

    expect(menu.preventDefault).toHaveBeenCalledOnce()
    expect(callbacks.onLongPress).toHaveBeenCalledOnce()
    expect(callbacks.haptic).toHaveBeenCalledOnce()
  })

  it('does not toggle watched twice when the timer and contextmenu both fire', () => {
    const { callbacks, handlers } = setup()
    handlers.onPointerDown(pointerEvent())
    vi.advanceTimersByTime(MOBILE_CARD_LONG_PRESS_DELAY_MS)
    handlers.onContextMenu({ preventDefault: vi.fn() } as unknown as MouseEvent)
    handlers.onPointerUp()
    handlers.onClick(clickEvent())
    vi.runAllTimers()

    expect(callbacks.onLongPress).toHaveBeenCalledOnce()
    expect(callbacks.onNavigate).not.toHaveBeenCalled()
  })

  it('ignores a mouse right-click contextmenu', () => {
    const { callbacks, handlers } = setup()
    handlers.onPointerDown(pointerEvent({ pointerType: 'mouse', button: 2 }))
    handlers.onContextMenu({ preventDefault: vi.fn() } as unknown as MouseEvent)
    vi.runAllTimers()

    expect(callbacks.onLongPress).not.toHaveBeenCalled()
  })

  it('cancels gestures when the finger moves far enough to scroll', () => {
    const { callbacks, handlers } = setup()
    handlers.onPointerDown(pointerEvent())
    handlers.onPointerMove(pointerEvent({ clientY: 50 }))
    handlers.onPointerUp()
    handlers.onClick(clickEvent())
    vi.runAllTimers()

    expect(callbacks.onLongPress).not.toHaveBeenCalled()
    expect(callbacks.onNavigate).not.toHaveBeenCalled()
    expect(callbacks.onDoubleTap).not.toHaveBeenCalled()
  })

  it('cancels a press that leaves the card before release', () => {
    const { callbacks, handlers } = setup()
    handlers.onPointerDown(pointerEvent())
    handlers.onPointerLeave()
    handlers.onPointerUp()
    handlers.onClick(clickEvent())
    vi.runAllTimers()

    expect(callbacks.onLongPress).not.toHaveBeenCalled()
    expect(callbacks.onNavigate).not.toHaveBeenCalled()
    expect(callbacks.onDoubleTap).not.toHaveBeenCalled()
  })

  it('uses a single mouse click for delayed navigation', () => {
    const { callbacks, handlers } = setup()
    const event = tap(handlers, 'mouse')

    expect(event.preventDefault).toHaveBeenCalledOnce()
    vi.advanceTimersByTime(MOBILE_CARD_DOUBLE_TAP_DELAY_MS)
    expect(callbacks.onNavigate).toHaveBeenCalledOnce()
  })

  it('uses a second mouse click for favorite without navigating', () => {
    const { callbacks, handlers } = setup()
    tap(handlers, 'mouse')
    vi.advanceTimersByTime(120)
    tap(handlers, 'mouse')
    vi.runAllTimers()

    expect(callbacks.onDoubleTap).toHaveBeenCalledOnce()
    expect(callbacks.onNavigate).not.toHaveBeenCalled()
    expect(callbacks.haptic).not.toHaveBeenCalled()
  })

  it('uses a stationary mouse hold for watched', () => {
    const { callbacks, handlers } = setup()
    handlers.onPointerDown(pointerEvent({ pointerType: 'mouse' }))
    vi.advanceTimersByTime(MOBILE_CARD_LONG_PRESS_DELAY_MS)
    handlers.onPointerUp()
    handlers.onClick(clickEvent())

    expect(callbacks.onLongPress).toHaveBeenCalledOnce()
    expect(callbacks.onNavigate).not.toHaveBeenCalled()
    expect(callbacks.haptic).not.toHaveBeenCalled()
  })

  it('leaves non-primary mouse buttons alone', () => {
    const { callbacks, handlers } = setup()
    handlers.onPointerDown(pointerEvent({ button: 2, pointerType: 'mouse' }))
    const event = clickEvent()
    handlers.onClick(event)

    expect(event.preventDefault).not.toHaveBeenCalled()
    expect(callbacks.onNavigate).not.toHaveBeenCalled()
    expect(callbacks.onDoubleTap).not.toHaveBeenCalled()
    expect(callbacks.onLongPress).not.toHaveBeenCalled()
  })
})
