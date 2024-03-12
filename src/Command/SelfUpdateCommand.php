<?php

namespace Yceruto\SelfUpdatePlugin\Command;

use Composer\Command\BaseCommand;
use Composer\Factory;
use React\Promise\PromiseInterface;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use ZipArchive;

class SelfUpdateCommand extends BaseCommand
{
    use UpdateTrait;

    protected function configure(): void
    {
        $this
            ->setName('project:self-update')
            ->setDescription('Updates the project to a defined version')
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
                $io->writeError(sprintf('Project is already patched with version <info>%s</info> for <info>%s</info>', $package->getPrettyVersion(), $package->getName()));

                return self::SUCCESS;
            }
        }

        $filePath = $this->composer()->getArchiveManager()->archive($package, $package->getDistType(), $packageDir);

        $promise = $this->extractWithZipArchive($filePath, $packageDir);
        $promise->then(function () use ($io, $filePath, $packageLockFile, $package) {
            if (file_exists($filePath)) {
                @unlink($filePath);
            }

            $packageLockFile->write([
                'name' => $package->getName(),
                'version' => $package->getVersion(),
                'datetime' => date('Y-m-d H:i:s'),
            ]);

            $io->writeError(sprintf('Project has been patched with version <info>%s</info> for <info>%s</info>', $package->getPrettyVersion(), $package->getName()));
        });

        return self::SUCCESS;
    }

    /**
     * extract $file to $path with ZipArchive
     *
     * @param string $file File to extract
     * @param string $path Path where to extract file
     *
     * @phpstan-return PromiseInterface<void|null>
     * @throws Throwable
     */
    private function extractWithZipArchive(string $file, string $path): PromiseInterface
    {
        $zipArchive = new ZipArchive();

        try {
            if (!file_exists($file) || ($filesize = filesize($file)) === false || $filesize === 0) {
                $retval = -1;
            } else {
                $retval = $zipArchive->open($file);
            }
            if (true === $retval) {
                $extractResult = $zipArchive->extractTo($path);

                if (true === $extractResult) {
                    $zipArchive->close();

                    return \React\Promise\resolve(null);
                }

                $processError = new \RuntimeException(
                    rtrim(
                        "There was an error extracting the ZIP file, it is either corrupted or using an invalid format.\n"
                    )
                );
            } else {
                $processError = new \UnexpectedValueException(
                    rtrim($this->getErrorMessage($retval, $file)."\n"),
                    $retval
                );
            }
        } catch (\ErrorException $e) {
            $processError = new \RuntimeException(
                'The archive may contain identical file names with different capitalization (which fails on case insensitive filesystems): '.$e->getMessage(
                ), 0, $e
            );
        } catch (\Throwable $e) {
            $processError = $e;
        }

        throw $processError;
    }

    /**
     * Give a meaningful error message to the user.
     */
    private function getErrorMessage(int $retval, string $file): string
    {
        switch ($retval) {
            case ZipArchive::ER_EXISTS:
                return sprintf("File '%s' already exists.", $file);
            case ZipArchive::ER_INCONS:
                return sprintf("Zip archive '%s' is inconsistent.", $file);
            case ZipArchive::ER_INVAL:
                return sprintf("Invalid argument (%s)", $file);
            case ZipArchive::ER_MEMORY:
                return sprintf("Malloc failure (%s)", $file);
            case ZipArchive::ER_NOENT:
                return sprintf("No such zip file: '%s'", $file);
            case ZipArchive::ER_NOZIP:
                return sprintf("'%s' is not a zip archive.", $file);
            case ZipArchive::ER_OPEN:
                return sprintf("Can't open zip file: %s", $file);
            case ZipArchive::ER_READ:
                return sprintf("Zip read error (%s)", $file);
            case ZipArchive::ER_SEEK:
                return sprintf("Zip seek error (%s)", $file);
            case -1:
                return sprintf("'%s' is a corrupted zip archive (0 bytes), try again.", $file);
            default:
                return sprintf("'%s' is not a valid zip archive, got error code: %s", $file, $retval);
        }
    }
}
