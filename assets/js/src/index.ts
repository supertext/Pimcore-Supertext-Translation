import { type AbstractModule, type IAbstractPlugin, container } from '@pimcore/studio-ui-bundle'
import { serviceIds } from '@pimcore/studio-ui-bundle/app'
import { componentConfig, type ComponentRegistry, type MainNavRegistry } from '@pimcore/studio-ui-bundle/modules/app'
import { type IconLibrary } from '@pimcore/studio-ui-bundle/modules/icon-library'
import { type WidgetRegistry } from '@pimcore/studio-ui-bundle/modules/widget-manager'
import { SupertextIcon } from './icons/supertext-icon'
import { DocumentTranslateButton, DataObjectTranslateButton } from './components/toolbar-buttons'
import { SettingsPage } from './components/settings-page'

/** Adds "Translate with Supertext" to the document and data object editor toolbars. */
const SupertextToolbarModule: AbstractModule = {
  onInit: (): void => {
    const registry = container.get<ComponentRegistry>(serviceIds['App/ComponentRegistry/ComponentRegistry'])
    registry.registerToSlot(componentConfig.document.editor.toolbar.slots.left.name, {
      name: 'supertextTranslateDocument',
      component: DocumentTranslateButton,
      priority: 900
    })
    registry.registerToSlot(componentConfig.dataObject.editor.toolbar.slots.left.name, {
      name: 'supertextTranslateDataObject',
      component: DataObjectTranslateButton,
      priority: 900
    })
  }
}

/** Supertext → Settings in the main navigation: version, API key and the language mapping. */
const SupertextSettingsModule: AbstractModule = {
  onInit: (): void => {
    container.get<IconLibrary>(serviceIds.iconLibrary).register({ name: 'supertext-globe', component: SupertextIcon })
    const mainNav = container.get<MainNavRegistry>(serviceIds.mainNavRegistry)
    mainNav.registerMainNavItem({
      path: 'Supertext',
      label: 'supertext.nav.group',
      icon: 'supertext-globe',
      order: 900
    })
    mainNav.registerMainNavItem({
      path: 'Supertext/Settings',
      label: 'supertext.nav.settings',
      icon: 'supertext-globe',
      permission: 'supertext_translate',
      widgetConfig: {
        name: 'Supertext settings',
        id: 'supertext-settings',
        component: 'supertext-settings',
        config: {
          translationKey: 'supertext.settings.title',
          icon: { type: 'name', value: 'supertext-globe' }
        }
      }
    })
    container.get<WidgetRegistry>(serviceIds.widgetManager).registerWidget({
      name: 'supertext-settings',
      component: SettingsPage
    })
  }
}

export const SupertextPlugin: IAbstractPlugin = {
  name: 'SupertextPlugin',

  onStartup ({ moduleSystem }) {
    moduleSystem.registerModule(SupertextToolbarModule)
    moduleSystem.registerModule(SupertextSettingsModule)
  }
}
