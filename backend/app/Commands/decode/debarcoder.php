<?php

/**
 * Start a session and set the maximum execution time for the script.
 */
session_start();
set_time_limit(300);

/**
 * Load configuration and service files necessary for the bot.
 */
require_once __DIR__ . '/../../config/bot_config.php';
require_once __DIR__ . '/../../Services/HttpClient.php';
require_once __DIR__ . '/../../Handlers/CurlHelper.php';

/**
 * Load the appropriate localization messages based on the user's language choice.
 */
$userLanguage = $_SESSION['language'] ?? 'en'; // Default to English if no session variable
$localizationPath = __DIR__ . "/../../Localization/{$userLanguage}/validation.php";

if (!file_exists($localizationPath)) {
    die('Error: Localization file not found.');
}

$validationMessages = include($localizationPath);

use App\Services\HttpClient;
use App\Handlers\CurlHelper;

/**
 * Load the bot configuration and validate it.
 */
$configFilePath = '../../config/bot_config.php';
if (!file_exists($configFilePath)) {
    die($validationMessages['required'] ?: 'Error: Configuration file not found.');
}

$config = include($configFilePath);
$botToken = $config['bot_token'] ?? null;
$chatId = $config['chat_id'] ?? null;

if (!$botToken || !$chatId) {
    die($validationMessages['required'] ?: 'Error: Bot token or chat ID is not configured properly.');
}

/**
 * Set the directory path for storing uploaded images.
 */
$imagesPath = __DIR__ . "../../../../storage/app/public/images/decoded/";
if (!is_dir($imagesPath)) {
    mkdir($imagesPath, 0777, true); // Create directory if it does not exist
}

/**
 * Process the uploaded image: validate, save, and return the results.
 *
 * @param array $image The uploaded image details.
 * @param string $imagesPath The path to save the image.
 * @param array $validationMessages The localization messages for validation.
 * @return array The result of the image processing.
 */
function processUploadedImage($image, $imagesPath, $validationMessages)
{
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif'];

    $fileExtension = strtolower(pathinfo($image['name'], PATHINFO_EXTENSION));
    $mimeType = mime_content_type($image['tmp_name']);

    if (!in_array($fileExtension, $allowedExtensions) || !in_array($mimeType, $allowedMimeTypes)) {
        return ["error" => $validationMessages['invalid_file_type'] . ": " . htmlspecialchars($image['name'])];
    }

    if ($image['size'] > 5 * 1024 * 1024) {
        return ["error" => $validationMessages['file_too_large'] . ": " . htmlspecialchars($image['name'])];
    }

    $fileName = pathinfo($image['name'], PATHINFO_FILENAME);
    $filePath = $imagesPath . $fileName . '.' . $fileExtension;

    // Ensure a unique file name if the file already exists
    $fileCounter = 1;
    while (file_exists($filePath)) {
        $filePath = $imagesPath . $fileName . '-' . $fileCounter++ . '.' . $fileExtension;
    }

    if (!move_uploaded_file($image['tmp_name'], $filePath)) {
        return ["error" => $validationMessages['failed_to_save_image'] . ": " . htmlspecialchars($fileName)];
    }

    return ["path" => $filePath, "name" => basename($filePath), "size" => $image['size'], "type" => $mimeType];
}

/**
 * Identify the type of barcode based on the decoded content.
 *
 * @param string $decodedCode The decoded barcode content.
 * @return string The type of barcode identified.
 */
function identifyBarcodeType($decodedCode)
{
    if (strpos($decodedCode, 'BEGIN:VCARD') !== false) {
        return 'QR Code';
    } elseif (filter_var($decodedCode, FILTER_VALIDATE_URL)) {
        return 'QR Code (URL)';
    } elseif (preg_match('/^[0-9]{12,13}$/', $decodedCode)) {
        return 'EAN/UPC Barcode';
    } elseif (preg_match('/^[0-9]{14}$/', $decodedCode)) {
        return 'ITF-14 Barcode';
    } else {
        return 'Unknown Barcode Type';
    }
}

/**
 * Handle image uploads and decoding.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['images'])) {
    // Check for upload errors
    if ($_FILES['images']['error'][0] !== UPLOAD_ERR_OK) {
        die($validationMessages['image_upload_failed'] ?: 'Error: Image upload failed.');
    }

    $decodedResults = [];
    $uploadedImages = $_FILES['images'];
    $httpClient = new HttpClient($botToken, $chatId);

    // Process each uploaded image
    foreach ($uploadedImages['tmp_name'] as $key => $tmpName) {
        $image = [
            'name' => $uploadedImages['name'][$key],
            'tmp_name' => $tmpName,
            'error' => $uploadedImages['error'][$key],
            'size' => $uploadedImages['size'][$key],
        ];

        $result = processUploadedImage($image, $imagesPath, $validationMessages);
        if (isset($result['error'])) {
            echo "<p class='text-red-600'>" . htmlspecialchars($result['error']) . "</p>";
            continue;
        }

        // Decode the barcode from the image
        $decodedCode = @shell_exec("zbarimg --raw " . escapeshellarg($result['path']));
        if ($decodedCode === null || trim($decodedCode) === '') {
            echo "<p class='text-red-600'>" . $validationMessages['decoding_failed'] . " " . htmlspecialchars($result['name']) . "</p>";
            continue;
        }

        $barcodeType = identifyBarcodeType(trim($decodedCode));
        $decodedResults[] = [
            'file' => $result['path'],
            'code' => trim($decodedCode),
            'name' => $result['name'],
            'size' => $result['size'],
            'type' => $result['type'],
            'barcode_type' => $barcodeType,
        ];

        $fileSizeInKb = round($result['size'] / 1024, 2);
        $message = "Uploaded Image\n" .
            "File Name: " . $result['name'] . "\n" .
            "File Size: " . $fileSizeInKb . " KB\n" .
            "File Type: " . $result['type'] . "\n" .
            "Decoded Info: " . trim($decodedCode) . "\n" .
            "Decode Image Type: " . $barcodeType;

        // Send the decoded information and image to the Telegram bot
        if (!$httpClient->sendImage($result['path'], $message)) {
            echo "<p class='text-red-600'>" . $validationMessages['failed_to_send_image'] . "</p>";
        } else {
            echo "<p class='text-green-600'>Image sent successfully!</p>";
        }
    }

    // Store decoded results in session and redirect to the decode view
    $_SESSION['decodedResults'] = $decodedResults;
    header('Location: ../../../public/views/decode_view.php');
    exit;
} else {
    // Handle case when no images are uploaded
    die($validationMessages['no_images_uploaded'] ?: "No images uploaded.");
}
