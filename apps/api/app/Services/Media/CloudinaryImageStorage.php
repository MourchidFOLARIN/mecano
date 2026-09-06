<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Data\Media\ObjectMeta;
use App\Data\Media\SignedUpload;
use App\Data\Media\UploadConstraints;
use App\Enums\ImageTransformPreset;
use App\Support\Contracts\ImageStorage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final readonly class CloudinaryImageStorage implements ImageStorage
{
    public function __construct(
        private string $cloudName,
        private string $apiKey,
        private string $apiSecret,
        private bool $secure = true,
    ) {}

    /**
     * Create an instance configured from config/media.php.
     */
    public static function fromConfig(): self
    {
        return new self(
            cloudName: (string) config('media.cloudinary.cloud_name', ''),
            apiKey: (string) config('media.cloudinary.api_key', ''),
            apiSecret: (string) config('media.cloudinary.api_secret', ''),
            secure: (bool) config('media.cloudinary.secure', true),
        );
    }

    /**
     * Generate restrictive signed upload parameters for direct client upload to Cloudinary.
     *
     * La signature couvre le chemin **et** les formats acceptés : Cloudinary
     * refuse lui-même un fichier hors contrainte, au lieu de s'en remettre au
     * type MIME que le navigateur déclare (CDC §4, intégrations externes).
     *
     * La taille maximale, elle, n'est pas signable : l'API d'upload ne l'expose
     * que via un `upload_preset` défini dans la console Cloudinary. Elle reste
     * donc vérifiée à la demande de signature, et bornée par le plafond de
     * 100 Mo que Cloudinary applique à tout envoi en une seule requête.
     *
     * Le dossier est porté par le `public_id`, jamais par le paramètre `folder` :
     * Cloudinary préfixe le `public_id` reçu par le `folder` quand les deux sont
     * envoyés. Le chemin se retrouvait alors dupliqué chez l'hébergeur
     * (`mecano/cars/12/mecano/cars/12/{uuid}`) alors que l'API renvoyait le
     * chemin simple, et la confirmation ne retrouvait plus l'objet.
     */
    public function signedUploadParams(string $folder, UploadConstraints $constraints): SignedUpload
    {
        $timestamp = now()->timestamp;
        $uniqueId = (string) Str::uuid();
        $publicId = trim($folder, '/')."/{$uniqueId}";
        $allowedFormats = implode(',', $constraints->cloudinaryFormats());

        // Parameters to sign in alphabetical order
        $paramsToSign = [
            'allowed_formats' => $allowedFormats,
            'public_id' => $publicId,
            'timestamp' => $timestamp,
        ];

        ksort($paramsToSign);

        $toSignString = collect($paramsToSign)
            ->map(static fn ($val, $key) => "{$key}={$val}")
            ->implode('&');

        $signature = sha1($toSignString.$this->apiSecret);

        $uploadUrl = "https://api.cloudinary.com/v1_1/{$this->cloudName}/image/upload";

        $fields = [
            'api_key' => $this->apiKey,
            'timestamp' => $timestamp,
            'public_id' => $publicId,
            'allowed_formats' => $allowedFormats,
            'signature' => $signature,
        ];

        return new SignedUpload(
            uploadUrl: $uploadUrl,
            fields: $fields,
            storageKey: $publicId,
            expiresAt: now()->addSeconds($constraints->ttlSeconds)->toIso8601String(),
        );
    }

    /**
     * Generate a CDN derivative URL for a stored image.
     */
    public function derivativeUrl(string $key, ImageTransformPreset|string $preset): string
    {
        $presetKey = $preset instanceof ImageTransformPreset ? $preset->value : $preset;
        $transform = (string) config("media.transform_presets.{$presetKey}", '');

        if ($transform === '' && $preset instanceof ImageTransformPreset) {
            $transform = $preset->defaultTransformation();
        }

        $scheme = $this->secure ? 'https' : 'http';
        $trimmedKey = ltrim($key, '/');

        if ($transform !== '') {
            return "{$scheme}://res.cloudinary.com/{$this->cloudName}/image/upload/{$transform}/{$trimmedKey}";
        }

        return "{$scheme}://res.cloudinary.com/{$this->cloudName}/image/upload/{$trimmedKey}";
    }

    /**
     * Verify if the object exists and return its metadata using Cloudinary Resource API.
     */
    public function exists(string $key): ?ObjectMeta
    {
        $trimmedKey = ltrim($key, '/');
        $url = "https://api.cloudinary.com/v1_1/{$this->cloudName}/resources/image/upload/{$trimmedKey}";

        try {
            $response = Http::withBasicAuth($this->apiKey, $this->apiSecret)
                ->timeout(5)
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();
            $bytes = (int) ($data['bytes'] ?? 0);
            $format = (string) ($data['format'] ?? 'jpeg');
            $mimeType = match ($format) {
                'png' => 'image/png',
                'webp' => 'image/webp',
                'heic' => 'image/heic',
                default => 'image/jpeg',
            };

            return new ObjectMeta(
                key: $trimmedKey,
                sizeBytes: $bytes,
                mimeType: $mimeType,
                width: isset($data['width']) ? (int) $data['width'] : null,
                height: isset($data['height']) ? (int) $data['height'] : null,
                url: (string) ($data['secure_url'] ?? $this->derivativeUrl($trimmedKey, '')),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Delete an image from Cloudinary storage.
     */
    public function delete(string $key): void
    {
        $trimmedKey = ltrim($key, '/');
        $timestamp = now()->timestamp;

        $paramsToSign = [
            'public_id' => $trimmedKey,
            'timestamp' => $timestamp,
        ];
        ksort($paramsToSign);

        $toSignString = collect($paramsToSign)
            ->map(static fn ($val, $key) => "{$key}={$val}")
            ->implode('&');

        $signature = sha1($toSignString.$this->apiSecret);

        try {
            Http::timeout(5)->post("https://api.cloudinary.com/v1_1/{$this->cloudName}/image/destroy", [
                'public_id' => $trimmedKey,
                'api_key' => $this->apiKey,
                'timestamp' => $timestamp,
                'signature' => $signature,
            ]);
        } catch (\Throwable) {
            // Ignored on network failure during purge
        }
    }
}
