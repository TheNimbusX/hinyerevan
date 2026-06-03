<script setup>
import { ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import FacebookMarkIcon from './FacebookMarkIcon.vue'
import { formatCommentBody } from '../utils/commentBody'
import { commentAvatarUrl, commentDisplayName, commentInitials } from '../utils/commentDisplay'
import { formatDateTime } from '../utils/locale'
import { userProfilePath } from '../utils/user'

const props = defineProps({
  threads: {
    type: Array,
    default: () => [],
  },
  t: {
    type: Function,
    required: true,
  },
  lang: {
    type: String,
    default: 'hy',
  },
  isAuthenticated: {
    type: Boolean,
    default: false,
  },
  nested: {
    type: Boolean,
    default: false,
  },
  submitting: {
    type: Boolean,
    default: false,
  },
  replyResetKey: {
    type: Number,
    default: 0,
  },
  postError: {
    type: String,
    default: '',
  },
  currentUserUnique: {
    type: String,
    default: '',
  },
})

const emit = defineEmits(['submit', 'delete'])

const activeReplyId = ref(null)
const replyDraft = ref('')
const inlineError = ref('')
const confirmDeleteId = ref(null)

function canDelete(item) {
  return (
    item.source === 'site' &&
    typeof item.id === 'number' &&
    !!props.currentUserUnique &&
    item.author?.unique === props.currentUserUnique
  )
}

function askDelete(item) {
  confirmDeleteId.value = confirmDeleteId.value === item.id ? null : item.id
}

function confirmDelete(item) {
  confirmDeleteId.value = null
  emit('delete', item)
}

watch(
  () => props.replyResetKey,
  () => {
    activeReplyId.value = null
    replyDraft.value = ''
    inlineError.value = ''
  },
)

function canReply(item) {
  return item.source === 'site' || item.source === 'facebook'
}

function toggleReply(item) {
  if (!props.isAuthenticated) return
  if (activeReplyId.value === item.id) {
    activeReplyId.value = null
    replyDraft.value = ''
    return
  }
  activeReplyId.value = item.id
  replyDraft.value = ''
  inlineError.value = ''
}

function cancelInlineReply() {
  activeReplyId.value = null
  replyDraft.value = ''
  inlineError.value = ''
}

function submitInline(item) {
  const body = replyDraft.value.trim()
  if (!body || props.submitting) return
  inlineError.value = ''
  emit('submit', { replyTo: item, body })
}

watch(
  () => props.postError,
  (message) => {
    if (message) inlineError.value = message
  },
)
</script>

<template>
  <ul class="comment-thread" :class="{ 'comment-thread--nested': nested }">
    <li v-for="item in threads" :key="item.id" class="comment-thread__item">
      <article
        class="comment-row"
        :class="{ 'comment-row--facebook': item.source === 'facebook' }"
      >
        <div class="comment-row__avatar" aria-hidden="true">
          <img
            v-if="commentAvatarUrl(item)"
            :src="commentAvatarUrl(item)"
            :alt="commentDisplayName(item, t)"
            loading="lazy"
          />
          <span
            v-else
            class="comment-row__initials"
            :class="{ 'comment-row__initials--fb': item.source === 'facebook' }"
          >
            {{ commentInitials(commentDisplayName(item, t)) }}
          </span>
        </div>

        <div class="comment-row__main">
          <header class="comment-row__head">
            <div class="comment-row__meta">
              <RouterLink
                v-if="item.source === 'site' && item.author?.unique"
                class="comment-row__author"
                :to="userProfilePath(item.author)"
              >
                {{ commentDisplayName(item, t) }}
              </RouterLink>
              <span v-else class="comment-row__author">{{ commentDisplayName(item, t) }}</span>
              <span
                v-if="item.source === 'facebook'"
                class="comment-row__fb-icon"
                :title="t('facebookCommentBadge')"
              >
                <FacebookMarkIcon :size="14" />
              </span>
              <time v-if="item.datetime" class="comment-row__time" :datetime="item.datetime">
                {{ formatDateTime(item.datetime, lang) }}
              </time>
            </div>
            <div
              v-if="isAuthenticated && (canReply(item) || canDelete(item))"
              class="comment-row__actions"
              :class="{ 'is-active': activeReplyId === item.id || confirmDeleteId === item.id }"
            >
              <button
                v-if="canReply(item)"
                type="button"
                class="comment-row__reply-btn"
                :aria-expanded="activeReplyId === item.id"
                @click="toggleReply(item)"
              >
                {{ activeReplyId === item.id ? t('cancelReply') : t('reply') }}
              </button>
              <template v-if="canDelete(item)">
                <button
                  v-if="confirmDeleteId !== item.id"
                  type="button"
                  class="comment-row__delete-btn"
                  :aria-label="t('deleteComment')"
                  :title="t('deleteComment')"
                  @click="askDelete(item)"
                >
                  <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true">
                    <path
                      fill="currentColor"
                      d="M9 3a1 1 0 0 0-1 1v1H4v2h16V5h-4V4a1 1 0 0 0-1-1H9Zm-3 6 1 11a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-11H6Z"
                    />
                  </svg>
                </button>
                <span v-else class="comment-row__delete-confirm">
                  <button type="button" class="comment-row__delete-yes" @click="confirmDelete(item)">
                    {{ t('confirmDelete') }}
                  </button>
                  <button type="button" class="comment-row__delete-no" @click="confirmDeleteId = null">
                    {{ t('cancel') }}
                  </button>
                </span>
              </template>
            </div>
          </header>
          <p class="comment-row__body">{{ formatCommentBody(item.body) }}</p>
        </div>
      </article>

      <form
        v-if="activeReplyId === item.id && isAuthenticated"
        class="comment-inline-reply"
        @submit.prevent="submitInline(item)"
      >
        <textarea
          v-model="replyDraft"
          rows="2"
          :placeholder="t('writeReply')"
          :disabled="submitting"
          required
        />
        <div class="comment-inline-reply__actions">
          <button class="button" type="submit" :disabled="submitting || !replyDraft.trim()">
            {{ t('postComment') }}
          </button>
          <button type="button" class="link-button" :disabled="submitting" @click="cancelInlineReply">
            {{ t('cancel') }}
          </button>
        </div>
        <p v-if="inlineError" class="error comment-inline-reply__error">{{ inlineError }}</p>
      </form>

      <PhotoCommentThread
        v-if="item.replies?.length"
        :threads="item.replies"
        :t="t"
        :lang="lang"
        :is-authenticated="isAuthenticated"
        :submitting="submitting"
        :reply-reset-key="replyResetKey"
        :post-error="postError"
        :current-user-unique="currentUserUnique"
        nested
        @submit="emit('submit', $event)"
        @delete="emit('delete', $event)"
      />
    </li>
  </ul>
</template>

<style lang="scss" scoped>
.comment-thread {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 12px;

  &--nested {
    margin-top: 8px;
    margin-left: 16px;
    padding-left: 12px;
    border-left: 1px solid rgba(0, 0, 0, 0.1);
    gap: 10px;
  }
}

.comment-row {
  display: grid;
  grid-template-columns: 36px minmax(0, 1fr);
  gap: 8px 10px;
  align-items: start;

  &--facebook .comment-row__main {
    background: rgba(24, 119, 242, 0.05);
    border: 1px solid rgba(24, 119, 242, 0.1);
  }
}

.comment-row__avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  overflow: hidden;
  background: #e4e6eb;
  flex-shrink: 0;

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }
}

