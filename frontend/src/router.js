import { createRouter, createWebHistory } from 'vue-router'
import HomeView from './views/HomeView.vue'
import PhotosView from './views/PhotosView.vue'
import PhotoDetailView from './views/PhotoDetailView.vue'
import PhotoUploadView from './views/PhotoUploadView.vue'
import NewsView from './views/NewsView.vue'
import NewsDetailView from './views/NewsDetailView.vue'
import PageView from './views/PageView.vue'
import ProfileView from './views/ProfileView.vue'
import AdminView from './views/AdminView.vue'
import RandomPhotoView from './views/RandomPhotoView.vue'
import UserProfileView from './views/UserProfileView.vue'
import { api, getToken } from './api'
import { applyRouteMeta } from './utils/seo'
import { isAdminUser } from './utils/user'

const routes = [
  { path: '/', name: 'home', component: HomeView, meta: { titleKey: 'pageTitleHome' } },
  { path: '/photos', name: 'photos', component: PhotosView, meta: { titleKey: 'pageTitlePhotos' } },
  {
    path: '/photos/add',
    name: 'photo-upload',
    component: PhotoUploadView,
    meta: { requiresAuth: true, titleKey: 'pageTitlePhotoAdd', noindex: true },
  },
  { path: '/photos/random', name: 'random-photo', component: RandomPhotoView, meta: { titleKey: 'pageTitlePhotoRandom' } },
  { path: '/photos/:id', name: 'photo-detail', component: PhotoDetailView },
  { path: '/news', name: 'news', component: NewsView, meta: { titleKey: 'pageTitleNews' } },
  { path: '/news/:id', name: 'news-detail', component: NewsDetailView },
  { path: '/pages/:alias', name: 'page', component: PageView },
  {
    path: '/profile',
    name: 'profile',
    component: ProfileView,
    meta: { requiresAuth: true, titleKey: 'pageTitleProfile', noindex: true },
  },
  { path: '/users/:unique', name: 'user-profile', component: UserProfileView },
  {
    path: '/admin',
    name: 'admin',
    component: AdminView,
    meta: { requiresAuth: true, requiresAdmin: true, titleKey: 'pageTitleAdmin', noindex: true },
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior() {
    return { top: 0 }
  },
})

router.beforeEach(async (to, from) => {
  if (to.meta.requiresAuth && !getToken()) {
    window.dispatchEvent(
      new CustomEvent('hinyerevan:open-auth', {
        detail: { mode: 'login', redirect: to.fullPath },
      }),
    )

    return from.matched.length ? false : '/'
  }

  if (to.meta.requiresAdmin) {
    if (!getToken()) return '/'

    try {
      const me = await api('/auth/me')
      if (!isAdminUser(me)) return '/'
    } catch {
      return '/'
    }
  }
})

router.afterEach((to) => {
  applyRouteMeta(to)
})

export default router
