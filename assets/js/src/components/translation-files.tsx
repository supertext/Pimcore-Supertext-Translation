import React, { useState } from 'react'
import { Alert, Button, Checkbox, Select, Space, Text } from '@pimcore/studio-ui-bundle/components'
import { useTranslation } from '@pimcore/studio-ui-bundle/app'
import { type ElementKind, type FileFormat, type ImportCount, type ImportedJob, type LanguageState, type ServerMessage, exportElement, importFile } from '../api'

/** Files larger than this are refused before uploading (the server checks again). */
const MAX_BYTES = 10_000_000

interface ExportProps {
  type: ElementKind
  id: number
  source: string
  languages: LanguageState[]
  /** "Already translated" for a language: only exported with overwrite. */
  exists: (l: LanguageState) => boolean
  describe: (m: ServerMessage) => string
}

/** Export for translation: format, target languages, download. */
export const ExportPanel = ({ type, id, source, languages, exists, describe }: ExportProps): React.JSX.Element => {
  const { t } = useTranslation()
  const others = languages.filter(l => l.language !== source)
  const [format, setFormat] = useState<FileFormat>('xliff')
  const [targets, setTargets] = useState<string[]>(others.filter(l => !exists(l)).map(l => l.language))
  const [overwrite, setOverwrite] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [done, setDone] = useState<string | null>(null)

  const toggle = (code: string, checked: boolean): void => {
    const next = checked ? [...targets, code] : targets.filter(x => x !== code)
    // HTML holds one language: the newest choice replaces the others.
    setTargets(format === 'html' && checked ? [code] : next)
  }

  const onFormat = (value: FileFormat): void => {
    setFormat(value)
    if (value === 'html' && targets.length > 1) {
      setTargets(targets.slice(0, 1))
    }
  }

  const download = async (): Promise<void> => {
    setBusy(true)
    setError(null)
    setDone(null)
    try {
      const name = await exportElement(type, id, { source: type === 'data-object' ? source : undefined, targets, format, overwrite })
      setDone(t('supertext.file.downloaded', { file: name, interpolation: { escapeValue: false } }))
    } catch (e) {
      setError(describe(e as ServerMessage))
    } finally {
      setBusy(false)
    }
  }

  const replacing = targets.some(code => { const l = languages.find(x => x.language === code); return l !== undefined && exists(l) })

  return (
    <Space className="supertext-export" direction="vertical" size="normal" style={ { width: '100%' } }>
      { error !== null && <Alert className="supertext-error" message={ error } showIcon type="error" /> }
      { done !== null && <Alert className="supertext-downloaded" message={ done } showIcon type="success" /> }
      <div>
        <Text strong>{ t('supertext.file.format') }</Text>
        <div>
          <Select
            className="supertext-format"
            onChange={ onFormat }
            options={ [{ value: 'xliff', label: t('supertext.file.xliff') }, { value: 'html', label: t('supertext.file.html') }] }
            style={ { minWidth: 320 } }
            value={ format }
          />
        </div>
      </div>
      <div>
        <Text strong>{ t('supertext.into') }</Text>
        <div className="supertext-export-targets">
          { others.map(l => (
            <div key={ l.language } style={ { margin: '6px 0' } }>
              <Checkbox checked={ targets.includes(l.language) } onChange={ (e) => { toggle(l.language, e.target.checked) } } value={ l.language }>
                { l.name } <Text type="secondary">({ l.language })</Text>
              </Checkbox>
            </div>
          )) }
        </div>
      </div>
      { replacing && (
        <Checkbox checked={ overwrite } className="supertext-export-overwrite" onChange={ (e) => { setOverwrite(e.target.checked) } }>
          { t('supertext.overwrite') }
        </Checkbox>
      ) }
      <Text type="secondary">{ t('supertext.file.export-hint') }</Text>
      <Button className="supertext-download" disabled={ busy || targets.length === 0 } loading={ busy } onClick={ () => { void download() } } type="primary">
        { t('supertext.file.download') }
      </Button>
    </Space>
  )
}

