<template>
  <span class="photo-detail-expand" aria-hidden="true">
    <svg viewBox="0 0 24 24" width="20" height="20">
      <path
        d="M4 9V5a1 1 0 0 1 1-1h4M20 9V5a1 1 0 0 0-1-1h-4M4 15v4a1 1 0 0 0 1 1h4M20 15v4a1 1 0 0 1-1 1h-4"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
      />
    </svg>
  </span>
</template>

<style lang="scss">
.photo-detail-expand {
  position: absolute;
  left: 14px;
  bottom: 14px;
  z-index: 2;
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: rgba(20, 24, 34, 0.78);
  color: #fff;
  backdrop-filter: blur(8px);
  opacity: 0;
  transform: translateY(4px);
  pointer-events: none;
  transition:
    opacity $duration ease,
    transform $duration ease;
}

.photo-detail-image:hover .photo-detail-expand,
.photo-detail-image:focus-visible .photo-detail-expand {
  opacity: 1;
  transform: translateY(0);
}
</style>
