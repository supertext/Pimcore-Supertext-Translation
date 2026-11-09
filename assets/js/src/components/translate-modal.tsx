import React, { useEffect, useMemo, useState } from 'react'
import { Alert, Button, Checkbox, Modal, Segmented, Select, Space, Spin, Tag, Text } from '@pimcore/studio-ui-bundle/components'
import { useTranslation } from '@pimcore/studio-ui-bundle/app'
import { useElementHelper, useElementRefresh } from '@pimcore/studio-ui-bundle/modules/element'
import { type ElementInfo, type ElementKind, type KeyCheck, type LanguageState, type ServerMessage, type TranslationResult, loadElement, translateElement } from '../api'
import { ExportPanel, ImportPanel } from './translation-files'

type Mode = 'translate' | 'export' | 'import'

interface Props {
  type: ElementKind
  id: number
  onClose: () => void
}

/** "Already translated" for documents = a linked translation exists; for objects = the language has text. */
const exists = (type: ElementKind, l: LanguageState): boolean =>
  type === 'document' ? l.documentId != null : l.hasContent === true

/** Languages without a Supertext code, or with a tone Supertext doesn't offer, can't be translated into. */
const supported = (l: LanguageState): boolean => l.supertext !== null && l.toneUnavailable !== true

const canTarget = (type: ElementKind, l: LanguageState): boolean =>
  supported(l) && (type === 'document' ? (l.allowed === true && l.parentReady === true) : l.editable === true)

/** Messages after which the account and API key links follow. */
const keyHelpAfter = ['auth-failed', 'no-api-key', 'not-configured']

