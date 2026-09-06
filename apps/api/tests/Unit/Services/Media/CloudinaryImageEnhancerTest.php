<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Media;

use App\Enums\MediaProvider;
use App\Models\Media;
use App\Services\Media\CloudinaryImageEnhancer;
use RuntimeException;
use Tests\TestCase;

final class CloudinaryImageEnhancerTest extends TestCase
{
    private CloudinaryImageEnhancer $enhancer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enhancer = new CloudinaryImageEnhancer(cloudName: 'test-cloud');
    }

    private function media(): Media
    {
        $media = new Media;
        $media->id = 42;
        $media->storage_key = 'mecano/cars/7/photo';

        return $media;
    }

    public function test_auto_improve_is_a_url_transformation(): void
    {
        $result = $this->enhancer->autoImprove($this->media());

        $this->assertSame(MediaProvider::Cloudinary, $result->provider);
        $this->assertSame(0, $result->costUnits);
        $this->assertStringContainsString('e_improve', $result->resultUrl);
        $this->assertStringEndsWith('mecano/cars/7/photo', $result->resultUrl);
    }

    public function test_smart_crop_is_a_url_transformation(): void
    {
        $result = $this->enhancer->smartCrop($this->media(), 800, 600);

        $this->assertSame(MediaProvider::Cloudinary, $result->provider);
        $this->assertStringContainsString('c_fill,g_auto,w_800,h_600', $result->resultUrl);
    }

    /**
     * `e_background_removal` est un add-on payant, écarté par l'ADR 0006.
     * Renvoyer son URL produisait un dérivé annoncé « prêt » sans rien avoir
     * vérifié : approuvé, il devenait la photo publiée de l'annonce.
     */
    public function test_background_removal_refuses_instead_of_returning_a_paid_addon_url(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('remove.bg');

        $this->enhancer->removeBackground($this->media());
    }
}
