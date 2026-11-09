<?php

declare(strict_types=1);

namespace App\Command;

use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\Document;
use Pimcore\Model\User;
use Pimcore\Tool\Authentication;
use Supertext\PimcoreTranslationBundle\LanguageStore;
use Supertext\PimcoreTranslationBundle\Settings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Supertext demo setup, run on every start. Only adds what is missing: the Editors role, the
 * DEMO_ADMIN / DEMO_EDITOR accounts, the class "Article", the English pages, the sample
 * article object and the Supertext language mapping (Supertext → Settings). Existing accounts and content are never changed. Passwords are never printed.
 */
#[AsCommand(name: 'supertext:demo-setup', description: 'Creates what the Supertext demo needs if it is missing.')]
final class DemoSetupCommand extends Command
{
    private OutputInterface $out;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->out = $output;
        $this->ensureClass();
        $role = $this->ensureEditorsRole();
        $this->ensureAccounts($role);
        $this->ensureDocuments();
        $this->ensureObject();
        $this->ensureLanguageMapping();

        return Command::SUCCESS;
    }

    private function log(string $message): void
    {
        $this->out->writeln("[demo] {$message}");
    }

    /**
     * The mapping on Supertext → Settings, saved once: "en" as British English (Supertext's default for a
     * bare "en" would be en-US), the Swiss languages formal. Changes made on the screen are kept.
     */
    private function ensureLanguageMapping(): void
    {
        $store = new LanguageStore();
        if ($store->load() !== []) {
            return;
        }
        $store->save([
            'en' => ['code' => 'en-GB', 'politeness' => '', 'review' => false],
            'de_CH' => ['code' => 'de-CH', 'politeness' => 'more', 'review' => false],
            'fr_CH' => ['code' => 'fr-CH', 'politeness' => 'more', 'review' => false],
            'it_CH' => ['code' => 'it-CH', 'politeness' => 'more', 'review' => false],
        ]);
        $this->log('Supertext language mapping saved (Supertext → Settings)');
    }

    /** The class "Article" with localized name, summary (textarea) and body (WYSIWYG). */
    private function ensureClass(): void
    {
        if (ClassDefinition::getByName('Article')) {
            return;
        }
        $fields = [];
        foreach ([['name', 'Name', ClassDefinition\Data\Input::class], ['summary', 'Summary', ClassDefinition\Data\Textarea::class], ['body', 'Body', ClassDefinition\Data\Wysiwyg::class]] as [$name, $title, $type]) {
            $field = new $type();
            $field->setName($name);
            $field->setTitle($title);
            $fields[] = $field;
        }
        $localized = new ClassDefinition\Data\Localizedfields();
        $localized->setName('localizedfields');
        $localized->setChildren($fields);
        $panel = new ClassDefinition\Layout\Panel();
        $panel->setName('Article');
        $panel->setChildren([$localized]);
        $root = new ClassDefinition\Layout\Panel();
        $root->setName('pimcore_root');
        $root->setChildren([$panel]);

        $class = new ClassDefinition();
        $class->setId('article');
        $class->setName('Article');
        $class->setLayoutDefinitions($root);
        $class->save();
        $this->log('Class Article added.');
    }

    private function ensureEditorsRole(): User\Role
    {
        // "classes": Pimcore Studio 2026.3 only opens data objects for users with this permission.
        $permissions = ['documents', 'objects', 'classes', 'notes_events', Settings::PERMISSION];
        $role = User\Role::getByName('Editors');
        if ($role instanceof User\Role) {
            $missing = array_diff($permissions, $role->getPermissions());
            if ($missing !== []) {
                $role->setPermissions(array_values(array_unique([...$role->getPermissions(), ...$missing])));
                $role->save();
                $this->log('Role Editors: added permissions ' . implode(', ', $missing) . '.');
            }

            return $role;
        }
        $role = User\Role::create(['parentId' => 0, 'name' => 'Editors']);
        $role->setPermissions($permissions);
        $workspace = ['cpath' => '/', 'list' => true, 'view' => true, 'save' => true, 'publish' => true, 'unpublish' => true,
            'delete' => false, 'rename' => true, 'create' => true, 'settings' => true, 'versions' => true, 'properties' => true];
        $documents = new User\Workspace\Document();
        $documents->setValues($workspace + ['cid' => 1]);
        $objects = new User\Workspace\DataObject();
        $objects->setValues($workspace + ['cid' => 1, 'lEdit' => null, 'lView' => null, 'layouts' => null]);
        $role->setWorkspacesDocument([$documents]);
        $role->setWorkspacesObject([$objects]);
        $role->save();
        $this->log('Role Editors added.');

        return $role;
    }

    private function ensureAccounts(User\Role $editors): void
    {
        foreach (['DEMO_ADMIN', 'DEMO_EDITOR'] as $prefix) {
            $email = trim((string) getenv("{$prefix}_EMAIL"));
            $password = (string) getenv("{$prefix}_PASSWORD");
            if ($email === '' || $password === '') {
                $this->log("{$prefix}_EMAIL / {$prefix}_PASSWORD not set; skipping that account.");
                continue;
            }
            // Pimcore's own rule (installer): user name and password of at least 4 characters.
            if (mb_strlen($email) < 4 || mb_strlen($password) < 4) {
                $this->log("WARNING: {$prefix}_EMAIL / {$prefix}_PASSWORD must have at least 4 characters (Pimcore's rule); skipping that account.");
                continue;
            }
            if (User::getByName($email) instanceof User) {
                $this->log("{$prefix}: account exists, left unchanged.");
                continue;
            }
            $user = User::create([
                'parentId' => 0,
                'name' => $email,
                'email' => $email,
                'firstname' => 'Demo',
                'lastname' => $prefix === 'DEMO_ADMIN' ? 'Admin' : 'Editor',
                'password' => Authentication::getPasswordHash($email, $password),
                'active' => true,
                'admin' => $prefix === 'DEMO_ADMIN',
                'language' => 'en',
            ]);
            if ($prefix === 'DEMO_EDITOR') {
                $user->setRoles([$editors->getId()]);
                $user->save();
            }
            $this->log("{$prefix}: account created.");
        }
    }

    private function ensureDocuments(): void
    {
        if (Document::getByPath('/en')) {
            return;
        }
        $home = $this->page(1, 'en', 'Welcome', 'A Pimcore demo site translated with Supertext.', 'Welcome', [
            'headline' => ['input', 'Welcome'],
            'intro' => ['textarea', 'A small demo site with one product page.'],
            'content' => ['wysiwyg', '<p>This site was written in <strong>English</strong>. Open a page in Pimcore Studio and use the Supertext button in the toolbar to create the German, French and Italian versions.</p>'],
        ]);
        $this->page($home->getId(), 'swiss-chocolate', 'Swiss chocolate, shipped worldwide',
            'How a small family business in Bern brings handmade pralines to 40 countries.', 'Swiss chocolate', [
                'headline' => ['input', 'From Bern to the world'],
                'intro' => ['textarea', 'Every praline is filled and decorated by hand.'],
                'content' => ['wysiwyg', '<p>Every praline is made by hand in our <strong>Bern</strong> workshop. Read more on <a href="https://www.supertext.com">our website</a>.</p><ul><li>Fresh ingredients from local farmers</li><li>Climate-neutral delivery within 48 hours</li></ul>'],
                'cta' => ['link', ['text' => 'Order a tasting box', 'path' => 'https://www.supertext.com', 'linktype' => 'direct']],
            ]);
        $this->log('Sample pages added (English).');
    }

    private function page(int $parentId, string $key, string $title, string $description, string $navigation, array $editables): Document\Page
    {
        $page = new Document\Page();
        $page->setParentId($parentId);
        $page->setKey($key);
        $page->setTitle($title);
        $page->setDescription($description);
        $page->setController('App\Controller\DefaultController::defaultAction');
        $page->setTemplate('default/default.html.twig');
        $page->setProperty('language', 'text', 'en', false, true);
        $page->setProperty('navigation_name', 'text', $navigation, false, false);
        foreach ($editables as $name => [$type, $data]) {
            $page->setRawEditable($name, $type, $data);
        }
        $page->setPublished(true);
        $page->save();

        return $page;
    }

    private function ensureObject(): void
    {
        if (DataObject::getByPath('/Articles/swiss-chocolate')) {
            return;
        }
        $folder = DataObject\Service::createFolderByPath('/Articles');
        $class = '\\Pimcore\\Model\\DataObject\\Article';
        $object = new $class();
        $object->setParentId($folder->getId());
        $object->setKey('swiss-chocolate');
        $object->setName('Swiss chocolate, shipped worldwide', 'en');
        $object->setSummary('How a small family business in Bern brings handmade pralines to 40 countries.', 'en');
        $object->setBody('<p>Every praline is made by hand in our <strong>Bern</strong> workshop.</p><ul><li>Fresh ingredients from local farmers</li><li>Climate-neutral delivery within 48 hours</li></ul>', 'en');
        $object->setPublished(true);
        $object->save();
        $this->log('Sample article object added (English).');
    }
}