export const TranslateModal = ({ type, id, onClose }: Props): React.JSX.Element => {
  const { t, i18n } = useTranslation()
  const { openElement } = useElementHelper()
  const { refreshElement } = useElementRefresh(type)
  const [info, setInfo] = useState<ElementInfo | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [source, setSource] = useState<string>('')
  const [targets, setTargets] = useState<string[]>([])
  const [overwrite, setOverwrite] = useState(false)
  const [busy, setBusy] = useState(false)
  const [results, setResults] = useState<TranslationResult[] | null>(null)
  // The editor is reloaded when the dialog closes: reloading it earlier would close the dialog
  // (it lives in the editor's toolbar) before the results are shown.
  const [changed, setChanged] = useState(false)
  // Translate with Supertext, Export for translation or Import translation.
  const [mode, setMode] = useState<Mode>('translate')

  /** The server's message in the user's language (English if its key has no translation). */
  const describe = (m: ServerMessage): string => {
    const id = `supertext.error.${m.key ?? ''}`
    if (m.key === undefined || m.key === '' || !i18n.exists(id)) {
      return m.message
    }
    const text = t(id, { ...m.params, interpolation: { escapeValue: false } }) +
      (m.detail !== undefined && m.detail !== '' ? ` (${m.detail})` : '')

    return keyHelpAfter.includes(m.key) ? `${text} ${t('supertext.error.key-help')}` : text
  }

  const close = (): void => {
    if (changed) {
      refreshElement(id, true)
    }
    onClose()
  }

  const load = async (keepSource?: string): Promise<void> => {
    try {
      const data = await loadElement(type, id)
      setInfo(data)
      const from = keepSource ?? data.source ?? data.languages.find(l => l.hasContent === true)?.language ?? data.languages[0]?.language ?? ''
      setSource(from)
      setTargets(data.languages.filter(l => l.language !== from && !exists(type, l) && canTarget(type, l)).map(l => l.language))
    } catch (e) {
      setError(describe(e as ServerMessage))
    }
  }

  useEffect(() => { void load() }, [type, id])

  const languages = info?.languages ?? []
  const names = useMemo(() => Object.fromEntries(languages.map(l => [l.language, l.name])), [languages])
  const replacing = targets.some(code => {
    const l = languages.find(x => x.language === code)
    return l !== undefined && exists(type, l)
  })

  const onSourceChange = (value: string): void => {
    setSource(value)
    setTargets(languages.filter(l => l.language !== value && !exists(type, l) && canTarget(type, l)).map(l => l.language))
  }

  const toggle = (code: string, checked: boolean): void => {
    setTargets(checked ? [...targets, code] : targets.filter(x => x !== code))
  }

  const submit = async (): Promise<void> => {
    setBusy(true)
    setError(null)
    setResults(null)
    try {
      const out = await translateElement(type, id, { source: type === 'data-object' ? source : undefined, targets, overwrite })
      setResults(out)
      setOverwrite(false)
      if (type === 'data-object' && out.some(r => r.status === 'translated')) {
        setChanged(true)
      }
      await load(source)
    } catch (e) {
      setError(describe(e as ServerMessage))
    } finally {
      setBusy(false)
    }
  }

  const date = (ts: number): string => new Date(ts * 1000).toLocaleDateString(i18n.language)

  const when = (iso: string): string => {
    const d = new Date(iso)
    return Number.isNaN(d.getTime()) ? iso : d.toLocaleString(i18n.language)
  }

  /** The key check in the user's language (the Commando Center's texts). */
  const checkText = (c: KeyCheck): string =>
    t(`supertext.keycheck.${c.status}`, { status: c.httpStatus ?? c.errorCode ?? '', interpolation: { escapeValue: false } })

  const marker = (l: LanguageState): React.JSX.Element | null => {
    if (l.lastTranslation != null) {
      return <Tag>{ t('supertext.translated-on', { date: date(l.lastTranslation), interpolation: { escapeValue: false } }) }</Tag>
    }
    if (l.supertext === null) {
      return <Tag className="supertext-not-mapped" color="orange">{ t('supertext.not-mapped') }</Tag>
    }
    if (l.toneUnavailable === true) {
      return <Tag color="orange">{ t('supertext.tone-unavailable') }</Tag>
    }
    if (exists(type, l)) {
      return <Tag>{ t('supertext.already-translated') }</Tag>
    }
    if (type === 'document' && l.parentReady === false) {
      return <Tag color="orange">{ t('supertext.parent-missing') }</Tag>
    }
    if (!canTarget(type, l)) {
      return <Tag color="orange">{ t('supertext.not-allowed') }</Tag>
    }
    return null
  }

  /** The source language: fixed for documents (their own language), chosen for data objects. */
  const fromBlock = (): React.JSX.Element => (
    <div>
      <Text strong>{ t('supertext.from') }</Text>
      <div>
        { type === 'document'
          ? <Text>{ names[source] ?? source } <Text type="secondary">({ source })</Text></Text>
          : (
            <Select
              className="supertext-source"
              onChange={ onSourceChange }
              options={ languages.filter(l => l.hasContent === true).map(l => ({ value: l.language, label: `${l.name} (${l.language})` })) }
              style={ { minWidth: 240 } }
              value={ source }
            />
            ) }
      </div>
    </div>
  )

  return (
    <Modal
      className="supertext-modal"
      footer={ results !== null || mode !== 'translate'
        ? <Button onClick={ close }>{ t('supertext.close') }</Button>
        : (
          <Space>
            <Button onClick={ close }>{ t('supertext.cancel') }</Button>
            <Button
              className="supertext-submit"
              disabled={ busy || info === null || !info.configured || targets.length === 0 || source === '' }
              loading={ busy }
              onClick={ () => { void submit() } }
              type="primary"
            >
              { busy ? t('supertext.translating') : t('supertext.translate') }
            </Button>
          </Space>
          ) }
      onCancel={ close }
      open
      size="ML"
      title={ t('supertext.title') }
    >
      { info === null && error === null && <Spin /> }
      { error !== null && <Alert className="supertext-error" message={ error } showIcon type="error" /> }

      { info !== null && !info.configured && (
        <Alert
          description={ (
            <span>
              { t('supertext.not-configured') }{ ' ' }
              <a href={ info.signupUrl } rel="noopener" target="_blank">{ t('supertext.signup') }</a>{ ' · ' }
              <a href={ info.apiKeyUrl } rel="noopener" target="_blank">{ t('supertext.api-key') }</a>
            </span>
          ) }
          message={ t('supertext.not-configured-title') }
          showIcon
          type="warning"
        />
      ) }

      { info !== null && results === null && (
        <Segmented
          className="supertext-mode"
          onChange={ (value) => { setMode(value as Mode) } }
          options={ [
            { value: 'translate', label: t('supertext.mode.translate') },
            { value: 'export', label: t('supertext.mode.export') },
            { value: 'import', label: t('supertext.mode.import') }
          ] }
          style={ { marginBottom: 16 } }
          value={ mode }
        />
      ) }

      { info !== null && results === null && mode === 'export' && (
        <Space direction="vertical" size="normal" style={ { width: '100%' } }>
          { fromBlock() }
          <ExportPanel describe={ describe } exists={ l => exists(type, l) } id={ id } key={ source } languages={ languages } source={ source } type={ type } />
        </Space>
      ) }

      { info !== null && results === null && mode === 'import' && (
        <ImportPanel describe={ describe } names={ names } onImported={ () => { setChanged(true) } } />
      ) }

      { results !== null && (
        <Space className="supertext-results" direction="vertical" style={ { width: '100%', marginBottom: 12 } }>
          { results.map(r => (
            <Alert
              action={ r.documentId !== undefined && r.status !== 'error'
                ? <Button onClick={ () => { close(); void openElement({ id: r.documentId!, type: 'document' }) } } size="small">{ t('supertext.open') }</Button>
                : undefined }
              key={ r.language }
              message={ `${names[r.language] ?? r.language}: ${
                r.status === 'translated'
                  ? (r.created === true ? t('supertext.result-created') : t('supertext.result-translated'))
                  : r.status === 'skipped' ? t('supertext.result-skipped') : describe(r)
              }` }
              showIcon
              type={ r.status === 'translated' ? 'success' : r.status === 'skipped' ? 'info' : 'error' }
            />
          )) }
          <Text type="secondary">{ t('supertext.review-hint') }</Text>
        </Space>
      ) }

      { info !== null && results === null && mode === 'translate' && (
        <Space direction="vertical" size="normal" style={ { width: '100%' } }>
          { fromBlock() }
          <div>
            <Text strong>{ t('supertext.into') }</Text>
            <div className="supertext-targets">
              { languages.filter(l => l.language !== source).map(l => (
                <div key={ l.language } style={ { display: 'flex', alignItems: 'center', gap: 8, margin: '6px 0' } }>
                  <Checkbox
                    checked={ targets.includes(l.language) }
                    disabled={ !canTarget(type, l) }
                    onChange={ (e) => { toggle(l.language, e.target.checked) } }
                    value={ l.language }
                  >
                    { l.name } <Text type="secondary">({ l.language })</Text>
                  </Checkbox>
                  { marker(l) }
                </div>
              )) }
            </div>
          </div>
          { replacing && (
            <Alert
              className="supertext-overwrite"
              description={ type === 'document' ? t('supertext.overwrite-help-document') : t('supertext.overwrite-help-object') }
              message={ (
                <Checkbox checked={ overwrite } className="supertext-overwrite-checkbox" onChange={ (e) => { setOverwrite(e.target.checked) } }>
                  { t('supertext.overwrite') }
                </Checkbox>
              ) }
              type="warning"
            />
          ) }
          <Text type="secondary">{ type === 'document' ? t('supertext.hint-document') : t('supertext.hint-object') }</Text>
        </Space>
      ) }

      { info?.status !== undefined && results === null && (
        <div className="supertext-status" style={ { marginTop: 16, paddingTop: 12, borderTop: '1px solid rgba(0, 0, 0, 0.06)' } }>
          <Text strong>{ t('supertext.status.title') }</Text>
          <div>
            <Text type="secondary">{ t('supertext.status.key-check') }: </Text>
            { info.status.check === null
              ? <Text>{ t('supertext.status.not-checked') }</Text>
              : (
                <>
                  <Text type={ info.status.check.status === 'ok' ? 'success' : 'danger' }>{ checkText(info.status.check) }</Text>
                  { (info.status.check.status === 'invalid' || info.status.check.status === 'missing') && (
                    <>
                      { ' ' }
                      <a href={ info.signupUrl } rel="noopener" target="_blank">{ t('supertext.signup') }</a>{ ' · ' }
                      <a href={ info.apiKeyUrl } rel="noopener" target="_blank">{ t('supertext.api-key') }</a>
                    </>
                  ) }
                  { ' ' }<Text type="secondary">({ t('supertext.status.checked-at', { time: when(info.status.check.checkedAt), interpolation: { escapeValue: false } }) })</Text>
                </>
                ) }
          </div>
          { languages.some(l => l.supertext === null) && (
            <div>
              <Text type="warning">
                { t('supertext.status.unmapped', { languages: languages.filter(l => l.supertext === null).map(l => l.language).join(', '), interpolation: { escapeValue: false } }) }
              </Text>
            </div>
          ) }
          <div>
            <Text type="secondary">{ t('supertext.status.reports') }: </Text>
            <Text>{ info.status.reports ? t('supertext.status.on') : t('supertext.status.off') }</Text>
            { info.status.reportsFromEnvironment && <Text type="secondary"> (SUPERTEXT_VERSION_REPORTS)</Text> }
          </div>
          <Text type="secondary" style={ { fontSize: 12 } }>{ t('supertext.status.reports-help') }</Text>
        </div>
      ) }
    </Modal>
  )
}
