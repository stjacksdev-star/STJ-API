<?php

namespace App\Services\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class ImageOptimizer
{
    private const MAX_WIDTH = 1280;
    private const MAX_HEIGHT = 900;
    private const JPEG_QUALITY = 78;
    private const WEBP_QUALITY = 78;
    private const PNG_COMPRESSION = 7;

    public function optimize(UploadedFile $file): OptimizedImage
    {
        $info = getimagesize($file->getRealPath());

        if ($info === false) {
            throw ValidationException::withMessages([
                'banner' => 'No fue posible leer la imagen.',
            ]);
        }

        [$width, $height] = $info;
        $mime = (string) ($info['mime'] ?? '');
        $image = $this->createImage($file->getRealPath(), $mime);

        if (! $image) {
            throw ValidationException::withMessages([
                'banner' => 'El formato de imagen no es soportado para compresion.',
            ]);
        }

        if ($mime === 'image/jpeg') {
            $image = $this->applyJpegOrientation($image, $file->getRealPath());
            $width = imagesx($image);
            $height = imagesy($image);
        }

        [$targetWidth, $targetHeight] = $this->targetDimensions($width, $height);
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        if (in_array($mime, ['image/png', 'image/webp'], true)) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefill($canvas, 0, 0, $transparent);
        }

        imagecopyresampled(
            $canvas,
            $image,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height,
        );

        $extension = $this->extension($mime);
        $basePath = tempnam(sys_get_temp_dir(), 'stj_img_');
        $path = $basePath.'.'.$extension;

        if (is_file($basePath)) {
            unlink($basePath);
        }

        $written = match ($mime) {
            'image/jpeg' => imagejpeg($canvas, $path, self::JPEG_QUALITY),
            'image/png' => imagepng($canvas, $path, self::PNG_COMPRESSION),
            'image/webp' => imagewebp($canvas, $path, self::WEBP_QUALITY),
            'image/gif' => imagegif($canvas, $path),
            default => false,
        };

        imagedestroy($image);
        imagedestroy($canvas);

        if (! $written) {
            throw ValidationException::withMessages([
                'banner' => 'No fue posible comprimir la imagen.',
            ]);
        }

        return new OptimizedImage($path, $extension, $mime);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function targetDimensions(int $width, int $height): array
    {
        if ($width <= self::MAX_WIDTH && $height <= self::MAX_HEIGHT) {
            return [$width, $height];
        }

        $ratio = min(self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height);

        return [
            (int) ceil($width * $ratio),
            (int) ceil($height * $ratio),
        ];
    }

    private function createImage(string $path, string $mime): mixed
    {
        return match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/png' => imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : false,
            'image/gif' => imagecreatefromgif($path),
            default => false,
        };
    }

    private function extension(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };
    }

    private function applyJpegOrientation(mixed $image, string $path): mixed
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated ?: $image;
    }
}
