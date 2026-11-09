import React, { useEffect, useState } from 'react'
import { Alert, Button, Checkbox, Content, Header, Select, Space, Text } from '@pimcore/studio-ui-bundle/components'
import { useTranslation } from '@pimcore/studio-ui-bundle/app'
import { type LanguageRow, type ServerMessage, type SettingsInfo, loadSettings, saveLanguages } from '../api'

interface Choice { code: string, politeness: string, review: boolean }

const styles = `
.supertext-settings table { border-collapse: collapse; }
.supertext-settings th, .supertext-settings td { padding: 6px 16px 6px 0; text-align: left; vertical-align: top; }
.supertext-settings-languages thead th { border-bottom: 1px solid rgba(0, 0, 0, 0.12); font-weight: 600; }
.supertext-settings-languages tbody td { border-bottom: 1px solid rgba(0, 0, 0, 0.06); }
.supertext-settings-status th { font-weight: 600; }
`

/** What the screen shows for a row: the saved code, else the suggestion (exact match or Supertext's default), else none. */
const initialChoice = (row: LanguageRow): Choice => ({
  code: row.saved !== '' ? row.saved : (row.suggested ?? ''),
  politeness: row.politeness,
  review: row.review
})

/**
 * Supertext → Settings: the installed version, the API key and address, and the language mapping, one
 * select of Supertext's target languages per Pimcore language (administrators can change it).
 */
