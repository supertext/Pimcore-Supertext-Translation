<?php

declare(strict_types=1);

namespace Supertext\PimcoreTranslationBundle\Controller;

use Pimcore\Bundle\StudioBackendBundle\Controller\AbstractApiController;
use Pimcore\Bundle\StudioBackendBundle\Security\Service\SecurityServiceInterface;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\Document;
use Pimcore\Model\Document\PageSnippet;
use Pimcore\Model\User;
use Pimcore\Tool;
use Psr\Log\LoggerInterface;
use Supertext\PimcoreTranslationBundle\Api\FileExchange;
use Supertext\PimcoreTranslationBundle\Api\InstallationReports;
use Supertext\PimcoreTranslationBundle\Api\LanguageCodes;
use Supertext\PimcoreTranslationBundle\Api\LanguageMapping;
use Supertext\PimcoreTranslationBundle\Api\SupertextException;
use Supertext\PimcoreTranslationBundle\Reporting\Reporting;
use Supertext\PimcoreTranslationBundle\Service\DocumentTranslator;
use Supertext\PimcoreTranslationBundle\Service\ObjectTranslator;
use Supertext\PimcoreTranslationBundle\LanguageStore;
use Supertext\PimcoreTranslationBundle\Sdk\V1\Languages;
use Supertext\PimcoreTranslationBundle\Service\TranslationFileElements;
use Supertext\PimcoreTranslationBundle\Settings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Studio API endpoints (behind Studio's login, under /pimcore-studio/api):
 *
 *   GET  /supertext/elements/{type}/{id}             languages and their state for the translate dialog
 *   POST /supertext/elements/{type}/{id}/translate   {source?, targets[], overwrite}
 *   GET  /supertext/elements/{type}/{id}/export      ?targets=de,fr&format=xliff|html&source=&overwrite=  (Export for translation: the file)
 *   POST /supertext/import                           multipart file, includeChanged, overwrite (Import translation)
 *   GET  /supertext/settings                         API key status and check, version reports, API address, languages and Supertext's targets
 *   POST /supertext/settings/languages               {languages: {<language>: {code, politeness, review}}} the language mapping (admins)
 *   POST /supertext/settings/test                    checks the API key, cost-free (admins)
 *
 * {type} is "document" or "data-object". Every endpoint queues the Commando Center's onRequest for
 * after the response (src/Reporting/Reporting.php); the ping route is public, in src/Ping/.
 */
final class SupertextController extends AbstractApiController
{
    public function __construct(
        SerializerInterface $serializer,
        private readonly SecurityServiceInterface $security,
        private readonly Settings $settings,
        private readonly ObjectTranslator $objects,
        private readonly DocumentTranslator $documents,
        private readonly LoggerInterface $logger,
        private readonly Reporting $reporting,
        private readonly LanguageStore $languageStore,
    ) {
        parent::__construct($serializer);
    }

    #[Route('/supertext/elements/{type}/{id}', name: 'supertext_translation_element', requirements: ['type' => 'document|data-object', 'id' => '\d+'], methods: ['GET'])]
    public function element(string $type, int $id): JsonResponse
    {
        $user = $this->user();
        if (!$user->isAllowed(Settings::PERMISSION)) {
            return $this->error('You are not allowed to translate with Supertext.', 403, 'not-allowed');
        }
        $element = $this->load($type, $id);
        if (!$element || !$element->isAllowed('view', $user)) {
            return $this->error('Not found.', 404, 'element-not-found');
        }

        $this->reporting->later(InstallationReports::ON_REQUEST);
        $common = [
            'configured' => $this->settings->apiKey() !== '',
            'signupUrl' => Settings::SIGNUP_URL,
            'apiKeyUrl' => Settings::API_KEY_URL,
            'isAdmin' => $user->isAdmin(),
        ];
        if ($user->isAdmin()) {
            $common['status'] = $this->status();
        }
        if ($element instanceof PageSnippet) {
            $info = $this->documents->describe($element, $user);

            return new JsonResponse($common + ['type' => 'document', 'source' => $info['source'], 'languages' => $this->withCodes($info['languages'])]);
        }

        return new JsonResponse($common + ['type' => 'data-object', 'fields' => array_keys($this->objects->fields($element)), 'languages' => $this->withCodes($this->objects->describe($element, $user))]);
    }

    #[Route('/supertext/elements/{type}/{id}/translate', name: 'supertext_translation_translate', requirements: ['type' => 'document|data-object', 'id' => '\d+'], methods: ['POST'])]
    public function translate(string $type, int $id, Request $request): JsonResponse
    {
        $user = $this->user();
        if (!$user->isAllowed(Settings::PERMISSION)) {
            return $this->error('You are not allowed to translate with Supertext.', 403, 'not-allowed');
        }
        $element = $this->load($type, $id);
        if (!$element || !$element->isAllowed('view', $user)) {
            return $this->error('Not found.', 404, 'element-not-found');
        }
        $payload = json_decode($request->getContent() ?: '[]', true) ?: [];
        $targets = array_values(array_filter((array) ($payload['targets'] ?? []), 'is_string'));
        if ($targets === []) {
            return $this->error('Choose at least one language to translate into.', 400, 'choose-target');
        }
        $overwrite = (bool) ($payload['overwrite'] ?? false);
        $this->reporting->later(InstallationReports::ON_REQUEST);
        @set_time_limit(max(300, $this->settings->timeout() * (\count($targets) + 1)));

        try {
            $results = $element instanceof PageSnippet
                ? $this->documents->translate($element, $targets, $overwrite, $user)
                : $this->objects->translate($element, (string) ($payload['source'] ?? ''), $targets, $overwrite, $user);
        } catch (SupertextException $e) {
            $this->logger->warning('Supertext translation failed: ' . $e->getMessage(), ['type' => $type, 'id' => $id]);

            return new JsonResponse(['error' => $e->getMessage()] + $e->toArray(), 400);
        } catch (\Throwable $e) {
            $this->logger->error('Supertext translation failed: ' . $e->getMessage(), ['exception' => $e]);

            return $this->error($e->getMessage(), 500);
        }

        foreach ($results as $result) {
            if ($result['status'] === 'error') {
                $this->logger->warning(sprintf('Supertext translation into %s failed: %s', $result['language'], $result['message']), ['type' => $type, 'id' => $id]);
            }
        }

        return new JsonResponse(['results' => $results]);
    }

    /** Export for translation: the element's texts as an XLIFF 1.2 or HTML file (nothing is stored). */
    #[Route('/supertext/elements/{type}/{id}/export', name: 'supertext_translation_export', requirements: ['type' => 'document|data-object', 'id' => '\d+'], methods: ['GET'])]
    public function export(string $type, int $id, Request $request): Response
    {
        $user = $this->user();
        if (!$user->isAllowed(Settings::PERMISSION)) {
            return $this->error('You are not allowed to translate with Supertext.', 403, 'not-allowed');
        }
        $targets = array_values(array_filter(array_map('trim', explode(',', (string) $request->query->get('targets', '')))));
        $exchange = new FileExchange(new TranslationFileElements($this->documents, $this->objects, $user), $this->settings->mapping());

        try {
            $file = $exchange->export(
                $type === 'document' ? FileExchange::DOCUMENT : FileExchange::OBJECT,
                $id,
                (string) $request->query->get('source', ''),
                $targets,
                (string) $request->query->get('format', FileExchange::XLIFF),
                \in_array((string) $request->query->get('overwrite', ''), ['1', 'true'], true),
            );
        } catch (SupertextException $e) {
            return new JsonResponse(['error' => $e->getMessage()] + $e->toArray(), $e->key === 'element-not-found' ? 404 : 400);
        }

        return new Response($file['content'], 200, [
            'Content-Type' => $file['contentType'],
            'Content-Disposition' => 'attachment; filename="' . $file['fileName'] . '"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Import translation: one XLIFF or HTML file (multipart field "file", at most 10 MB), read in
     * memory and written into the documents and objects it was exported from. The header
     * X-Supertext-Request is required, so another site can't submit a form here.
     */
    #[Route('/supertext/import', name: 'supertext_translation_import', methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        $user = $this->user();
        if (!$user->isAllowed(Settings::PERMISSION)) {
            return $this->error('You are not allowed to translate with Supertext.', 403, 'not-allowed');
        }
        if ($request->headers->get('X-Supertext-Request') !== '1') {
            return $this->error('Missing X-Supertext-Request header.', 400);
        }
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || $file->getError() === \UPLOAD_ERR_NO_FILE) {
            return $this->error('Choose a file to import.', 400, 'file-no-file');
        }
        if (\in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true) || $file->getSize() > FileExchange::MAX_UPLOAD_BYTES) {
            return $this->error('The file is too large (at most 10 MB).', 400, 'file-too-large');
        }
        if (!$file->isValid()) {
            return $this->error('The file could not be uploaded.', 400, 'file-invalid-file');
        }
        $this->reporting->later(InstallationReports::ON_REQUEST);
        $exchange = new FileExchange(new TranslationFileElements($this->documents, $this->objects, $user), $this->settings->mapping());

        try {
            $jobs = $exchange->import(
                (string) file_get_contents($file->getPathname(), false, null, 0, FileExchange::MAX_UPLOAD_BYTES + 1),
                $request->request->getBoolean('includeChanged'),
                $request->request->getBoolean('overwrite'),
            );
        } catch (SupertextException $e) {
            return new JsonResponse(['error' => $e->getMessage()] + $e->toArray(), 400);
        } catch (\Throwable $e) {
            $this->logger->error('Supertext translation file import failed: ' . $e->getMessage(), ['exception' => $e]);

            return $this->error($e->getMessage(), 500);
        }

        return new JsonResponse(['jobs' => array_map(static fn (array $job): array => ['type' => $job['type'] === FileExchange::OBJECT ? 'data-object' : $job['type']] + $job, $jobs)]);
    }

    #[Route('/supertext/settings', name: 'supertext_translation_settings', methods: ['GET'])]
    public function settings(): JsonResponse
    {
        $user = $this->user();
        if (!$user->isAllowed(Settings::PERMISSION) && !$user->isAdmin()) {
            return $this->error('You are not allowed to see the Supertext settings.', 403);
        }
        $this->reporting->later(InstallationReports::ON_REQUEST);

        return new JsonResponse($this->settingsData($user));
    }

    /**
     * Saves the language mapping of the *Supertext → Settings* screen: one Supertext code (or none:
     * the default applies), tone and *Translation review* per Pimcore language, checked against Supertext's targets.
     */
    #[Route('/supertext/settings/languages', name: 'supertext_translation_save_languages', methods: ['POST'])]
    public function saveLanguages(Request $request): JsonResponse
    {
        $user = $this->user();
        if (!$user->isAdmin()) {
            return $this->error('Only administrators can change the Supertext settings.', 403, 'admin-only');
        }
        if ($request->headers->get('X-Supertext-Request') !== '1') {
            return $this->error('Missing X-Supertext-Request header.', 400);
        }
        $payload = json_decode($request->getContent() ?: '[]', true);
        $input = \is_array($payload) && \is_array($payload['languages'] ?? null) ? $payload['languages'] : null;
        if ($input === null) {
            return $this->error('Missing languages.', 400);
        }
        try {
            $languages = LanguageMapping::validate($input, Tool::getValidLanguages());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400, 'invalid-mapping');
        }
        $this->languageStore->save($languages);
        $this->reporting->later(InstallationReports::ON_REQUEST);

        return new JsonResponse($this->settingsData($user));
    }

    /**
     * What the settings screen shows: status, and per Pimcore language its row of the mapping.
     *
     * @return array<string, mixed>
     */
    private function settingsData(User $user): array
    {
        $mapping = $this->settings->languageMapping();
        $display = $user->getLanguage() ?: 'en';
        $languages = [];
        foreach (Tool::getValidLanguages() as $language) {
            $row = $mapping->row($language);
            $languages[] = $row + [
                'name' => \Locale::getDisplayName($language, $display),
                'politenessSupported' => $row['code'] === null ? null : LanguageCodes::supportsPoliteness($row['code']),
            ];
        }
        $targets = array_map(static fn (array $target): array => [
            'code' => $target['code'],
            'name' => $target['name'],
            'localName' => self::displayName($target['code'], $display, $target['name']),
            'politeness' => $target['politeness'],
        ], Languages::targetOptions());

        return [
            'version' => Settings::version(),
            'releaseUrl' => Settings::releaseUrl(Settings::version()),
            'configured' => $this->settings->apiKey() !== '',
            'status' => $this->status(),
            'apiKeySource' => $this->settings->apiKeySource(),
            'apiUrl' => $this->settings->baseUrl(),
            'timeout' => $this->settings->timeout(),
            // '' = Translation review is off for every language (review_url / SUPERTEXT_REVIEW_URL).
            'reviewAvailable' => $this->settings->reviewUrl() !== '',
            'languages' => $languages,
            'targets' => $targets,
            'signupUrl' => Settings::SIGNUP_URL,
            'apiKeyUrl' => Settings::API_KEY_URL,
            'isAdmin' => $user->isAdmin(),
        ];
    }

    /** A Supertext code's name in the user's language (PHP intl), else Supertext's English name. */
    private static function displayName(string $code, string $display, string $fallback): string
    {
        // gsw-u-sd-chzh: intl doesn't name the subdivision; keep Supertext's "Swiss German (Zurich)".
        if (str_contains($code, '-u-')) {
            return $fallback;
        }
        $name = \Locale::getDisplayName(str_replace('-', '_', $code), $display);

        return \is_string($name) && $name !== str_replace('-', '_', $code) ? $name : $fallback;
    }

    #[Route('/supertext/settings/test', name: 'supertext_translation_test', methods: ['POST'])]
    public function test(): JsonResponse
    {
        if (!$this->user()->isAdmin()) {
            return $this->error('Only administrators can test the connection.', 403);
        }
        // The SDK's key check (cost-free GET features), stored and reported like every other check.
        // Always 200 for administrators: the check's status says how it went.
        $result = $this->reporting->run(InstallationReports::TEST_CONNECTION);
        $check = $result?->check;

        return new JsonResponse([
            'ok' => $check?->status === 'ok',
            'check' => InstallationReports::checkToArray($check),
            'message' => InstallationReports::checkMessage($check),
        ]);
    }

    /**
     * The latest API key check and the version report setting (administrators).
     *
     * @return array{check: array{status: string, checkedAt: string, httpStatus: int|null, errorCode: string|null}|null, reports: bool, reportsFromEnvironment: bool}
     */
    private function status(): array
    {
        return [
            'check' => InstallationReports::checkToArray($this->reporting->lastCheck()),
            'reports' => $this->settings->versionReports(),
            'reportsFromEnvironment' => $this->settings->versionReportsFromEnvironment(),
        ];
    }

    /**
     * Adds the Supertext target code to each language (null: not mapped, the dialog says so) and
     * whether a configured tone is offered for it.
     *
     * @param list<array<string, mixed>> $languages
     *
     * @return list<array<string, mixed>>
     */
    private function withCodes(array $languages): array
    {
        foreach ($languages as $i => $language) {
            $code = $this->settings->languageCodeOrNull((string) $language['language']);
            $languages[$i]['supertext'] = $code;
            $languages[$i]['toneUnavailable'] = $code !== null
                && $this->settings->politeness((string) $language['language']) !== ''
                && LanguageCodes::supportsPoliteness($code) === false;
        }

        return $languages;
    }

    private function user(): User
    {
        $user = $this->security->getCurrentUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function load(string $type, int $id): PageSnippet|Concrete|null
    {
        $element = $type === 'document' ? Document::getById($id) : Concrete::getById($id);

        return $element instanceof PageSnippet || $element instanceof Concrete ? $element : null;
    }

    /** @param string $key the UI shows `supertext.error.<key>` (translations/studio.*.yaml) instead of the English message */
    private function error(string $message, int $status, string $key = ''): JsonResponse
    {
        return new JsonResponse(['error' => $message, 'key' => $key], $status);
    }
}
