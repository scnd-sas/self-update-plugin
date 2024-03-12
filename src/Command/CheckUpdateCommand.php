<?php

namespace Yceruto\SelfUpdatePlugin\Command;

use Composer\Command\BaseCommand;
use Composer\Factory;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CheckUpdateCommand extends BaseCommand
{
    use UpdateTrait;

    protected function configure(): void
    {
        $this
            ->setName('project:check-update')
            ->setDescription('Checks if there is an update available for the project')
            ->addArgument('version', InputArgument::OPTIONAL, 'The version to update to')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();

        try {
            $package = $this->findPackage($input->getArgument('version'));
        } catch (RuntimeException $e) {
            $io->writeError(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        $packageDir = realpath(dirname(Factory::getComposerFile()));
        $packageLockFile = $this->getLockFile($packageDir, $package, $io);

        if ($packageLockFile->exists()) {
            $lock = $packageLockFile->read();

            if ($package->getName() === $lock['name'] && $package->getVersion() === $lock['version']) {
                $io->writeError(sprintf('No new version is available. The project is already updated to version <info>%s</info> for <info>%s</info>', $package->getPrettyVersion(), $package->getName()));

                return self::SUCCESS;
            }
        }

        $io->writeError(sprintf('A new version <info>%s</info> of <info>%s</info> is now available', $package->getPrettyVersion(), $package->getName()));

        return self::SUCCESS;
    }
}
