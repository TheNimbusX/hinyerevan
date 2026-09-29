<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'

// The year pills are the handles: they stay grabbable even on adjacent years.
const props = defineProps({
  modelValue: { type: Array, required: true },
  min: { type: Number, required: true },
  max: { type: Number, required: true },
})
const emit = defineEmits(['update:modelValue', 'change'])

const trackEl = ref(null)
const fromEl = ref(null)
const toEl = ref(null)
const dragging = ref(null)
const pillShift = ref(0)
let grabOffset = 0
let resizeObserver

const from = computed(() => clamp(props.modelValue[0]))
const to = computed(() => clamp(props.modelValue[1]))
const span = computed(() => Math.max(0, props.max - props.min))

function clamp(year) {
  const value = Math.round(Number(year))
  if (!Number.isFinite(value)) return props.min
  return Math.min(props.max, Math.max(props.min, value))
}

function pct(year) {
  if (span.value <= 0) return 0
  return ((year - props.min) / span.value) * 100
}

function trackRect() {
  return trackEl.value?.getBoundingClientRect()
}

function yearAt(clientX) {
  const rect = trackRect()
  if (!rect || rect.width <= 0 || span.value <= 0) return props.min
  const ratio = Math.min(1, Math.max(0, (clientX - rect.left) / rect.width))
  return Math.round(props.min + ratio * span.value)
}

function pointX(year) {
  const rect = trackRect()
  return rect ? rect.left + (pct(year) / 100) * rect.width : 0
}

function setRange(nextFrom, nextTo) {
  if (nextFrom === from.value && nextTo === to.value) return
  emit('update:modelValue', [nextFrom, nextTo])
}

function moveHandle(which, year) {
  if (which === 'from') setRange(Math.min(year, to.value), to.value)
  else setRange(from.value, Math.max(year, from.value))
}

function startDrag(which, event) {
  if (span.value <= 0) return
  event.preventDefault()
  dragging.value = which
  // Where inside the pill it was grabbed, measured from the pill's visual centre.
  grabOffset = event.clientX - visualCenter(which)
  window.addEventListener('pointermove', onPointerMove)
  window.addEventListener('pointerup', stopDrag)
  window.addEventListener('pointercancel', stopDrag)
}

// Pills are pushed apart when close, so the visual centre sits off the year point.
function visualCenter(which) {
  return which === 'from'
    ? pointX(from.value) - pillShift.value
    : pointX(to.value) + pillShift.value
}

function onPointerMove(event) {
  if (!dragging.value) return
  // Undo the current push-apart so the pill stays under the pointer.
  const centre = event.clientX - grabOffset
  const x = dragging.value === 'from' ? centre + pillShift.value : centre - pillShift.value
  moveHandle(dragging.value, yearAt(x))
}

function stopDrag() {
  if (!dragging.value) return
  dragging.value = null
  window.removeEventListener('pointermove', onPointerMove)
  window.removeEventListener('pointerup', stopDrag)
  window.removeEventListener('pointercancel', stopDrag)
  emit('change', [from.value, to.value])
}

function onTrackPointerDown(event) {
  if (span.value <= 0 || event.target.closest('.yrs-pill')) return
  const year = yearAt(event.clientX)
  // Nearest handle; on a tie the side the click is on.
  const dFrom = Math.abs(year - from.value)
  const dTo = Math.abs(year - to.value)
  const which = dFrom < dTo || (dFrom === dTo && year <= from.value) ? 'from' : 'to'
  moveHandle(which, year)
  dragging.value = which
  grabOffset = 0
  window.addEventListener('pointermove', onPointerMove)
  window.addEventListener('pointerup', stopDrag)
  window.addEventListener('pointercancel', stopDrag)
}

function onKey(which, event) {
  const steps = { ArrowLeft: -1, ArrowDown: -1, ArrowRight: 1, ArrowUp: 1, PageDown: -10, PageUp: 10 }
  let year
  const current = which === 'from' ? from.value : to.value
  if (event.key in steps) year = current + steps[event.key]
  else if (event.key === 'Home') year = props.min
  else if (event.key === 'End') year = props.max
  else return
  event.preventDefault()
  moveHandle(which, clamp(year))
  emit('change', [from.value, to.value])
}

// Pills never overlap: push them apart around their midpoint.
function recomputeShift() {
  const rect = trackRect()
  if (!rect || !fromEl.value || !toEl.value) return
  const gap = ((pct(to.value) - pct(from.value)) / 100) * rect.width
  const need = (fromEl.value.offsetWidth + toEl.value.offsetWidth) / 2 + 2
  pillShift.value = gap < need ? (need - gap) / 2 : 0
}

