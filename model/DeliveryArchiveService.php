<?php
/**
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; under version 2
 * of the License (non-upgradable).
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 *
 * Copyright (c) 2017 (original work) Open Assessment Technologies SA (under the project TAO-PRODUCT);
 *
 */

namespace oat\taoDeliveryRdf\model;

use common_Logger;
use oat\generis\model\OntologyAwareTrait;
use oat\oatbox\filesystem\Directory;
use oat\oatbox\filesystem\File;
use oat\oatbox\filesystem\FileSystem;
use oat\oatbox\filesystem\FileSystemService;
use oat\oatbox\service\ConfigurableService;
use oat\oatbox\service\ServiceNotFoundException;
use oat\taoDelivery\model\DeliverArchiveExistingException;
use oat\taoDelivery\model\DeliveryArchiveNotExistingException;
use oat\taoDeliveryRdf\model\event\DeliveryCreatedEvent;
use oat\taoDeliveryRdf\model\event\DeliveryRemovedEvent;
use tao_models_classes_service_FileStorage;
use tao_models_classes_service_StorageDirectory;

class DeliveryArchiveService extends ConfigurableService implements \oat\taoDelivery\model\DeliveryArchiveService
{
    use OntologyAwareTrait;

    const BUCKET_DIRECTORY = 'deliveriesArchives';

    /** @var string */
    protected $tmpDir;

    /**
     * @param DeliveryCreatedEvent $event
     * @throws ServiceNotFoundException
     */
    public function catchDeliveryCreated(DeliveryCreatedEvent $event)
    {
        $compiledDelivery = $this->getResource($event->getDeliveryUri());

        try {
            $this->archive($compiledDelivery);
        } catch (DeliverArchiveExistingException $e) {
            common_Logger::i($e->getMessage());
        }
    }

    /**
     * @param DeliveryRemovedEvent $event
     * @throws ServiceNotFoundException
     */
    public function catchDeliveryRemoved(DeliveryRemovedEvent $event)
    {
        $compiledDelivery = $this->getResource($event->getDeliveryUri());

        $this->deleteArchive($compiledDelivery);
    }

    /**
     * @param \core_kernel_classes_Resource $compiledDelivery
     * @param bool $force
     * @return string
     * @throws DeliverArchiveExistingException
     */
    public function archive($compiledDelivery, $force = false)
    {
        $fileName = $this->getArchiveFileName($compiledDelivery);

        if (!$force && $this->getArchiveFileSystem()->has($fileName)) {
            throw new DeliverArchiveExistingException('Delivery archive already created: ' . $compiledDelivery->getUri());
        }

        $this->generateNewTmpPath($fileName);
        $localZipName = $this->getLocalZipPathName($fileName);

        $zip = new \ZipArchive();
        $zip->open($localZipName, \ZipArchive::CREATE);

        $directories = $compiledDelivery->getPropertyValues(
            $this->getProperty(DeliveryAssemblyService::PROPERTY_DELIVERY_DIRECTORY)
        );
        foreach ($directories as $directoryId) {
            /** @var tao_models_classes_service_StorageDirectory $directory */
            $directory = $this->getServiceLocator()->get(tao_models_classes_service_FileStorage::SERVICE_ID)->getDirectoryById($directoryId);
            $directories = $directory->getFlyIterator(Directory::ITERATOR_FILE | Directory::ITERATOR_RECURSIVE);
            /** @var File $item */
            foreach ($directories as $item) {
                $zip->addFromString($item->getFileSystemId() . '/' . $item->getPrefix(), $item->read());
            }
        }

        $zip = $this->refreshArchiveProcessed($zip);
        $zip->close();

        $fileName = $this->uploadZip($compiledDelivery);

        $this->deleteTmpFile($localZipName);

        return $fileName;
    }

    /**
     * @param \core_kernel_classes_Resource $compiledDelivery
     * @param bool $force
     * @return string
     * @throws DeliveryArchiveNotExistingException
     * @throws ServiceNotFoundException
     */
    public function unArchive($compiledDelivery, $force = false)
    {
        $fileName = $this->getArchiveFileName($compiledDelivery);

        if (!$this->getArchiveFileSystem()->has($fileName)) {
            throw new DeliveryArchiveNotExistingException('Delivery archive not exist please generate: ' . $compiledDelivery->getUri());
        }

        $this->generateNewTmpPath($fileName);
        $zipPath = $this->download($compiledDelivery);

        $zip = new \ZipArchive();
        $zip->open($zipPath);

        if ($force || !$this->isArchivedProcessed($zip, $fileName)){
            $this->copyFromZip($zip);
            $this->setArchiveProcessed($zip, $fileName);
            $zip->close();

            $fileName = $this->uploadZip($compiledDelivery);
        } else {
            $zip->close();
        }

        $this->deleteTmpFile($zipPath);

        return $fileName;
    }

