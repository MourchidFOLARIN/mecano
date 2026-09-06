<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Media;

use App\Data\Media\UploadConstraints;
use App\Enums\MediaKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class CreateUploadSignatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'car_id' => ['required', 'integer', 'exists:cars,id'],
            'kind' => ['required', 'string', Rule::enum(MediaKind::class)],
            'mime' => ['required', 'string'],
            'bytes' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'car_id.required' => 'Le véhicule (car_id) est obligatoire.',
            'car_id.exists' => 'Le véhicule sélectionné n\'existe pas.',
            'kind.required' => 'Le type de média (kind) est obligatoire.',
            'kind.enum' => 'Le type de média doit être "photo" ou "video".',
            'mime.required' => 'Le type MIME est obligatoire.',
            'bytes.required' => 'La taille en octets est obligatoire.',
            'bytes.min' => 'La taille du fichier doit être supérieure à 0 octet.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $kind = $this->input('kind');
                $mime = (string) $this->input('mime');
                $bytes = (int) $this->input('bytes');

                if ($kind === MediaKind::Photo->value) {
                    $constraints = UploadConstraints::forPhotos();

                    if (! in_array($mime, $constraints->allowedMimes, true)) {
                        $validator->errors()->add('mime', 'Format d\'image non supporté. Formats acceptés : '.implode(', ', $constraints->allowedMimes));
                    }

                    if ($bytes > $constraints->maxSizeBytes) {
                        $validator->errors()->add('bytes', 'La taille de l\'image dépasse la limite maximale autorisée ('.self::megabytes($constraints->maxSizeBytes).').');
                    }
                } elseif ($kind === MediaKind::Video->value) {
                    $constraints = UploadConstraints::forVideos();

                    if (! in_array($mime, $constraints->allowedMimes, true)) {
                        $validator->errors()->add('mime', 'Format de vidéo non supporté. Formats acceptés : '.implode(', ', $constraints->allowedMimes));
                    }

                    if ($bytes > $constraints->maxSizeBytes) {
                        $validator->errors()->add('bytes', 'La taille de la vidéo dépasse la limite maximale autorisée ('.self::megabytes($constraints->maxSizeBytes).').');
                    }
                }
            },
        ];
    }

    /**
     * Le plafond est annoncé à partir de la configuration, jamais écrit en dur :
     * un message figé à « 200 Mo » alors que la limite est passée à 100 Mo
     * envoie le mécanicien réessayer un fichier que l'API refusera encore.
     */
    private static function megabytes(int $bytes): string
    {
        return round($bytes / (1024 * 1024)).' Mo';
    }
}
