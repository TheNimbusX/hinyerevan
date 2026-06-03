let sdkPromise = null
let sdkAppId = null

export function loadFacebookSdk(appId, locale = 'ru_RU') {
  if (typeof window === 'undefined' || !appId) {
    return Promise.resolve(false)
  }

  if (window.FB && sdkAppId === appId) {
    return Promise.resolve(true)
  }

  if (sdkPromise && sdkAppId === appId) {
    return sdkPromise
  }

  sdkAppId = appId
  sdkPromise = null

  sdkPromise = new Promise((resolve) => {
    window.fbAsyncInit = function fbAsyncInit() {
      window.FB.init({
        appId,
        cookie: false,
        xfbml: true,
        version: 'v22.0',
      })
      resolve(true)
    }

    if (document.getElementById('facebook-jssdk')) {
      const wait = setInterval(() => {
        if (window.FB) {
          clearInterval(wait)
          resolve(true)
        }
      }, 100)
      return
    }

    const script = document.createElement('script')
    script.id = 'facebook-jssdk'
    script.async = true
    script.defer = true
    script.crossOrigin = 'anonymous'
    script.src = `https://connect.facebook.net/${locale}/sdk.js`
    script.onerror = () => resolve(false)
    document.body.appendChild(script)
  })

  return sdkPromise
}

export function parseFacebookXfbml(root) {
  if (window.FB?.XFBML?.parse) {
    window.FB.XFBML.parse(root || undefined)
  }
}
