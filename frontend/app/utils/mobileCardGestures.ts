export const MOBILE_CARD_DOUBLE_TAP_DELAY_MS = 320
export const MOBILE_CARD_LONG_PRESS_DELAY_MS = 550
const MOBILE_CARD_MOVE_TOLERANCE_PX = 12

type PointerLikeEvent = Pick<PointerEvent, 'button' | 'clientX' | 'clientY' | 'isPrimary' | 'pointerType'>
type ClickLikeEvent = Pick<MouseEvent, 'preventDefault' | 'stopPropagation'> & { timeStamp?: number }
type ContextMenuLikeEvent = Pick<MouseEvent, 'preventDefault'>

interface MobileCardGestureCallbacks {
  isEnabled: () => boolean
  onDoubleTap: () => void
  onLongPress: () => void
  onNavigate: () => void
  haptic?: () => void
}

function defaultHaptic() {
  if (typeof navigator !== 'undefined' && 'vibrate' in navigator) navigator.vibrate(25)
}

export function createMobileCardGestureHandlers(callbacks: MobileCardGestureCallbacks) {
  let gestureSequence = false
  let gestureCancelled = false
  let longPressTriggered = false
  let pointerPressed = false
  let activePointerType = ''
  let pressX = 0
  let pressY = 0
  let lastTapAt = 0
  // True when a new press started while the previous tap was still waiting to
  // navigate: that press is the second half of a double tap.
  let secondPressStarted = false
  let singleTapTimer: ReturnType<typeof setTimeout> | null = null
  let longPressTimer: ReturnType<typeof setTimeout> | null = null

  const haptic = callbacks.haptic ?? defaultHaptic

  function clearSingleTapTimer() {
    if (singleTapTimer === null) return
    clearTimeout(singleTapTimer)
    singleTapTimer = null
  }

  function clearLongPressTimer() {
    if (longPressTimer === null) return
    clearTimeout(longPressTimer)
    longPressTimer = null
  }

  function onPointerDown(event: PointerLikeEvent) {
    const supportedPointer = event.pointerType === 'touch'
      || event.pointerType === 'pen'
      || (event.pointerType === 'mouse' && event.button === 0)
    gestureSequence = callbacks.isEnabled() && supportedPointer && event.isPrimary
    gestureCancelled = false
    longPressTriggered = false
    pointerPressed = gestureSequence
    activePointerType = gestureSequence ? event.pointerType : ''
    clearLongPressTimer()
    if (!gestureSequence) return

    pressX = event.clientX
    pressY = event.clientY
    // Hold the previous tap's navigation now; this press's click decides
    // between double tap and a new single tap even if it is dispatched late.
    secondPressStarted = singleTapTimer !== null
    clearSingleTapTimer()
    longPressTimer = setTimeout(triggerLongPress, MOBILE_CARD_LONG_PRESS_DELAY_MS)
  }

  function triggerLongPress() {
    clearLongPressTimer()
    if (longPressTriggered) return
    longPressTriggered = true
    clearSingleTapTimer()
    lastTapAt = 0
    callbacks.onLongPress()
    if (activePointerType !== 'mouse') haptic()
  }

  function onPointerMove(event: PointerLikeEvent) {
    if (!gestureSequence || gestureCancelled) return
    if (
      Math.abs(event.clientX - pressX) <= MOBILE_CARD_MOVE_TOLERANCE_PX
      && Math.abs(event.clientY - pressY) <= MOBILE_CARD_MOVE_TOLERANCE_PX
    ) return

    gestureCancelled = true
    clearLongPressTimer()
  }

  function onPointerUp() {
    pointerPressed = false
    clearLongPressTimer()
  }

  function onPointerCancel() {
    pointerPressed = false
    gestureCancelled = true
    clearLongPressTimer()
  }

  function onPointerLeave() {
    // Touch browsers can emit pointerleave after pointerup but before click.
    // At that point the tap is already complete and must still reach the
    // single/double-tap resolver. Only leaving while actively pressed cancels.
    if (!pointerPressed) return
    gestureCancelled = true
    pointerPressed = false
    clearLongPressTimer()
  }

  function onClick(event: ClickLikeEvent) {
    if (!gestureSequence || !callbacks.isEnabled()) return

    event.preventDefault()
    event.stopPropagation()
    gestureSequence = false

    if (gestureCancelled || longPressTriggered) {
      gestureCancelled = false
      longPressTriggered = false
      secondPressStarted = false
      lastTapAt = 0
      return
    }

    // Use when the tap happened, not when this handler got to run: right after
    // an action the page is busy and a quick second tap can be dispatched
    // late, which used to turn a double tap into a navigation.
    const now = typeof event.timeStamp === 'number' && event.timeStamp > 0 ? event.timeStamp : Date.now()
    const isSecondTap = secondPressStarted
      || (lastTapAt > 0 && now - lastTapAt <= MOBILE_CARD_DOUBLE_TAP_DELAY_MS)
    secondPressStarted = false
    if (isSecondTap) {
      clearSingleTapTimer()
      lastTapAt = 0
      callbacks.onDoubleTap()
      if (activePointerType !== 'mouse') haptic()
      return
    }

    lastTapAt = now
    clearSingleTapTimer()
    singleTapTimer = setTimeout(() => {
      singleTapTimer = null
      lastTapAt = 0
      callbacks.onNavigate()
    }, MOBILE_CARD_DOUBLE_TAP_DELAY_MS)
  }

  function onContextMenu(event: ContextMenuLikeEvent) {
    if (!gestureSequence || !callbacks.isEnabled()) return
    event.preventDefault()
    // On touch devices contextmenu *is* the platform's long-press signal. It
    // can arrive before our timer, and Android may then start a drag that
    // cancels the pointer, so the timer alone is not reliable there.
    if (activePointerType !== 'mouse' && pointerPressed && !gestureCancelled) triggerLongPress()
  }

  function dispose() {
    clearSingleTapTimer()
    clearLongPressTimer()
  }

  return {
    onClick,
    onContextMenu,
    onPointerCancel,
    onPointerDown,
    onPointerLeave,
    onPointerMove,
    onPointerUp,
    dispose
  }
}
