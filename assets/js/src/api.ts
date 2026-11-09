import { getPrefix } from '@pimcore/studio-ui-bundle/api'

export type ElementKind = 'document' | 'data-object'

export interface LanguageState {
  language: string
  name: string
  // documents
  documentId?: number | null
  path?: string | null
  parentReady?: boolean
  allowed?: boolean
  // data objects
  hasContent?: boolean
  editable?: boolean
  lastTranslation: number | null
  /** The Supertext target code it is sent as; null: not mapped (can't be translated into). */
  supertext?: string | null
  /** A formal/informal tone is configured, but Supertext offers none for this language. */
  toneUnavailable?: boolean
}

/** The automatic API key check (Commando Center), latest result. */
export interface KeyCheck {
  status: 'ok' | 'invalid' | 'missing' | 'unreachable' | 'error'
  checkedAt: string
  httpStatus: number | null
  errorCode: string | null
}

/** Administrators only. */
export interface SupertextStatus {
  check: KeyCheck | null
  reports: boolean
  reportsFromEnvironment: boolean
}

export interface ElementInfo {
  type: ElementKind
  configured: boolean
  signupUrl: string
  apiKeyUrl: string
  isAdmin: boolean
  status?: SupertextStatus
  source?: string
  fields?: string[]
  languages: LanguageState[]
}

/** A server message: English `message`, plus `key` (supertext.error.<key>), `params` and Supertext's untranslated `detail` for the UI. */
export interface ServerMessage {
  message: string
  key?: string
  params?: Record<string, string | number>
  detail?: string
}

export class ApiError extends Error implements ServerMessage {
  constructor (message: string, readonly key?: string, readonly params?: Record<string, string | number>, readonly detail?: string) {
    super(message)
  }
}

export interface TranslationResult extends ServerMessage {
  language: string
  status: 'translated' | 'skipped' | 'error'
  documentId?: number
  path?: string
  created?: boolean
}

async function call<T> (path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(`${getPrefix()}/supertext${path}`, {
    credentials: 'include',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    ...init
  })
  const body = await response.json().catch(() => ({}))
  if (!response.ok) {
    throw new ApiError(
      typeof body?.error === 'string' ? body.error : (body?.message ?? `HTTP ${response.status}`),
      typeof body?.key === 'string' ? body.key : undefined,
      typeof body?.params === 'object' && body.params !== null ? body.params : undefined,
      typeof body?.detail === 'string' ? body.detail : undefined
    )
  }
  return body as T
}

export const loadElement = async (type: ElementKind, id: number): Promise<ElementInfo> =>
  await call<ElementInfo>(`/elements/${type}/${id}`)

export const translateElement = async (
  type: ElementKind, id: number, payload: { source?: string, targets: string[], overwrite: boolean }
): Promise<TranslationResult[]> =>
  (await call<{ results: TranslationResult[] }>(`/elements/${type}/${id}/translate`, {
    method: 'POST',
    body: JSON.stringify(payload)
  })).results

export type FileFormat = 'xliff' | 'html'

export type ImportCount = 'imported' | 'sourceChanged' | 'untranslated' | 'invalidMarkup' | 'missing' | 'kept'

/** One job of an imported translation file (one element in one language). */
export interface ImportedJob {
  reference: string
  type: ElementKind | ''
  id: number
  language: string
  error: ServerMessage | null
  counts: Record<ImportCount, number>
}

const failure = async (response: Response): Promise<ApiError> => {
  const body = await response.json().catch(() => ({}))
  return new ApiError(
    typeof body?.error === 'string' ? body.error : (body?.message ?? `HTTP ${response.status}`),
    typeof body?.key === 'string' ? body.key : undefined,
    typeof body?.params === 'object' && body.params !== null ? body.params : undefined,
    typeof body?.detail === 'string' ? body.detail : undefined
  )
}

/** Export for translation: downloads the file and returns its name. */
export const exportElement = async (
  type: ElementKind, id: number, payload: { source?: string, targets: string[], format: FileFormat, overwrite: boolean }
): Promise<string> => {
  const query = new URLSearchParams({ targets: payload.targets.join(','), format: payload.format, overwrite: payload.overwrite ? '1' : '0' })
  if (payload.source !== undefined) {
    query.set('source', payload.source)
  }
  const response = await fetch(`${getPrefix()}/supertext/elements/${type}/${id}/export?${query.toString()}`, { credentials: 'include' })
  if (!response.ok) {
    throw await failure(response)
  }
  const name = /filename="([^"]+)"/.exec(response.headers.get('Content-Disposition') ?? '')?.[1] ?? `pimcore-${type}-${id}.xlf`
  const url = URL.createObjectURL(await response.blob())
  const link = document.createElement('a')
  link.href = url
  link.download = name
  document.body.appendChild(link)
  link.click()
  link.remove()
  setTimeout(() => { URL.revokeObjectURL(url) }, 1000)
  return name
}

/** Import translation: uploads one XLIFF or HTML file. */
export const importFile = async (file: File, options: { includeChanged: boolean, overwrite: boolean }): Promise<ImportedJob[]> => {
  const form = new FormData()
  form.append('file', file)
  form.append('includeChanged', options.includeChanged ? '1' : '0')
  form.append('overwrite', options.overwrite ? '1' : '0')
  const response = await fetch(`${getPrefix()}/supertext/import`, {
    method: 'POST',
    credentials: 'include',
    headers: { Accept: 'application/json', 'X-Supertext-Request': '1' },
    body: form
  })
  if (!response.ok) {
    throw await failure(response)
  }
  return (await response.json() as { jobs: ImportedJob[] }).jobs
}

/** One Pimcore language on Supertext → Settings. */
export interface LanguageRow {
  language: string
  name: string
  /** The code chosen on the screen ('' while none is saved). */
  saved: string
  /** The code set in the bundle configuration, which wins over the screen; null if none. */
  override: string | null
  /** Exact match or Supertext's default; null: the language needs a choice. */
  suggested: string | null
  /** What a translation sends; null: can't be translated into. */
  code: string | null
  politeness: string
  politenessOverride: boolean
  politenessSupported: boolean | null
  /** Translation review: translations into this language go through Supertext's review service. */
  review: boolean
  /** Set in the bundle configuration (`languages.<language>.review`), which wins. */
  reviewOverride: boolean
}

/** One of Supertext's target languages (the SDK's Languages::targetOptions()). */
export interface TargetOption {
  code: string
  name: string
  localName: string
  politeness: boolean
}

export interface SettingsInfo {
  version: string
  releaseUrl: string | null
  configured: boolean
  status: SupertextStatus | null
  apiKeySource: string
  apiUrl: string
  timeout: number
  /** false: review_url is empty, Translation review is off for every language. */
  reviewAvailable: boolean
  languages: LanguageRow[]
  targets: TargetOption[]
  signupUrl: string
  apiKeyUrl: string
  isAdmin: boolean
}

export const loadSettings = async (): Promise<SettingsInfo> => await call<SettingsInfo>('/settings')

export const saveLanguages = async (languages: Record<string, { code: string, politeness: string, review: boolean }>): Promise<SettingsInfo> =>
  await call<SettingsInfo>('/settings/languages', {
    method: 'POST',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Supertext-Request': '1' },
    body: JSON.stringify({ languages })
  })
