<?php

function uploadProfilePhoto(array $upload): array
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['path' => null, 'error' => null];
    }
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
        return ['path' => null, 'error' => 'Profile photo upload failed. Please try again.'];
    }
    if ($upload['size'] > 2 * 1024 * 1024) {
        return ['path' => null, 'error' => 'Profile photo must be 2 MB or smaller.'];
    }

    $imageInfo = @getimagesize($upload['tmp_name']);
    $extensionByMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mimeType = $imageInfo['mime'] ?? '';
    if (!isset($extensionByMime[$mimeType])) {
        return ['path' => null, 'error' => 'Profile photo must be a JPG, PNG, or WebP image.'];
    }

    $directory = __DIR__ . '/../assets/uploads/profiles';
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
        return ['path' => null, 'error' => 'Profile photo storage is unavailable.'];
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $extensionByMime[$mimeType];
    if (!move_uploaded_file($upload['tmp_name'], $directory . '/' . $filename)) {
        return ['path' => null, 'error' => 'Profile photo could not be saved.'];
    }

    return ['path' => '/assets/uploads/profiles/' . $filename, 'error' => null];
}