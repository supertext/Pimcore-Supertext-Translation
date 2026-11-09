<?php

declare(strict_types=1);

namespace Supertext\PimcoreTranslationBundle\Command;

use Pimcore\Tool;
use Supertext\PimcoreTranslationBundle\Api\InstallationReports;
use Supertext\PimcoreTranslationBundle\Api\LanguageCodes;
use Supertext\PimcoreTranslationBundle\PluginHealth\KeyCheck;
use Supertext\PimcoreTranslationBundle\Reporting\Reporting;
use Supertext\PimcoreTranslationBundle\Settings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/console supertext:check: the bundle version, the API address, the language mapping, version
 * reports, and the API key check (cost-free), which is sent to the Commando Center as a `check` report.
 */
#[AsCommand(name: 'supertext:check', description: 'Checks the Supertext API key (no cost) and shows the language mapping.')]
final class CheckCommand extends Command
{
    public function __construct(private readonly Settings $settings, private readonly Reporting $reporting)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $version = Settings::version();
        $release = Settings::releaseUrl($version);
        $output->writeln('Supertext Translation for Pimcore ' . $version . ($release ? " ({$release})" : ''));
        $output->writeln('API address: ' . $this->settings->baseUrl());
        $output->writeln('Review service (Translation review): ' . ($this->settings->reviewUrl() !== '' ? $this->settings->reviewUrl() : 'off (review_url is empty)'));

        $output->writeln('Languages (Pimcore → Supertext):');
        $unmapped = 0;
        foreach (Tool::getValidLanguages() as $language) {
            $code = $this->settings->languageCodeOrNull($language);
            $tone = $this->settings->politeness($language);
            if ($code === null) {
                ++$unmapped;
                $output->writeln(sprintf('  %s → <comment>not mapped</comment>: choose its Supertext language in Pimcore Studio, Supertext → Settings (or set supertext_translation.languages.%s.code)', $language, $language));
                continue;
            }
            $line = sprintf('  %s → %s', $language, $code) . ($tone !== '' ? " ({$tone})" : '')
                . ($this->settings->review($language) ? ', Translation review' : '');
            if ($tone !== '' && LanguageCodes::supportsPoliteness($code) === false) {
                $line .= ' <comment>Supertext offers no formal/informal tone for this language: remove its politeness</comment>';
            }
            $output->writeln($line);
        }

        $output->writeln('Send version information to Supertext: ' . ($this->settings->versionReports() ? 'on' : 'off')
            . ($this->settings->versionReportsFromEnvironment() ? ' (SUPERTEXT_VERSION_REPORTS)' : ' (version_reports)'));

        $result = $this->reporting->run(InstallationReports::REPORT_CHECK);
        // Without a site URL (no request, no domain in Pimcore's system settings) nothing is reported; check the key anyway.
        $check = $result->check ?? KeyCheck::from($this->settings->client()->checkApiKey())
            ?? new KeyCheck(KeyCheck::ERROR, gmdate('Y-m-d\TH:i:s\Z'));
        $ok = $check->status === KeyCheck::OK;
        $message = InstallationReports::checkMessage($check);
        $output->writeln($ok ? "<info>{$message}</info>" : "<error>{$message}</error>");
        if ($check->status === KeyCheck::MISSING) {
            $output->writeln('Set the SUPERTEXT_API_KEY environment variable.');
        }

        if ($unmapped > 0) {
            $output->writeln("<comment>{$unmapped} language(s) can't be translated into until they are mapped.</comment>");
        }

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }
}
