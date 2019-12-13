<?php
declare(strict_types=1);

namespace Plan2net\Webp\Processing;

use Plan2net\Webp\Service\Configuration;
use Plan2net\Webp\Service\Webp as WebpService;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Resource\Driver\DriverInterface;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;
use TYPO3\CMS\Core\Resource\Service\FileProcessingService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class Webp
 *
 * @package Plan2net\Webp\Processing
 * @author  Wolfgang Klinger <wk@plan2.net>
 */
class Webp
{
    /**
     * Process a file using the configured adapter to create a webp copy
     *
     * @param FileProcessingService $fileProcessingService
     * @param DriverInterface $driver
     * @param ProcessedFile $processedFile
     * @param File $file
     * @param string $taskType
     * @param array $configuration
     */
    public function processFile(
        FileProcessingService $fileProcessingService,
        DriverInterface $driver,
        ProcessedFile $processedFile,
        File $file,
        string $taskType,
        array $configuration
    ): void {
        if ($this->shouldProcess($taskType, $processedFile)) {
            /** @var ProcessedFileRepository $processedFileRepository */
            $processedFileRepository = GeneralUtility::makeInstance(ProcessedFileRepository::class);
            // This will either return an existing file
            // or create a new one
            $processedFileWebp = $processedFileRepository->findOneByOriginalFileAndTaskTypeAndConfiguration(
                $file,
                $taskType,
                $configuration + [
                    'webp' => true
                ]
            );
            // Check if reprocessing is required
            if (!$this->needsReprocessing($processedFileWebp)) {
                return;
            }

            try {
                /** @var WebpService $service */
                $service = GeneralUtility::makeInstance(WebpService::class);
                $service->process($processedFile, $processedFileWebp);

                // Be aware that using shortMD5 results in a very bad checksum,
                // but TYPO3 CMS core has a limit on this field
                $processedFileWebp->updateProperties(
                    [
                        'checksum' => GeneralUtility::shortMD5(implode('|',
                            $this->getChecksumData($file, $processedFileWebp, $configuration)))
                    ]
                );

                // This will add or update
                $processedFileRepository->add($processedFileWebp);
            } catch (\Exception $e) {
                $logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
                $logger->error(sprintf('Failed to convert image to webp: %s', $e->getMessage()));
                try {
                    $processedFile->delete(true);
                } catch (\Exception $e) {
                    $logger->error(sprintf('Failed to remove processed file "%s": %s',
                        $processedFile->getIdentifier(),
                        $e->getMessage()
                    ));
                }
            }
        }
    }

    /**
     * @param string $taskType
     * @param ProcessedFile $processedFile
     * @return bool
     */
    protected function shouldProcess(string $taskType, ProcessedFile $processedFile): bool
    {
        if ($taskType !== 'Image.CropScaleMask') {
            return false;
        }

        if (!WebpService::isSupportedMimeType($processedFile->getMimeType())) {
            return false;
        }

        // Convert images in any folder or only in the _processed_ folder
        $convertAllImages = (bool)Configuration::get('convert_all');
        if (!$convertAllImages && !$this->isFileInProcessingFolder($processedFile)) {
            return false;
        }

        // Process files only in a local and writable storage
        if (!$this->isStorageLocalAndWritable($processedFile)) {
            return false;
        }

        return true;
    }

    /**
     * @param ProcessedFile $processedFile
     * @return bool
     */
    protected function needsReprocessing($processedFile): bool
    {
        return $processedFile->isNew() ||
            (!$processedFile->usesOriginalFile() && !$processedFile->exists()) ||
            $processedFile->isOutdated();
    }

    /**
     * @param ProcessedFile $file
     * @return bool
     */
    protected function isFileInProcessingFolder($file): bool
    {
        $processingFolder = $file->getStorage()->getProcessingFolder();

        return strpos($file->getIdentifier(), $processingFolder->getIdentifier()) !== false;
    }

    /**
     * @param ProcessedFile $file
     * @return bool
     */
    protected function isStorageLocalAndWritable($file): bool
    {
        $storage = $file->getStorage();

        return $storage->getDriverType() === 'Local' && $storage->isWritable();
    }

    /**
     * @param File $file
     * @param ProcessedFile $processedFile
     * @param array $configuration
     * @return array
     */
    protected function getChecksumData($file, $processedFile, $configuration): array
    {
        return [
            $file->getUid(),
            'Image.Webp' . '.' . $processedFile->getName() . $file->getModificationTime(),
            serialize($configuration)
        ];
    }
}
