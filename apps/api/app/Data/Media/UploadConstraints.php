<?php

declare(strict_types=1);

namespace App\Data\Media;

final readonly class UploadConstraints
{
    /**
     * Types MIME acceptés → noms de format compris par Cloudinary.
     *
     * Cloudinary raisonne en formats, pas en types MIME : `allowed_formats`
     * attend `jpg`, pas `image/jpeg`. `jpeg` est conservé à côté de `jpg` car
     * les deux graphies circulent selon la source du fichier.
     */
    private const CLOUDINARY_FORMATS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'image/heic' => ['heic'],
        'image/heif' => ['heif'],
        'video/mp4' => ['mp4'],
        'video/quicktime' => ['mov'],
    ];

    /**
     * @param  list<string>  $allowedMimes
     */
    public function __construct(
        public int $maxSizeBytes,
        public array $allowedMimes,
        public int $ttlSeconds = 600,
    ) {}

    public static function forPhotos(): self
    {
        return new self(
            maxSizeBytes: (int) config('media.photos.max_size_bytes', 15 * 1024 * 1024),
            allowedMimes: (array) config('media.photos.allowed_mimes', ['image/jpeg', 'image/png', 'image/webp', 'image/heic']),
            ttlSeconds: (int) config('media.photos.signature_ttl_seconds', 600),
        );
    }

    public static function forVideos(): self
    {
        return new self(
            maxSizeBytes: (int) config('media.videos.max_size_bytes', 100 * 1024 * 1024),
            allowedMimes: (array) config('media.videos.allowed_mimes', ['video/mp4', 'video/quicktime']),
            ttlSeconds: (int) config('media.videos.signature_ttl_seconds', 900),
        );
    }

    /**
     * Formats à signer dans `allowed_formats`.
     *
     * Sans ce paramètre, une signature obtenue pour un JPEG autorise l'envoi de
     * n'importe quel fichier : la validation côté API ne porte que sur ce que le
     * navigateur déclare, et Cloudinary n'a alors aucune contrainte à opposer.
     *
     * @return list<string>
     */
    public function cloudinaryFormats(): array
    {
        $formats = [];

        foreach ($this->allowedMimes as $mime) {
            foreach (self::CLOUDINARY_FORMATS[$mime] ?? [] as $format) {
                $formats[] = $format;
            }
        }

        return array_values(array_unique($formats));
    }
}
