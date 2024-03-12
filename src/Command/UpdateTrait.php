<?php

namespace Yceruto\SelfUpdatePlugin\Command;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\BasePackage;
use Composer\Package\CompletePackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Package\Version\VersionSelector;
use Composer\Pcre\Preg;
use Composer\Repository\CompositeRepository;
use Composer\Repository\RepositoryFactory;
use Composer\Repository\RepositorySet;
use RuntimeException;

trait UpdateTrait
{
    private function composer(): Composer
    {
        return $this->tryComposer(true, true) ?? throw new RuntimeException('Composer is not available.');
    }

    private function findPackage(?string $version = null): CompletePackageInterface
    {
        $io = $this->getIO();

        if (null === $composer = $this->tryComposer(true, true)) {
            throw new RuntimeException('Composer is not available.');
        }

        $extra = $composer->getPackage()->getExtra();
        $packageName = $extra['self-update-plugin']['package'] ?? $composer->getPackage()->getName();
        $packageVersion = $version ?? $extra['self-update-plugin']['require'] ?? null;

        if (!$packageName || '__root__' === $packageName) {
            throw new RuntimeException('Unable to determine the package name. Please, add "extra.self-update-plugin.package" config to your composer.json file and try again.');
        }

        if (!$packageVersion) {
            throw new RuntimeException('Unable to determine the package require version. Please, add "extra.self-update-plugin.require" config to your composer.json file and try again.');
        }

        $io->writeError(sprintf('<info>Searching for "%s" package, version "%s"...</info>', $packageName, $packageVersion));

        $package = $this->selectPackage($io, $packageName, $packageVersion);

        if (null === $package) {
            throw new RuntimeException('The specified package was not found.');
        }

        return $package;
    }

    private function selectPackage(IOInterface $io, string $packageName, ?string $version = null): ?CompletePackageInterface
    {
        $io->writeError('<info>Searching for the specified package.</info>');

        if ($composer = $this->tryComposer()) {
            $localRepo = $composer->getRepositoryManager()->getLocalRepository();
            $repo = new CompositeRepository(
                array_merge([$localRepo], $composer->getRepositoryManager()->getRepositories())
            );
            $minStability = $composer->getPackage()->getMinimumStability();
        } else {
            $defaultRepos = RepositoryFactory::defaultReposWithDefaultManager($io);
            $io->writeError(
                'No composer.json found in the current directory, searching packages from '.implode(
                    ', ',
                    array_keys($defaultRepos)
                )
            );
            $repo = new CompositeRepository($defaultRepos);
            $minStability = 'stable';
        }

        if ($version !== null && Preg::isMatchStrictGroups('{@(stable|RC|beta|alpha|dev)$}i', $version, $match)) {
            $minStability = $match[1];
            $version = substr($version, 0, -strlen($match[0]));
        }

        $repoSet = new RepositorySet($minStability);
        $repoSet->addRepository($repo);
        $parser = new VersionParser();
        $constraint = $version !== null ? $parser->parseConstraints($version) : null;
        $packages = $repoSet->findPackages(strtolower($packageName), $constraint);

        if (count($packages) > 1) {
            $versionSelector = new VersionSelector($repoSet);
            $package = $versionSelector->findBestCandidate(strtolower($packageName), $version, $minStability);
            if ($package === false) {
                $package = reset($packages);
            }

            $io->writeError('<comment>Found multiple matches, selected '.$package->getPrettyString().'.</comment>');
            $io->writeError('<comment>Please use a more specific constraint to pick a different package.</comment>');
        } elseif (count($packages) === 1) {
            $package = reset($packages);
            $io->writeError('<info>Found an exact match '.$package->getPrettyString().'.</info>');
        } else {
            $io->writeError('<error>Could not find a package matching '.$packageName.'.</error>');

            return null;
        }

        if (!$package instanceof CompletePackageInterface) {
            throw new \LogicException('Expected a CompletePackageInterface instance but found '.get_class($package));
        }
        if (!$package instanceof BasePackage) {
            throw new \LogicException('Expected a BasePackage instance but found '.get_class($package));
        }

        return $package;
    }

    private function getLockFile(string $packageDir, CompletePackageInterface $package, IOInterface $io): JsonFile
    {
        return new JsonFile(
            path: $packageDir.DIRECTORY_SEPARATOR.substr($package->getName(), strpos($package->getName(), '/') + 1).'.lock',
            io: $io,
        );
    }
}
