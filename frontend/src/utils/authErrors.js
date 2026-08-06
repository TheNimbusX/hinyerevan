const FIELD_PRIORITY = [
  'login',
  'password',
  'uid',
  'email',
  'code',
  'recaptcha_token',
  'first_name',
  'last_name',
  'password_confirmation',
  'current_password',
]

const ENGLISH_FALLBACKS = {
  'Invalid credentials.': 'authInvalidCredentials',
  'The given data was invalid.': 'authRequestInvalid',
  'Request failed': 'authRequestFailed',
  'This account is blocked.': 'authAccountBlocked',
  'The uid has already been taken.': 'authUsernameTaken',
  'The email has already been taken.': 'authEmailTaken',
  'The password field confirmation does not match.': 'authPasswordMismatch',
  'The password field must be at least 6 characters.': 'authPasswordTooShort',
  'The uid field must only contain letters and numbers.': 'authUsernameInvalid',
  'The uid field must be at least 3 characters.': 'authUsernameTooShort',
  'The first name field must be at least 3 characters.': 'authFirstNameTooShort',
  'The last name field must be at least 3 characters.': 'authLastNameTooShort',
  'Current password is invalid.': 'authCurrentPasswordInvalid',
}

function pickValidationMessage(errors) {
  if (!errors || typeof errors !== 'object') return ''

  for (const field of FIELD_PRIORITY) {
    const message = errors[field]?.[0]
    if (typeof message === 'string' && message.trim() !== '') {
      return message.trim()
    }
  }

  for (const messages of Object.values(errors)) {
    const message = messages?.[0]
    if (typeof message === 'string' && message.trim() !== '') {
      return message.trim()
    }
  }

  return ''
}

export function formatAuthError(error, t) {
  if (!error) return t('authRequestFailed')

  if (error.name === 'AbortError' || error.message === 'Request timed out') {
    return t('authRequestTimeout')
  }

  const fromFields = pickValidationMessage(error.errors)
  if (fromFields) return fromFields

  const message = String(error.message || '').trim()
  if (!message) return t('authRequestFailed')

  const i18nKey = ENGLISH_FALLBACKS[message]
  if (i18nKey) return t(i18nKey)

  if (message === 'Request failed' || message === 'The given data was invalid.') {
    return t('authRequestInvalid')
  }

  return message
}
