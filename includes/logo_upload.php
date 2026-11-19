<?php
// Logo upload helpers shared by subscriptions, contracts and insurances (moved from endpoints/subscription/add.php).
// Expects ssrf_helper.php to be loaded (getLogoFromUrl fetches remote images).

function sanitizeFilename($filename)
{
    $filename = preg_replace("/[^a-zA-Z0-9\s]/", "", $filename);
    $filename = str_replace(" ", "-", $filename);
    $filename = str_replace(".", "", $filename);
    return $filename;
}

function validateFileExtension($fileExtension)
{
    $allowedExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
    return in_array($fileExtension, $allowedExtensions);
}

function getLogoFromUrl($url, $uploadDir, $name, $settings, $i18n)
{
    $maxRedirects = 3;
    $currentUrl = $url;

    for ($i = 0; $i <= $maxRedirects; $i++) {
        if (!filter_var($currentUrl, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $currentUrl)) {
            return ['success' => false, 'message' => 'Invalid URL format.'];
        }

        $parts = parse_url($currentUrl);
        $host = $parts['host'];
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        
        $ip = gethostbyname($host);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false 
            || is_cgnat_ip($ip)) {
            return ['success' => false, 'message' => 'Invalid IP Address.'];
        }

        $ch = curl_init($currentUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // Manual handling to re-validate IPs
        
        curl_setopt($ch, CURLOPT_RESOLVE, ["$host:$port:$ip"]);

        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode >= 300 && $httpCode < 400) {
            $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            unset($ch);

            if (!$redirectUrl) {
                break;
            }

            $currentUrl = $redirectUrl;
            continue; 
        }

        if ($imageData !== false && $httpCode === 200) {
            $timestamp = time();
            $fileName = $timestamp . '-' . sanitizeFilename($name) . '.png';
            $uploadFile = $uploadDir . $fileName; // Note: Use the provided $uploadDir variable

            if (saveLogo($imageData, $uploadFile, $name, $settings)) {
                unset($ch);
                return ['success' => true, 'filename' => $fileName];
            }

            unset($ch);
            return ['success' => false, 'message' => translate('error_saving_logo', $i18n)];
        }

        $error = curl_error($ch);
        unset($ch);
        return ['success' => false, 'message' => translate('error_fetching_image', $i18n) . ': ' . $error];
    }

    return ['success' => false, 'message' => translate('error_fetching_image', $i18n)];
}

function saveLogo($imageData, $uploadFile, $name, $settings)
{
    $image = imagecreatefromstring($imageData);
    $removeBackground = isset($settings['removeBackground']) && $settings['removeBackground'] === 'true';

    if ($image !== false) {
        $tempFile = tempnam(sys_get_temp_dir(), 'logo');

        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagepng($image, $tempFile);
        imagedestroy($image);

        $newImage = imagecreatefrompng($tempFile);
        if ($newImage !== false) {
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);

            if ($removeBackground) {
                require_once __DIR__ . '/gd_background_removal.php';
                // On palette images imagecolorat() returns palette indexes, not RGB values
                if (!imageistruecolor($newImage)) {
                    imagepalettetotruecolor($newImage);
                    imagealphablending($newImage, false);
                    imagesavealpha($newImage, true);
                }
                // Paint out the corner color with ~10% fuzz
                $corner = imagecolorat($newImage, 0, 0);
                if ((($corner >> 24) & 0x7F) !== 127) {
                    gdRemoveBackgroundColor($newImage, ($corner >> 16) & 0xFF, ($corner >> 8) & 0xFF, $corner & 0xFF);
                }
            }

            // Crop/trim transparent margins
            require_once __DIR__ . '/gd_background_removal.php';
            $newImage = gdCropTransparent($newImage, 2);

            $saved = imagepng($newImage, $uploadFile);
            imagedestroy($newImage);
        } else {
            unlink($tempFile);
            return false;
        }

        unlink($tempFile);
        return $saved;
    }

    return false;
}

function resizeAndUploadLogo($uploadedFile, $uploadDir, $name, $settings)
{
    $targetWidth = 135;
    $targetHeight = 42;

    $timestamp = time();
    $originalFileName = $uploadedFile['name'];
    $fileExtension = pathinfo($originalFileName, PATHINFO_EXTENSION);
    $fileExtension = validateFileExtension($fileExtension) ? $fileExtension : 'png';
    $fileName = $timestamp . '-' . sanitizeFilename($name) . '.' . $fileExtension;
    $uploadFile = $uploadDir . $fileName;

    if (move_uploaded_file($uploadedFile['tmp_name'], $uploadFile)) {
        $fileInfo = getimagesize($uploadFile);

        if ($fileInfo !== false) {
            $width = $fileInfo[0];
            $height = $fileInfo[1];

            if ($fileExtension === 'png') {
                $image = imagecreatefrompng($uploadFile);
            } elseif ($fileExtension === 'jpg' || $fileExtension === 'jpeg') {
                $image = imagecreatefromjpeg($uploadFile);
            } elseif ($fileExtension === 'gif') {
                $image = imagecreatefromgif($uploadFile);
            } elseif ($fileExtension === 'webp') {
                $image = imagecreatefromwebp($uploadFile);
            } else {
                return "";
            }

            if ($fileExtension === 'png') {
                imagesavealpha($image, true);
            }

            // Crop/trim transparent margins (ensure we update dimensions after cropping)
            require_once __DIR__ . '/gd_background_removal.php';
            $image = gdCropTransparent($image, 2);
            $width = imagesx($image);
            $height = imagesy($image);

            $newWidth = $width;
            $newHeight = $height;

            if ($width > $targetWidth) {
                $newWidth = (int) $targetWidth;
                $newHeight = (int) (($targetWidth / $width) * $height);
            }

            if ($newHeight > $targetHeight) {
                $newWidth = (int) (($targetHeight / $newHeight) * $newWidth);
                $newHeight = (int) $targetHeight;
            }

            $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
            imagesavealpha($resizedImage, true);
            $transparency = imagecolorallocatealpha($resizedImage, 0, 0, 0, 127);
            imagefill($resizedImage, 0, 0, $transparency);
            imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            if ($fileExtension === 'png') {
                $saved = imagepng($resizedImage, $uploadFile);
            } elseif ($fileExtension === 'jpg' || $fileExtension === 'jpeg') {
                $saved = imagejpeg($resizedImage, $uploadFile);
            } elseif ($fileExtension === 'gif') {
                $saved = imagegif($resizedImage, $uploadFile);
            } elseif ($fileExtension === 'webp') {
                $saved = imagewebp($resizedImage, $uploadFile);
            } else {
                return "";
            }

            imagedestroy($image);
            imagedestroy($resizedImage);

            if (!$saved) {
                if (file_exists($uploadFile)) {
                    unlink($uploadFile);
                }
                return "";
            }

            return $fileName;
        }
    }

    return "";
}