const COUNTS: Array<[ImportCount, string]> = [
  ['imported', 'supertext.file.imported'],
  ['sourceChanged', 'supertext.file.source-changed'],
  ['untranslated', 'supertext.file.untranslated'],
  ['invalidMarkup', 'supertext.file.invalid-markup'],
  ['missing', 'supertext.file.missing'],
  ['kept', 'supertext.file.kept']
]

interface ImportProps {
  describe: (m: ServerMessage) => string
  names: Record<string, string>
  /** Called when something was written, so the editor reloads when the dialog closes. */
  onImported: () => void
}

/** Import translation: file, options, summary per element and language. */
export const ImportPanel = ({ describe, names, onImported }: ImportProps): React.JSX.Element => {
  const { t } = useTranslation()
  const [file, setFile] = useState<File | null>(null)
  const [includeChanged, setIncludeChanged] = useState(false)
  const [overwrite, setOverwrite] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [jobs, setJobs] = useState<ImportedJob[] | null>(null)

  const submit = async (): Promise<void> => {
    if (file === null) {
      return
    }
    setError(null)
    setJobs(null)
    if (file.size > MAX_BYTES) {
      setError(t('supertext.error.file-too-large'))
      return
    }
    setBusy(true)
    try {
      const out = await importFile(file, { includeChanged, overwrite })
      setJobs(out)
      if (out.some(j => j.counts.imported > 0)) {
        onImported()
      }
    } catch (e) {
      setError(describe(e as ServerMessage))
    } finally {
      setBusy(false)
    }
  }

  const title = (job: ImportedJob): string => {
    if (job.type === '') {
      return job.reference
    }
    const element = t(`supertext.file.${job.type}`, { id: job.id, interpolation: { escapeValue: false } })
    return `${element}, ${names[job.language] ?? job.language} (${job.language})`
  }

  return (
    <Space className="supertext-import" direction="vertical" size="normal" style={ { width: '100%' } }>
      { error !== null && <Alert className="supertext-error" message={ error } showIcon type="error" /> }
      { jobs?.map(job => (
        <Alert
          className="supertext-import-result"
          description={ job.error === null
            ? (
              <ul style={ { margin: 0, paddingLeft: 18 } }>
                { COUNTS.filter(([count]) => count === 'imported' || job.counts[count] > 0).map(([count, key]) => (
                  <li key={ count }>{ t(key, { count: job.counts[count], interpolation: { escapeValue: false } }) }</li>
                )) }
              </ul>
              )
            : describe(job.error) }
          key={ job.reference }
          message={ title(job) }
          showIcon
          type={ job.error !== null ? 'error' : job.counts.imported > 0 ? 'success' : 'info' }
        />
      )) }
      <label>
        <Text strong>{ t('supertext.file.field') }</Text>
        <div>
          <input
            accept=".xlf,.xliff,.html,.htm,application/x-xliff+xml,text/html"
            className="supertext-file"
            onChange={ (e) => { setFile(e.target.files?.[0] ?? null) } }
            type="file"
          />
        </div>
      </label>
      <Checkbox checked={ includeChanged } className="supertext-include-changed" onChange={ (e) => { setIncludeChanged(e.target.checked) } }>
        { t('supertext.file.include-changed') }
      </Checkbox>
      <Checkbox checked={ overwrite } className="supertext-import-overwrite" onChange={ (e) => { setOverwrite(e.target.checked) } }>
        { t('supertext.overwrite') }
      </Checkbox>
      <Text type="secondary">{ t('supertext.file.import-hint') }</Text>
      <Button className="supertext-import-submit" disabled={ busy || file === null } loading={ busy } onClick={ () => { void submit() } } type="primary">
        { busy ? t('supertext.file.importing') : t('supertext.file.import') }
      </Button>
    </Space>
  )
}