.comment-row__initials {
  display: grid;
  place-items: center;
  width: 100%;
  height: 100%;
  font-size: 12px;
  font-weight: 700;
  color: #fff;
  background: linear-gradient(145deg, #6b7280, #4b5563);

  &--fb {
    background: linear-gradient(145deg, #3b82f6, #1877f2);
  }
}

.comment-row__main {
  min-width: 0;
  padding: 8px 10px;
  border-radius: 10px;
  background: rgba(0, 0, 0, 0.035);
}

.comment-row__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 2px;
}

.comment-row__meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  min-width: 0;
}

.comment-row__author {
  font-weight: 600;
  font-size: 13px;
  color: inherit;
  text-decoration: none;

  &:hover {
    text-decoration: underline;
  }
}

.comment-row__fb-icon {
  display: inline-flex;
  line-height: 0;
}

.comment-row__time {
  font-size: 11px;
  color: #65676b;
}

.comment-row__actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
  opacity: 0;
  transform: translateY(-1px);
  transition: opacity 0.15s ease;

  &.is-active {
    opacity: 1;
  }

  // Touch devices can't hover — keep actions reachable.
  @media (hover: none) {
    opacity: 1;
  }
}

.comment-row:hover .comment-row__actions,
.comment-row:focus-within .comment-row__actions {
  opacity: 1;
}

.comment-row__reply-btn {
  border: 0;
  padding: 0;
  background: none;
  font-size: 12px;
  font-weight: 600;
  color: #1877f2;
  cursor: pointer;
  white-space: nowrap;

  &:hover {
    text-decoration: underline;
  }
}

.comment-row__delete-btn {
  display: inline-flex;
  align-items: center;
  border: 0;
  padding: 0;
  background: none;
  color: #b0b3b8;
  cursor: pointer;
  line-height: 0;
  transition: color 0.15s ease;

  &:hover {
    color: #e53935;
  }
}

.comment-row__delete-confirm {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  white-space: nowrap;

  button {
    border: 0;
    padding: 0;
    background: none;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
  }
}

.comment-row__delete-yes {
  color: #e53935;
}

.comment-row__delete-no {
  color: #65676b;
}

.comment-row__body {
  margin: 0;
  font-size: 14px;
  line-height: 1.45;
  white-space: pre-wrap;
  word-break: break-word;
}

.comment-inline-reply {
  margin: 6px 0 0 46px;
  display: grid;
  gap: 8px;

  textarea {
    width: 100%;
    min-height: 56px;
    padding: 8px 10px;
    border: 1px solid rgba(0, 0, 0, 0.12);
    border-radius: 10px;
    font: inherit;
    font-size: 14px;
    resize: vertical;
    background: transparent;
    color: inherit;
  }
}

.comment-inline-reply__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}

.comment-inline-reply__error {
  margin: 0;
  font-size: 13px;
}
</style>

<style lang="scss">
[data-theme='dark'] {
  .comment-thread--nested {
    border-left-color: rgba(255, 255, 255, 0.12);
  }

  .comment-row__main {
    background: rgba(255, 255, 255, 0.04);
  }

  .comment-row--facebook .comment-row__main {
    background: rgba(24, 119, 242, 0.12);
    border-color: rgba(24, 119, 242, 0.22);
  }

  .comment-inline-reply textarea {
    border-color: rgba(255, 255, 255, 0.14);
  }
}
</style>
