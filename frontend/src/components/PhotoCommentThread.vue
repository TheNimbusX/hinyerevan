<script setup>
import { RouterLink } from 'vue-router'
import { formatCommentBody } from '../utils/commentBody'
import { commentAvatarUrl, commentDisplayName, commentInitials } from '../utils/commentDisplay'
import { formatDateTime } from '../utils/locale'
import { userProfilePath } from '../utils/user'

defineProps({
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
  replyToId: {
    type: [String, Number],
    default: null,
  },
  nested: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['reply'])

function canReply(item) {
  return item.source === 'site' || item.source === 'facebook'
}

function onReply(item) {
  emit('reply', item)
}
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
            v-if="!commentAvatarUrl(item)"
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
              <span v-if="item.source === 'facebook'" class="comment-row__badge">{{ t('facebookCommentBadge') }}</span>
              <time v-if="item.datetime" class="comment-row__time" :datetime="item.datetime">
                {{ formatDateTime(item.datetime, lang) }}
              </time>
            </div>
            <button
              v-if="canReply(item) && isAuthenticated"
              type="button"
              class="comment-row__reply-btn"
              :aria-pressed="replyToId === item.id"
              @click="onReply(item)"
            >
              {{ t('reply') }}
            </button>
          </header>
          <p class="comment-row__body">{{ formatCommentBody(item.body) }}</p>
        </div>
      </article>

      <PhotoCommentThread
        v-if="item.replies?.length"
        :threads="item.replies"
        :t="t"
        :lang="lang"
        :is-authenticated="isAuthenticated"
        :reply-to-id="replyToId"
        nested
        @reply="emit('reply', $event)"
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
  gap: 14px;

  &--nested {
    margin-top: 10px;
    margin-left: 20px;
    padding-left: 14px;
    border-left: 2px solid rgba(0, 0, 0, 0.08);
    gap: 12px;
  }
}

.comment-row {
  display: grid;
  grid-template-columns: 40px minmax(0, 1fr);
  gap: 10px 12px;
  align-items: start;

  &--facebook .comment-row__main {
    background: rgba(24, 119, 242, 0.06);
    border: 1px solid rgba(24, 119, 242, 0.12);
  }
}

.comment-row__avatar {
  width: 40px;
  height: 40px;
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
  font-size: 13px;
  font-weight: 700;
  color: #fff;
  background: linear-gradient(145deg, #6b7280, #4b5563);

  &--fb {
    background: linear-gradient(145deg, #3b82f6, #1877f2);
  }
}

.comment-row__main {
  min-width: 0;
  padding: 10px 12px;
  border-radius: 12px;
  background: rgba(0, 0, 0, 0.04);
}

.comment-row__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 4px;
}

.comment-row__meta {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 6px;
  min-width: 0;
}

.comment-row__author {
  font-weight: 700;
  font-size: 14px;
  color: inherit;
  text-decoration: none;

  &:hover {
    text-decoration: underline;
  }
}

.comment-row__badge {
  padding: 1px 6px;
  border-radius: 999px;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: #1877f2;
  background: rgba(24, 119, 242, 0.14);
}

.comment-row__time {
  font-size: 12px;
  color: #65676b;
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

  &[aria-pressed='true'] {
    text-decoration: underline;
  }
}

.comment-row__body {
  margin: 0;
  font-size: 14px;
  line-height: 1.45;
  white-space: pre-wrap;
  word-break: break-word;
}
</style>