    /**
     * @param \core_kernel_classes_Resource $compiledDelivery
     * @return string
     * @throws ServiceNotFoundException
     */
    public function deleteArchive($compiledDelivery)
    {
        $fileName = $this->getArchiveFileName($compiledDelivery);
        if ($this->getArchiveFileSystem()->has($fileName)) {
            $this->getArchiveFileSystem()->delete($fileName);
        }

        return $fileName;
    }

    /**
     * @param $zip \ZipArchive
     * @return bool
     * @throws ServiceNotFoundException
     */
    private function copyFromZip($zip)
    {
        /** @var FileSystemService $fileSystem */
        $fileSystem = $this->getServiceLocator()->get(FileSystemService::SERVICE_ID);

        for ($index = 0; $index < $zip->numFiles; ++$index)
        {
            $zipEntryName = $zip->getNameIndex($index);
            if (!$this->isZipDirectory($zipEntryName)) {
                $parts = explode('/', $zipEntryName);
                $bucketDestination = $parts[0];
                unset($parts[0]);
                if (in_array($bucketDestination, ['public', 'private',])) {
                    $entryName = implode('/', $parts);
                    $stream = $zip->getStream($zipEntryName);
                    if (is_resource($stream)) {
                        $fileSystem->getFileSystem($bucketDestination)->putStream($entryName, $stream);
                    }
                }
            }
        }
        return true;
    }

    /**
     * @param \core_kernel_classes_Resource $compiledDelivery
     * @return string
     * @throws ServiceNotFoundException
     */
    private function uploadZip($compiledDelivery)
    {
        $fileName = $this->getArchiveFileName($compiledDelivery);
        $zipPath = $this->getLocalZipPathName($fileName);

        if (!$this->getArchiveFileSystem()->has($fileName)) {
            $this->getArchiveFileSystem()->write($fileName, file_get_contents($zipPath));
        } else {
            $this->getArchiveFileSystem()->update($fileName, file_get_contents($zipPath));
        }

        return $fileName;
    }

    /**
     * @return FileSystem
     * @throws ServiceNotFoundException
     */
    private function getArchiveFileSystem()
    {
        return $this->getServiceLocator()->get(FileSystemService::SERVICE_ID)->getFileSystem(static::BUCKET_DIRECTORY);
    }

    /**
     * @param \core_kernel_classes_Resource $compiledDelivery
     * @return string
     */
    private function download($compiledDelivery)
    {
        $fileName = $this->getArchiveFileName($compiledDelivery);
        $zipPath = $this->getLocalZipPathName($fileName);

        file_put_contents($zipPath, $this->getArchiveFileSystem()->read($fileName));

        return $zipPath;
    }

    /**
     * @param \core_kernel_classes_Resource $compiledDelivery
     * @return string
     */
    private function getArchiveFileName($compiledDelivery)
    {
        return md5($compiledDelivery->getUri()) . '.zip';
    }

    /**
     * @param $fileName
     * @return string
     */
    private function getLocalZipPathName($fileName)
    {
        return $this->getTmpPath() . $fileName;
    }

    /**
     * @return mixed
     */
    private function getTmpPath()
    {
        return $this->tmpDir;
    }


    /**
     * generate unique tmp folder based on delivery.
     * @param $fileName
     */
    private function generateNewTmpPath($fileName)
    {
        $folder = sys_get_temp_dir().DIRECTORY_SEPARATOR."tmp".md5($fileName. uniqid('', true)).DIRECTORY_SEPARATOR;

        if (!file_exists($folder)) {
            mkdir($folder);
        }

        $this->tmpDir = $folder;
    }

    /**
     * @param $tmpZipPath
     */
    private function deleteTmpFile($tmpZipPath)
    {
        unlink($tmpZipPath);
        if (\helpers_File::emptyDirectory($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    /**
     * @param $zipEntryName
     * @return bool
     */
    private function isZipDirectory($zipEntryName)
    {
        return substr($zipEntryName, -1) ===  '/';
    }

    /**
     * @param $fileName
     * @return string
     */
    private function getUniqueProcessedName($fileName)
    {
        return md5(gethostname()) . $fileName;
    }

    /**
     * @param \ZipArchive $zip
     * @param $fileName
     * @return \ZipArchive
     */
    private function setArchiveProcessed($zip, $fileName)
    {
        $stats = json_decode($zip->getArchiveComment(), true);
        if (is_null($stats)) {
            $stats = ['processed' => []];
        }

        $stats['processed'][] = $this->getUniqueProcessedName($fileName);
        $zip->setArchiveComment(json_encode($stats));

        return $zip;
    }

    /**
     * @param \ZipArchive $zip
     * @return \ZipArchive
     */
    private function refreshArchiveProcessed($zip)
    {
        $stats = ['processed' => []];
        $zip->setArchiveComment(json_encode($stats));

        return $zip;
    }

    /**
     * @param \ZipArchive $zip
     * @return bool
     */
    private function isArchivedProcessed($zip, $fileName)
    {
        $stats = json_decode($zip->getArchiveComment(), true);
        if (is_null($stats) || !isset($stats['processed'])) {
            $stats = ['processed' => []];
        }

        return in_array($this->getUniqueProcessedName($fileName), $stats['processed']);
    }
}