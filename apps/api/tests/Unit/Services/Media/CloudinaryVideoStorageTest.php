<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Media;

use App\Data\Media\UploadConstraints;
use App\Services\Media\CloudinaryVideoStorage;
use Tests\TestCase;

final class CloudinaryVideoStorageTest extends TestCase
{
    private CloudinaryVideoStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = new CloudinaryVideoStorage(
            cloudName: 'test-cloud',
            apiKey: '123456789',
            apiSecret: 'secret_abc_123',
            secure: true,
        );
    }

    public function test_signed_upload_params_target_the_video_endpoint(): void
    {
        $signed = $this->storage->signedUploadParams(
            'mecano/cars/12/videos/abc',
            UploadConstraints::forVideos(),
        );

        $this->assertSame('https://api.cloudinary.com/v1_1/test-cloud/video/upload', $signed->uploadUrl);
        $this->assertSame('mecano/cars/12/videos/abc', $signed->storageKey);
        $this->assertSame($signed->storageKey, $signed->fields['public_id']);
        $this->assertArrayNotHasKey('folder', $signed->fields);
    }

    /**
     * Sans `allowed_formats` signé, une signature obtenue pour un MP4 laisserait
     * envoyer n'importe quel fichier : la seule barrière serait le type MIME
     * annoncé par le navigateur, que l'appelant contrôle entièrement.
     */
    public function test_signature_covers_the_path_and_the_allowed_formats(): void
    {
        $constraints = new UploadConstraints(
            maxSizeBytes: 100 * 1024 * 1024,
            allowedMimes: ['video/mp4', 'video/quicktime'],
            ttlSeconds: 900,
        );

        $signed = $this->storage->signedUploadParams('mecano/cars/12/videos/abc', $constraints);
        $fields = $signed->fields;

        $this->assertSame('mp4,mov', $fields['allowed_formats']);

        // Paramètres triés par ordre alphabétique, comme l'exige Cloudinary.
        $expected = sha1(
            "allowed_formats={$fields['allowed_formats']}"
            ."&public_id={$fields['public_id']}"
            ."&timestamp={$fields['timestamp']}"
            .'secret_abc_123'
        );

        $this->assertSame($expected, $fields['signature']);
    }

    public function test_public_url_lets_cloudinary_choose_container_and_bitrate(): void
    {
        $this->assertSame(
            'https://res.cloudinary.com/test-cloud/video/upload/f_auto,q_auto/mecano/cars/12/videos/abc',
            $this->storage->publicUrl('mecano/cars/12/videos/abc'),
        );
    }
}
