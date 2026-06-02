let sdkPromise = null

export function loadFacebookSdk(appId, locale = 'ru_RU') {
  if (typeof window === 'undefined' || !appId) {
    return Promise.resolve(false)
  }

  if (window.FB) {
    return Promise.resolve(true)
  }

  if (sdkPromise) {
    return sdkPromise
  }

  sdkPromise = new Promise((resolve) => {
    window.fbAsyncInit = function fbAsyncInit() {
      window.FB.init({
        appId,
        xfbml: true,
        version: 'v19.0',
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

export function parseFacebookXfbml() {
  if (window.FB?.XFBML?.parse) {
    window.FB.XFBML.parse()
  }
}
