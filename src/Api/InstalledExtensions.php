<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for Pimcore
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\PimcoreTranslationBundle\Api;

/**
 * The extensions "Send configuration to Supertext" reports: the bundles registered in the kernel
 * except Pimcore's own and the framework's, with the Composer package each comes from (name and
 * version). No Pimcore or Symfony classes (unit-tested).
 */
final class InstalledExtensions
{
    /** Namespaces of Pimcore itself and of the libraries it ships with. */
    public const CORE_NAMESPACES = [
        'Pimcore\\', 'Symfony\\', 'Doctrine\\', 'Twig\\', 'FOS\\', 'Liip\\', 'Sensio\\', 'Nelmio\\', 'Monolog\\',
        'Http\\', 'League\\', 'Knp\\', 'Presta\\', 'Scheb\\', 'Lexik\\', 'JMS\\',
    ];

    /**
     * @param array<string, string>                        $bundles  kernel.bundles (short name => class)
     * @param (callable(string): ?array{name: string, version: ?string})|null $package the Composer package a class comes from (default: Composer's InstalledVersions)
     *
     * @return list<array{name: string, version: ?string, active: bool, type: string}>
     */
    public static function from(array $bundles, ?callable $package = null): array
    {
        $package ??= self::composerPackage(...);
        $out       = [];

        foreach ($bundles as $short => $class) {
            foreach (self::CORE_NAMESPACES as $namespace) {
                if (str_starts_with($class, $namespace)) {
                    continue 2;
                }
            }

            $info  = $package($class);
            $name  = $info['name'] ?? (string) $short;
            $out[$name] = ['name' => $name, 'version' => $info['version'] ?? null, 'active' => true, 'type' => 'bundle'];
        }

        ksort($out);

        return array_values($out);
    }

    /** @return array{name: string, version: ?string}|null the installed Composer package whose folder holds the class */
    public static function composerPackage(string $class): ?array
    {
        try {
            if (!class_exists($class) || !class_exists(\Composer\InstalledVersions::class)) {
                return null;
            }

            $file = (string) (new \ReflectionClass($class))->getFileName();
            $best = null;

            foreach (\Composer\InstalledVersions::getInstalledPackages() as $name) {
                $path = \Composer\InstalledVersions::getInstallPath($name);
                $path = $path !== null ? realpath($path) : false;

                if ($path !== false && str_starts_with($file, $path . \DIRECTORY_SEPARATOR) && ($best === null || \strlen($path) > \strlen($best[1]))) {
                    $best = [$name, $path];
                }
            }

            if ($best === null) {
                return null;
            }

            $version = \Composer\InstalledVersions::getPrettyVersion($best[0]);

            return ['name' => $best[0], 'version' => $version !== null ? ltrim($version, 'v') : null];
        } catch (\Throwable) {
            return null;
        }
    }
}