export const SettingsPage = (): React.JSX.Element => {
  const { t, i18n } = useTranslation()
  const [info, setInfo] = useState<SettingsInfo | null>(null)
  const [choices, setChoices] = useState<Record<string, Choice>>({})
  const [error, setError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)
  const [busy, setBusy] = useState(false)

  const describe = (m: ServerMessage): string => {
    const id = `supertext.error.${m.key ?? ''}`
    return m.key !== undefined && m.key !== '' && i18n.exists(id) ? t(id, { ...m.params, interpolation: { escapeValue: false } }) : m.message
  }

  const show = (data: SettingsInfo): void => {
    setInfo(data)
    setChoices(Object.fromEntries(data.languages.map(row => [row.language, initialChoice(row)])))
  }

  useEffect(() => {
    loadSettings().then(show).catch((e: ServerMessage) => { setError(describe(e)) })
  }, [])

  if (info === null) {
    return (
      <Content loading={ error === null } padded>
        { error !== null && <Alert message={ error } showIcon type="error" /> }
      </Content>
    )
  }

  const editable = info.isAdmin
  const targets = new Map(info.targets.map(target => [target.code, target]))
  const options = info.targets.map(target => ({ value: target.code, label: `${target.localName} (${target.code})` }))
  const politenessOptions = [
    { value: '', label: t('supertext.settings.politeness-default') },
    { value: 'more', label: t('supertext.settings.politeness-more') },
    { value: 'less', label: t('supertext.settings.politeness-less') }
  ]

  const change = (language: string, update: Partial<Choice>): void => {
    setSaved(false)
    setChoices(current => {
      const next = { ...current[language], ...update }
      if (next.code === '' || targets.get(next.code)?.politeness !== true) {
        next.politeness = ''
      }
      return { ...current, [language]: next }
    })
  }

  const save = async (): Promise<void> => {
    setBusy(true)
    setError(null)
    try {
      show(await saveLanguages(choices))
      setSaved(true)
    } catch (e) {
      setError(describe(e as ServerMessage))
    } finally {
      setBusy(false)
    }
  }

  const release = info.releaseUrl !== null
    ? <a href={ info.releaseUrl } rel="noopener" target="_blank">{ t('supertext.settings.version', { version: info.version }) }</a>
    : t('supertext.settings.version', { version: info.version })

  return (
    <Content overflow={ { x: 'auto', y: 'auto' } } padded>
      <style>{ styles }</style>
      <Header title={ t('supertext.settings.title') } />
      <Space className="supertext-settings" direction="vertical" size="normal" style={ { maxWidth: 960, width: '100%' } }>
        <Text type="secondary">{ release }</Text>

        <table className="supertext-settings-status">
          <tbody>
            <tr>
              <th scope="row">{ t('supertext.settings.api-key') }</th>
              <td>
                <div>{ info.configured ? t('supertext.settings.api-key-set') : t('supertext.not-configured') }</div>
                <Text type="secondary">
                  { info.configured ? `${t('supertext.settings.api-key-help')} ` : '' }
                  <a href={ info.signupUrl } rel="noopener" target="_blank">{ t('supertext.signup') }</a>{ ' · ' }
                  <a href={ info.apiKeyUrl } rel="noopener" target="_blank">{ t('supertext.api-key') }</a>
                </Text>
              </td>
            </tr>
            <tr>
              <th scope="row">{ t('supertext.settings.api-url') }</th>
              <td><code>{ info.apiUrl }</code></td>
            </tr>
          </tbody>
        </table>

        <div>
          <Header position="content" title={ t('supertext.settings.languages') } />
          <Text>{ t('supertext.settings.languages-help') }</Text>
          <div><Text type="secondary">{ t(info.reviewAvailable ? 'supertext.settings.review-help' : 'supertext.settings.review-off') }</Text></div>
        </div>

        { !editable && <Alert message={ t('supertext.settings.admin-only') } showIcon type="info" /> }

        <table className="supertext-settings-languages">
          <thead>
            <tr>
              <th scope="col">{ t('supertext.settings.language') }</th>
              <th scope="col">{ t('supertext.settings.supertext-language') }</th>
              <th scope="col">{ t('supertext.settings.politeness') }</th>
              <th scope="col">{ t('supertext.settings.review') }</th>
            </tr>
          </thead>
          <tbody>
            { info.languages.map(row => {
              const choice = choices[row.language] ?? initialChoice(row)
              const code = row.override ?? choice.code
              const tone = targets.get(code)?.politeness === true
              return (
                <tr key={ row.language }>
                  <td>{ row.name } <Text type="secondary">({ row.language })</Text></td>
                  <td>
                    <Select
                      aria-label={ `${t('supertext.settings.supertext-language')}: ${row.name}` }
                      disabled={ !editable || row.override !== null }
                      onChange={ (value: string | undefined) => { change(row.language, { code: value ?? '' }) } }
                      optionFilterProp="label"
                      options={ options }
                      placeholder={ t('supertext.settings.choose') }
                      showSearch
                      style={ { width: 300 } }
                      value={ code !== '' ? code : undefined }
                    />
                    { row.override !== null && <div><Text type="secondary">{ t('supertext.settings.override', { language: row.language }) }</Text></div> }
                  </td>
                  <td>
                    { tone
                      ? (
                        <Select
                          aria-label={ `${t('supertext.settings.politeness')}: ${row.name}` }
                          disabled={ !editable || row.politenessOverride }
                          onChange={ (value: string) => { change(row.language, { politeness: value }) } }
                          options={ politenessOptions }
                          style={ { width: 220 } }
                          value={ row.politenessOverride ? row.politeness : choice.politeness }
                        />
                        )
                      : <Text type="secondary">{ code !== '' ? t('supertext.settings.no-politeness') : '' }</Text> }
                  </td>
                  <td>
                    <Checkbox
                      aria-label={ `${t('supertext.settings.review')}: ${row.name}` }
                      checked={ info.reviewAvailable && (row.reviewOverride ? row.review : choice.review) }
                      disabled={ !editable || !info.reviewAvailable || row.reviewOverride }
                      onChange={ (e) => { change(row.language, { review: e.target.checked }) } }
                    />
                    { row.reviewOverride && <div><Text type="secondary">{ t('supertext.settings.override', { language: row.language }) }</Text></div> }
                  </td>
                </tr>
              )
            }) }
          </tbody>
        </table>

        { error !== null && <Alert message={ error } showIcon type="error" /> }
        { saved && <Alert message={ t('supertext.settings.saved') } showIcon type="success" /> }

        { editable && (
          <div>
            <Button loading={ busy } onClick={ () => { void save() } } type="primary">{ t('supertext.settings.save') }</Button>
          </div>
        ) }
      </Space>
    </Content>
  )
}
