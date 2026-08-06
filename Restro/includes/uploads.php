<?php
/**
 * File-upload helpers shared by the tenant menu page (dish photos)
 * and the admin AR panel (.glb models).
 *
 * Both write into /uploads, which ships an .htaccess turning PHP
 * execution off — nothing dropped in there is ever executable.
 * Stored paths are relative to the project root ("uploads/dishes/x.jpg"),
 * so pages one level down prefix them with "../".
 */

define('UPLOAD_ROOT', dirname(__DIR__, 2) . '/uploads');
define('MAX_PHOTO_BYTES', 4 * 1024 * 1024);   // 4 MB
define('MAX_MODEL_BYTES', 30 * 1024 * 1024);  // 30 MB

/**
 * Validate and store a dish photo.
 * Returns ['ok'=>true,'path'=>...] or ['ok'=>false,'error'=>...].
 */
function store_dish_photo(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'No file uploaded.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed (the file may be larger than the server allows).'];
    }
    if ($file['size'] > MAX_PHOTO_BYTES) {
        return ['ok' => false, 'error' => 'Photo must be under 4 MB.'];
    }

    // Trust the decoded image, not the filename or the browser's MIME.
    $info = @getimagesize($file['tmp_name']);
    if (!$info) {
        return ['ok' => false, 'error' => 'That file is not a valid image.'];
    }
    $ext = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF  => 'gif',
    ][$info[2]] ?? null;
    if (!$ext) {
        return ['ok' => false, 'error' => 'Use a JPG, PNG, WEBP or GIF image.'];
    }

    $dir = UPLOAD_ROOT . '/dishes';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['ok' => false, 'error' => 'Upload folder is not writable.'];
    }

    $name = 'dish_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        return ['ok' => false, 'error' => 'Could not save the photo.'];
    }
    return ['ok' => true, 'path' => 'uploads/dishes/' . $name];
}

/**
 * Validate and store a .glb / .gltf model.
 * Binary glTF starts with the magic word "glTF"; .gltf is JSON.
 */
function store_ar_model(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'No file uploaded.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed (the file may be larger than the server allows).'];
    }
    if ($file['size'] > MAX_MODEL_BYTES) {
        return ['ok' => false, 'error' => 'Model must be under 30 MB.'];
    }

    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($ext, ['glb', 'gltf'], true)) {
        return ['ok' => false, 'error' => 'Upload a .glb or .gltf file.'];
    }

    $head = (string) @file_get_contents($file['tmp_name'], false, null, 0, 4);
    if ($ext === 'glb' && $head !== 'glTF') {
        return ['ok' => false, 'error' => 'That .glb is not a valid binary glTF file.'];
    }
    if ($ext === 'gltf' && ltrim($head)[0] !== '{') {
        return ['ok' => false, 'error' => 'That .gltf is not valid JSON glTF.'];
    }

    $dir = UPLOAD_ROOT . '/models';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['ok' => false, 'error' => 'Upload folder is not writable.'];
    }

    $name = 'model_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        return ['ok' => false, 'error' => 'Could not save the model.'];
    }
    return ['ok' => true, 'path' => 'uploads/models/' . $name];
}

/**
 * Turn a stored asset reference into a URL usable from a page one level
 * below the project root. Absolute URLs (legacy externally-hosted models)
 * pass through untouched; our own "uploads/..." paths get the ../ prefix.
 */
function asset_url(?string $path): string {
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^(https?:)?//#i', $path) || $path[0] === '/') {
        return $path;
    }
    return '../' . $path;
}

/** Delete a previously stored upload, ignoring anything outside /uploads. */
function delete_upload(?string $stored_path): void {
    if (!$stored_path || strpos($stored_path, 'uploads/') !== 0 || strpos($stored_path, '..') !== false) {
        return;
    }
    $full = dirname(UPLOAD_ROOT) . '/' . $stored_path;
    if (is_file($full)) {
        @unlink($full);
    }
}
