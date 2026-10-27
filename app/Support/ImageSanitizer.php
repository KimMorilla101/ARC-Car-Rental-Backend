<?php

namespace App\Support;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Re-encodes uploaded JPEG/PNG images with GD. The result contains only pixels: EXIF/GPS metadata
 * and anything hidden or appended in the original file are dropped. Used for vehicle photos and for
 * renters' private documents.
 */
class ImageSanitizer
{
    /** Largest accepted side in pixels. 6000×6000 RGBA stays well within PHP's memory limit. */
    public const MAX_DIMENSION = 6000;

    /**
     * Validation for a document photo: JPEG or PNG by content, at most 5 MB.
     *
     * @return array<int, string>
     */
    public static function documentRules(): array
    {
        return [
            'required', 'file', 'mimes:jpg,jpeg,png', 'mimetypes:image/jpeg,image/png', 'max:5120',
            'dimensions:min_width=200,min_height=200,max_width='.self::MAX_DIMENSION.',max_height='.self::MAX_DIMENSION,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function documentMessages(string $field): array
    {
        $type = 'Only JPEG or PNG photos are accepted. HEIC and WebP photos are not supported; please convert them to JPEG first.';

        return [
            "{$field}.mimes" => $type,
            "{$field}.mimetypes" => $type,
            "{$field}.max" => 'The photo must not be larger than 5 MB.',
            "{$field}.dimensions" => 'The photo must be between 200×200 and '.self::MAX_DIMENSION.'×'.self::MAX_DIMENSION.' pixels.',
        ];
    }

    /**
     * @return array{contents: string, extension: string}
     *
     * @throws ValidationException When the file is not a readable image.
     */
    public function sanitize(UploadedFile $file, string $field): array
    {
        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if (! $image instanceof GdImage) {
            throw ValidationException::withMessages([$field => 'The image could not be read. Please upload a valid JPEG or PNG image.']);
        }

        $isPng = $file->getMimeType() === 'image/png';

        if (! $isPng) {
            $image = $this->applyExifOrientation($image, $file->getRealPath());
        }

        ob_start();
        if ($isPng) {
            imagesavealpha($image, true);
            imagepng($image, null, 6);
        } else {
            imagejpeg($image, null, 88);
        }

        return ['contents' => (string) ob_get_clean(), 'extension' => $isPng ? 'png' : 'jpg'];
    }

    /**
     * Applies the camera's rotation first, so phone photos stay upright once the tag is gone.
     */
    private function applyExifOrientation(GdImage $image, string $path): GdImage
    {
        $orientation = function_exists('exif_read_data') ? (@exif_read_data($path)['Orientation'] ?? 1) : 1;

        $angle = match ((int) $orientation) {
            3 => 180,
            6 => 270,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        return $rotated instanceof GdImage ? $rotated : $image;
    }
}
