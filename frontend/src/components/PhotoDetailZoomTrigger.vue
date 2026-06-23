<template>
  <span class="photo-detail-expand" aria-hidden="true">
    <svg viewBox="0 0 24 24" width="20" height="20">
      <path
        d="M12 5v14M5 12h14"
        fill="none"
        stroke="currentColor"
        stroke-width="2.2"
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
  pointer-events: none;
  box-shadow: 0 8px 20px rgba(8, 11, 18, 0.28);
}
</style>
