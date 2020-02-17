<?php

namespace Spatie\Medialibrary\FileAdder;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Traits\Macroable;
use Spatie\Medialibrary\Exceptions\FileCannotBeAdded\DiskDoesNotExist;
use Spatie\Medialibrary\Exceptions\FileCannotBeAdded\FileDoesNotExist;
use Spatie\Medialibrary\Exceptions\FileCannotBeAdded\FileIsTooBig;
use Spatie\Medialibrary\Exceptions\FileCannotBeAdded\FileUnacceptableForCollection;
use Spatie\Medialibrary\Exceptions\FileCannotBeAdded\TemporaryUploadDoesNotBelongToCurrentSession;
use Spatie\Medialibrary\Exceptions\FileCannotBeAdded\UnknownType;
use Spatie\Medialibrary\File as PendingFile;
use Spatie\Medialibrary\Filesystem\Filesystem;
use Spatie\Medialibrary\HasMedia\HasMedia;
use Spatie\Medialibrary\Helpers\File;
use Spatie\Medialibrary\Helpers\RemoteFile;
use Spatie\Medialibrary\ImageGenerators\FileTypes\Image as ImageGenerator;
use Spatie\Medialibrary\Jobs\GenerateResponsiveImagesJob;
use Spatie\Medialibrary\MediaCollection\MediaCollection;
use Spatie\Medialibrary\Models\Media;
use Spatie\MedialibraryPro\Models\TemporaryUpload;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class FileAdder
{
    use Macroable;

    protected ?Model $subject;

    protected ?Filesystem $filesystem;

    protected bool $preserveOriginal = false;

    /** @var \Symfony\Component\HttpFoundation\File\UploadedFile|string */
    protected $file;

    protected array $properties = [];

    protected array $customProperties = [];

    protected array $manipulations = [];

    protected string $pathToFile = '';

    protected string $fileName = '';

    protected string $mediaName = '';

    protected string $diskName = '';

    protected string $conversionsDiskName = '';

    protected ?Closure $fileNameSanitizer;

    protected bool $generateResponsiveImages = false;

    protected array $customHeaders = [];

    public function __construct(Filesystem $fileSystem)
    {
        $this->filesystem = $fileSystem;

        $this->fileNameSanitizer = fn($fileName) => $this->defaultSanitizer($fileName);
    }

    public function setSubject(Model $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    /**
     * Set the file that needs to be imported.
     *
     * @param string|\Symfony\Component\HttpFoundation\File\UploadedFile|RemoteFile|TemporaryUpload $file
     *
     * @return $this
     */
    public function setFile($file): self
    {
        $this->file = $file;

        if (is_string($file)) {
            $this->pathToFile = $file;
            $this->setFileName(pathinfo($file, PATHINFO_BASENAME));
            $this->mediaName = pathinfo($file, PATHINFO_FILENAME);

            return $this;
        }

        if ($file instanceof RemoteFile) {
            $this->pathToFile = $file->getKey();
            $this->setFileName($file->getFilename());
            $this->mediaName = $file->getName();

            return $this;
        }

        if ($file instanceof UploadedFile) {
            $this->pathToFile = $file->getPath().'/'.$file->getFilename();
            $this->setFileName($file->getClientOriginalName());
            $this->mediaName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

            return $this;
        }

        if ($file instanceof SymfonyFile) {
            $this->pathToFile = $file->getPath().'/'.$file->getFilename();
            $this->setFileName(pathinfo($file->getFilename(), PATHINFO_BASENAME));
            $this->mediaName = pathinfo($file->getFilename(), PATHINFO_FILENAME);

            return $this;
        }

        if ($file instanceof TemporaryUpload) {
            return $this;
        }

        throw UnknownType::create();
    }

    public function preservingOriginal(): self
    {
        $this->preserveOriginal = true;

        return $this;
    }

    public function usingName(string $name): self
    {
        return $this->setName($name);
    }

    public function setName(string $name): self
    {
        $this->mediaName = $name;

        return $this;
    }

    public function usingFileName(string $fileName): self
    {
        return $this->setFileName($fileName);
    }

    public function setFileName(string $fileName): self
    {
        $this->fileName = $fileName;

        return $this;
    }

    public function withCustomProperties(array $customProperties): self
    {
        $this->customProperties = $customProperties;

        return $this;
    }

    public function storingConversionsOnDisk(string $diskName)
    {
        $this->conversionsDiskName = $diskName;

        return $this;
    }

    public function withManipulations(array $manipulations): self
    {
        $this->manipulations = $manipulations;

        return $this;
    }

    public function withProperties(array $properties): self
    {
        $this->properties = $properties;

        return $this;
    }

    public function withAttributes(array $properties): self
    {
        return $this->withProperties($properties);
    }

    public function withResponsiveImages(): self
    {
        $this->generateResponsiveImages = true;

        return $this;
    }

    public function addCustomHeaders(array $customRemoteHeaders): self
    {
        $this->customHeaders = $customRemoteHeaders;

        $this->filesystem->addCustomRemoteHeaders($customRemoteHeaders);

        return $this;
    }

    public function toMediaCollectionOnCloudDisk(string $collectionName = 'default'): Media
    {
        return $this->toMediaCollection($collectionName, config('filesystems.cloud'));
    }

    public function toMediaCollectionFromRemote(string $collectionName = 'default', string $diskName = ''): Media
    {
        $storage = Storage::disk($this->file->getDisk());

        if (! $storage->exists($this->pathToFile)) {
            throw FileDoesNotExist::create($this->pathToFile);
        }

        if ($storage->size($this->pathToFile) > config('medialibrary.max_file_size')) {
            throw FileIsTooBig::create($this->pathToFile, $storage->size($this->pathToFile));
        }

        $mediaClass = config('medialibrary.media_model');
        /** @var \Spatie\Medialibrary\Models\Media $media */
        $media = new $mediaClass();

        $media->name = $this->mediaName;

        $this->fileName = ($this->fileNameSanitizer)($this->fileName);

        $media->file_name = $this->fileName;

        $media->disk = $this->determineDiskName($diskName, $collectionName);

        if (is_null(config("filesystems.disks.{$media->disk}"))) {
            throw DiskDoesNotExist::create($media->disk);
        }

        $media->collection_name = $collectionName;

        $media->mime_type = $storage->mimeType($this->pathToFile);
        $media->size = $storage->size($this->pathToFile);
        $media->custom_properties = $this->customProperties;

        $media->responsive_images = [];

        $media->manipulations = $this->manipulations;

        if (filled($this->customHeaders)) {
            $media->setCustomHeaders($this->customHeaders);
        }

        $media->fill($this->properties);

        $this->attachMedia($media);

        return $media;
    }

    public function toMediaCollection(string $collectionName = 'default', string $diskName = ''): Media
    {
        if ($this->file instanceof RemoteFile) {
            return $this->toMediaCollectionFromRemote($collectionName, $diskName);
        }

        if ($this->file instanceof TemporaryUpload) {
            return $this->toMediaCollectionFromTemporaryUpload($collectionName, $diskName);
        }

        if (! is_file($this->pathToFile)) {
            throw FileDoesNotExist::create($this->pathToFile);
        }

        if (filesize($this->pathToFile) > config('medialibrary.max_file_size')) {
            throw FileIsTooBig::create($this->pathToFile);
        }

        $mediaClass = config('medialibrary.media_model');
        /** @var \Spatie\Medialibrary\Models\Media $media */
        $media = new $mediaClass();

        $media->name = $this->mediaName;

        $this->fileName = ($this->fileNameSanitizer)($this->fileName);

        $media->file_name = $this->fileName;

        $media->disk = $this->determineDiskName($diskName, $collectionName);
        if (is_null(config("filesystems.disks.{$media->disk}"))) {
            throw DiskDoesNotExist::create($media->disk);
        }

        $media->conversions_disk = $this->determineConversionsDiskName($media->disk, $collectionName);
        if (is_null(config("filesystems.disks.{$media->conversions_disk}"))) {
            throw DiskDoesNotExist::create($media->conversions_disk);
        }
        $media->collection_name = $collectionName;

        $media->mime_type = File::getMimetype($this->pathToFile);
        $media->size = filesize($this->pathToFile);
        $media->custom_properties = $this->customProperties;

        $media->responsive_images = [];

        $media->manipulations = $this->manipulations;

        if (filled($this->customHeaders)) {
            $media->setCustomHeaders($this->customHeaders);
        }

        $media->fill($this->properties);

        $this->attachMedia($media);

        return $media;
    }

    protected function determineDiskName(string $diskName, string $collectionName): string
    {
        if ($diskName !== '') {
            return $diskName;
        }

        if ($collection = $this->getMediaCollection($collectionName)) {
            $collectionDiskName = $collection->diskName;

            if ($collectionDiskName !== '') {
                return $collectionDiskName;
            }
        }

        return config('medialibrary.disk_name');
    }

    protected function determineConversionsDiskName(string $originalsDiskName, string $collectionName): string
    {
        if ($this->conversionsDiskName !== '') {
            return $this->conversionsDiskName;
        }

        if ($collection = $this->getMediaCollection($collectionName)) {
            $collectionConversionsDiskName = $collection->conversionsDiskName;

            if ($collectionConversionsDiskName !== '') {
                return $collectionConversionsDiskName;
            }
        }

        return $originalsDiskName;
    }

    public function defaultSanitizer(string $fileName): string
    {
        return str_replace(['#', '/', '\\', ' '], '-', $fileName);
    }

    public function sanitizingFileName(callable $fileNameSanitizer): self
    {
        $this->fileNameSanitizer = $fileNameSanitizer;

        return $this;
    }

    protected function attachMedia(Media $media)
    {
        if (! $this->subject->exists) {
            $this->subject->prepareToAttachMedia($media, $this);

            $class = get_class($this->subject);

            $class::created(function ($model) {
                $model->processUnattachedMedia(function (Media $media, self $fileAdder) use ($model) {
                    $this->processMediaItem($model, $media, $fileAdder);
                });
            });

            return;
        }

        $this->processMediaItem($this->subject, $media, $this);
    }

    protected function processMediaItem(HasMedia $model, Media $media, self $fileAdder)
    {
        $this->guardAgainstDisallowedFileAdditions($media, $model);

        $this->checkGenerateResponsiveImages($media);

        $model->media()->save($media);

        if ($fileAdder->file instanceof RemoteFile) {
            $this->filesystem->addRemote($fileAdder->file, $media, $fileAdder->fileName);
        } else {
            $this->filesystem->add($fileAdder->pathToFile, $media, $fileAdder->fileName);
        }

        if (! $fileAdder->preserveOriginal) {
            if ($fileAdder->file instanceof RemoteFile) {
                Storage::disk($fileAdder->file->getDisk())->delete($fileAdder->file->getKey());
            } else {
                unlink($fileAdder->pathToFile);
            }
        }

        if ($this->generateResponsiveImages && (new ImageGenerator())->canConvert($media)) {
            $generateResponsiveImagesJobClass = config('medialibrary.jobs.generate_responsive_images', GenerateResponsiveImagesJob::class);

            $job = new $generateResponsiveImagesJobClass($media);

            if ($customQueue = config('medialibrary.queue_name')) {
                $job->onQueue($customQueue);
            }

            dispatch($job);
        }

        if ($collectionSizeLimit = optional($this->getMediaCollection($media->collection_name))->collectionSizeLimit) {
            $collectionMedia = $this->subject->fresh()->getMedia($media->collection_name);

            if ($collectionMedia->count() > $collectionSizeLimit) {
                $model->clearMediaCollectionExcept($media->collection_name, $collectionMedia->reverse()->take($collectionSizeLimit));
            }
        }
    }

    protected function getMediaCollection(string $collectionName): ?MediaCollection
    {
        $this->subject->registerMediaCollections();

        return collect($this->subject->mediaCollections)
            ->first(fn(MediaCollection $collection) => $collection->name === $collectionName);
    }

    protected function guardAgainstDisallowedFileAdditions(Media $media)
    {
        $file = PendingFile::createFromMedia($media);

        if (! $collection = $this->getMediaCollection($media->collection_name)) {
            return;
        }

        if (! ($collection->acceptsFile)($file, $this->subject)) {
            throw FileUnacceptableForCollection::create($file, $collection, $this->subject);
        }

        if (! empty($collection->acceptsMimeTypes) && ! in_array($file->mimeType, $collection->acceptsMimeTypes)) {
            throw FileUnacceptableForCollection::create($file, $collection, $this->subject);
        }
    }

    protected function checkGenerateResponsiveImages(Media $media)
    {
        $collection = optional($this->getMediaCollection($media->collection_name))->generateResponsiveImages;

        if ($collection) {
            $this->withResponsiveImages();
        }
    }

    protected function toMediaCollectionFromTemporaryUpload(string $collectionName, string $diskName): Media
    {
        /** @var TemporaryUpload $temporaryUpload */
        $temporaryUpload = $this->file;

        return $temporaryUpload->moveMedia($this->subject, $collectionName, $diskName);
    }
}