// Synchronous: pointer math must see the shift for the current values.
watch([from, to, () => props.min, () => props.max], () => recomputeShift(), { flush: 'post' })

onMounted(() => {
  nextTick(recomputeShift)
  if (typeof ResizeObserver !== 'undefined') {
    resizeObserver = new ResizeObserver(() => recomputeShift())
    for (const el of [trackEl.value, fromEl.value, toEl.value]) {
      if (el) resizeObserver.observe(el)
    }
  }
  document.fonts?.ready?.then(recomputeShift)
})

onBeforeUnmount(() => {
  stopDrag()
  resizeObserver?.disconnect()
})
</script>

<template>
  <div class="yrs" :class="{ 'yrs--dragging': dragging, 'yrs--single': span <= 0 }">
    <div ref="trackEl" class="yrs-track" @pointerdown="onTrackPointerDown">
      <span class="yrs-rail" aria-hidden="true"></span>
      <span
        class="yrs-connect"
        aria-hidden="true"
        :style="{ left: `${pct(from)}%`, width: `${pct(to) - pct(from)}%` }"
      ></span>
      <span class="yrs-tick" aria-hidden="true" :style="{ left: `${pct(from)}%` }"></span>
      <span class="yrs-tick" aria-hidden="true" :style="{ left: `${pct(to)}%` }"></span>
      <button
        ref="fromEl"
        type="button"
        class="yrs-pill yrs-pill--from"
        :class="{ 'is-active': dragging === 'from' }"
        role="slider"
        :aria-valuemin="min"
        :aria-valuemax="to"
        :aria-valuenow="from"
        :style="{ left: `${pct(from)}%`, transform: `translate(calc(-50% - ${pillShift}px), -50%)` }"
        @pointerdown.stop="startDrag('from', $event)"
        @keydown="onKey('from', $event)"
      >
        {{ from }}
      </button>
      <button
        ref="toEl"
        type="button"
        class="yrs-pill yrs-pill--to"
        :class="{ 'is-active': dragging === 'to' }"
        role="slider"
        :aria-valuemin="from"
        :aria-valuemax="max"
        :aria-valuenow="to"
        :style="{ left: `${pct(to)}%`, transform: `translate(calc(-50% + ${pillShift}px), -50%)` }"
        @pointerdown.stop="startDrag('to', $event)"
        @keydown="onKey('to', $event)"
      >
        {{ to }}
      </button>
    </div>
  </div>
</template>

<style lang="scss">
.yrs {
  position: relative;
  min-width: 0;
  padding-inline: 26px;
  user-select: none;
  touch-action: none;
}

.yrs-track {
  position: relative;
  height: 28px;
  cursor: pointer;
}

.yrs-rail,
.yrs-connect {
  position: absolute;
  top: 50%;
  height: 4px;
  border-radius: 999px;
  transform: translateY(-50%);
}

.yrs-rail {
  left: 0;
  right: 0;
  background: #e4eaf6;
  box-shadow: inset 0 1px 1px rgba(23, 52, 126, 0.08);
}

.yrs-connect {
  background: linear-gradient(90deg, #c43d30, #8a1c14);
}

.yrs-tick {
  position: absolute;
  top: 50%;
  width: 8px;
  height: 8px;
  border: 2px solid #fff;
  border-radius: 50%;
  background: #8a1c14;
  transform: translate(-50%, -50%);
  pointer-events: none;
}

.yrs-pill {
  position: absolute;
  top: 50%;
  z-index: 2;
  display: flex;
  align-items: center;
  justify-content: center;
  height: 24px;
  min-width: 46px;
  padding: 0 8px;
  border: 0;
  border-radius: 6px;
  background: linear-gradient(135deg, #c43d30, #8a1c14);
  color: #fff;
  cursor: grab;
  font: inherit;
  font-size: calc(0.7857rem + 2px);
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  line-height: 1;
  white-space: nowrap;
  box-shadow: 0 3px 8px rgba(138, 28, 20, 0.35);
  touch-action: none;
  transition: box-shadow 0.15s ease;

  &:hover,
  &.is-active {
    box-shadow:
      0 0 0 3px rgba(255, 145, 15, 0.45),
      0 3px 8px rgba(138, 28, 20, 0.35);
  }

  &.is-active {
    z-index: 3;
    cursor: grabbing;
  }

  &:focus-visible {
    outline: 2px solid #ff910f;
    outline-offset: 2px;
  }
}

.yrs--single .yrs-pill {
  cursor: default;
}

.yrs--dragging,
.yrs--dragging .yrs-track {
  cursor: grabbing;
}

[data-theme='dark'] .yrs-rail {
  background: #2a313d;
  box-shadow: inset 0 1px 1px rgba(0, 0, 0, 0.32);
}
</style>
