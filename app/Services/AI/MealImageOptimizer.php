<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Downscales meal images before upload to speed up Kaman API item creation.
 */
final class MealImageOptimizer
{
    private const int MAX_DIMENSION = 1200;

    private const int JPEG_QUALITY = 82;

    /**
     * @return array{path: string, temporary: bool}
     */
    public static function optimizeForUpload(string $sourcePath): array
    {
        if (!is_file($sourcePath) || !function_exists('imagecreatefromstring')) {
            return ['path' => $sourcePath, 'temporary' => false];
        }

        $info = @getimagesize($sourcePath);
        if ($info === false) {
            return ['path' => $sourcePath, 'temporary' => false];
        }

        [$width, $height] = $info;
        if ($width <= self::MAX_DIMENSION && $height <= self::MAX_DIMENSION && ($info[2] ?? 0) === IMAGETYPE_JPEG) {
            $fileSize = filesize($sourcePath) ?: 0;
            if ($fileSize > 0 && $fileSize < 400_000) {
                return ['path' => $sourcePath, 'temporary' => false];
            }
        }

        $bytes = @file_get_contents($sourcePath);
        if ($bytes === false) {
            return ['path' => $sourcePath, 'temporary' => false];
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return ['path' => $sourcePath, 'temporary' => false];
        }

        $newWidth = $width;
        $newHeight = $height;
        $maxSide = max($width, $height);

        if ($maxSide > self::MAX_DIMENSION) {
            $ratio = self::MAX_DIMENSION / $maxSide;
            $newWidth = (int) round($width * $ratio);
            $newHeight = (int) round($height * $ratio);
        }

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        if ($canvas === false) {
            imagedestroy($image);

            return ['path' => $sourcePath, 'temporary' => false];
        }

        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);

        $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'meal_' . uniqid('', true) . '.jpg';
        $saved = imagejpeg($canvas, $tempPath, self::JPEG_QUALITY);
        imagedestroy($canvas);

        if (!$saved || !is_file($tempPath)) {
            return ['path' => $sourcePath, 'temporary' => false];
        }

        return ['path' => $tempPath, 'temporary' => true];
    }
}
