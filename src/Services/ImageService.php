<?php
declare(strict_types=1);

namespace DevAppPro\Services;

/**
 * Služba pro generování náhledů obrázků a konverzi na WebP.
 * Používá GD knihovnu (dostupná s WebP podporou).
 */
class ImageService
{
    private const THUMB_SIZE = 200;
    private const MEDIUM_SIZE = 800;
    private const WEBP_QUALITY = 82;

    /**
     * Zpracuje obrázek: vygeneruje thumbnail (200x200) a medium (800x800) ve WebP.
     * Pokud je vstup JPG, originál se také konvertuje na WebP.
     *
     * @return array{thumbnail_path: ?string, medium_path: ?string, storage_path: ?string, stored_name: ?string, mime_type: ?string, size_bytes: ?int}
     *   Vrací aktualizované cesty/pole, nebo null hodnoty pokud není obrázek.
     */
    public function processImage(
        string $absPath,
        string $storagePath,
        string $storedName,
        string $ext,
        string $mime
    ): array {
        $ext = strtolower($ext);

        if (!$this->isSupportedImage($ext, $mime)) {
            return [
                'thumbnail_path' => null,
                'medium_path' => null,
                'storage_path' => $storagePath,
                'stored_name' => $storedName,
                'mime_type' => $mime,
                'size_bytes' => null,
            ];
        }

        // Načtení zdrojového obrázku
        $source = $this->loadImage($absPath, $ext);
        if ($source === null) {
            return [
                'thumbnail_path' => null,
                'medium_path' => null,
                'storage_path' => $storagePath,
                'stored_name' => $storedName,
                'mime_type' => $mime,
                'size_bytes' => null,
            ];
        }

        $origWidth = imagesx($source);
        $origHeight = imagesy($source);

        // Cesty pro náhledy (vedle originálu, s příponou _thumb.webp a _medium.webp)
        $dir = dirname($absPath);
        $baseName = pathinfo($storedName, PATHINFO_FILENAME);
        $thumbName = $baseName . '_thumb.webp';
        $mediumName = $baseName . '_medium.webp';
        $thumbAbsPath = $dir . '/' . $thumbName;
        $mediumAbsPath = $dir . '/' . $mediumName;

        $relDir = dirname($storagePath);
        $thumbRelPath = $relDir . '/' . $thumbName;
        $mediumRelPath = $relDir . '/' . $mediumName;

        // Generovat thumbnail 200x200 (crop na čtverec)
        $this->generateThumbnail($source, $origWidth, $origHeight, $thumbAbsPath);

        // Generovat medium 800x800 (fit, zachovat poměr stran)
        $this->generateMedium($source, $origWidth, $origHeight, $mediumAbsPath);



        // Konverze JPG na WebP (originál)
        $resultStoragePath = $storagePath;
        $resultStoredName = $storedName;
        $resultMime = $mime;
        $resultSize = null;

        if ($ext === 'jpg' || $ext === 'jpeg') {
            $webpName = $baseName . '.webp';
            $webpAbsPath = $dir . '/' . $webpName;
            $webpRelPath = $relDir . '/' . $webpName;

            $srcForWebp = $this->loadImage($absPath, $ext);
            if ($srcForWebp !== null) {
                if (imagewebp($srcForWebp, $webpAbsPath, self::WEBP_QUALITY)) {
                    // Smazat původní JPG
                    @unlink($absPath);
                    $resultStoragePath = $webpRelPath;
                    $resultStoredName = $webpName;
                    $resultMime = 'image/webp';
                    $resultSize = filesize($webpAbsPath) ?: null;

                }
            }
        } else {
            $resultSize = filesize($absPath) ?: null;
        }

        return [
            'thumbnail_path' => $thumbRelPath,
            'medium_path' => $mediumRelPath,
            'storage_path' => $resultStoragePath,
            'stored_name' => $resultStoredName,
            'mime_type' => $resultMime,
            'size_bytes' => $resultSize,
        ];
    }

    private function isSupportedImage(string $ext, string $mime): bool
    {
        $supported = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
        return in_array($ext, $supported, true) && strpos($mime, 'image/') === 0;
    }

    private function loadImage(string $path, string $ext): ?\GdImage
    {
        switch ($ext) {
            case 'png':
                $img = imagecreatefrompng($path);
                break;
            case 'jpg':
            case 'jpeg':
                $img = imagecreatefromjpeg($path);
                break;
            case 'webp':
                $img = imagecreatefromwebp($path);
                break;
            case 'gif':
                $img = imagecreatefromgif($path);
                break;
            default:
                return null;
        }
        return $img === false ? null : $img;
    }

    /**
     * Vygeneruje čtvercový thumbnail (crop na střed).
     */
    private function generateThumbnail(\GdImage $source, int $origW, int $origH, string $outPath): void
    {
        $size = self::THUMB_SIZE;
        $thumb = imagecreatetruecolor($size, $size);

        // Průhlednost pro PNG
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
        imagefilledrectangle($thumb, 0, 0, $size, $size, $transparent);

        // Crop na čtverec ze středu
        $minDim = min($origW, $origH);
        $srcX = (int) (($origW - $minDim) / 2);
        $srcY = (int) (($origH - $minDim) / 2);

        imagecopyresampled($thumb, $source, 0, 0, $srcX, $srcY, $size, $size, $minDim, $minDim);
        imagewebp($thumb, $outPath, self::WEBP_QUALITY);

    }

    /**
     * Vygeneruje medium náhled (fit do 800x800, zachovat poměr stran).
     */
    private function generateMedium(\GdImage $source, int $origW, int $origH, string $outPath): void
    {
        $maxSize = self::MEDIUM_SIZE;

        // Pokud je obrázek menší než max, nezvětšovat
        if ($origW <= $maxSize && $origH <= $maxSize) {
            $newW = $origW;
            $newH = $origH;
        } else {
            $ratio = min($maxSize / $origW, $maxSize / $origH);
            $newW = (int) round($origW * $ratio);
            $newH = (int) round($origH * $ratio);
        }

        $medium = imagecreatetruecolor($newW, $newH);
        imagealphablending($medium, false);
        imagesavealpha($medium, true);
        $transparent = imagecolorallocatealpha($medium, 0, 0, 0, 127);
        imagefilledrectangle($medium, 0, 0, $newW, $newH, $transparent);

        imagecopyresampled($medium, $source, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagewebp($medium, $outPath, self::WEBP_QUALITY);

    }
}
