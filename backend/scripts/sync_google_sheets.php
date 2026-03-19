<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Database\DatabaseHelper;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$lockFile = __DIR__ . '/../data/sync.lock';
$logFile = __DIR__ . '/../logs/google_sync.log';

$logger = new Logger('sync');
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
$logger->pushHandler(new StreamHandler($logFile, Logger::INFO));

$lockHandle = fopen($lockFile, 'c');
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    $logger->warning('Sync already running, skipping this execution');
    fclose($lockHandle);
    exit(0);
}

set_time_limit(60);

try {
    $startTime = microtime(true);
    $logger->info('Starting scheduled sync');

    $dataDir = __DIR__ . '/../data';
    $settingsPath = $dataDir . '/settings.json';

    if (!file_exists($settingsPath)) {
        $logger->warning('Settings file not found');
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        exit(0);
    }

    $settings = json_decode(file_get_contents($settingsPath), true) ?: [];
    $refreshToken = $settings['google_refresh_token'] ?? '';
    $spreadsheetId = $settings['google_spreadsheet_id'] ?? '';
    $lastSyncTimestamp = $settings['last_sync_timestamp'] ?? '1970-01-01 00:00:00';

    if (empty($refreshToken) || empty($spreadsheetId)) {
        $logger->info('Sync skipped: Missing configuration');
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        exit(0);
    }

    $db = new DatabaseHelper($dataDir);
    $pdo = $db->getConnection();

    $stmt = $pdo->prepare(
        "SELECT gclid, conversion_name, conversion_time, conversion_value, conversion_currency
        FROM conversions
        WHERE created_at > :last_sync
        ORDER BY created_at ASC"
    );
    $stmt->execute([':last_sync' => $lastSyncTimestamp]);
    $newConversions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($newConversions)) {
        $logger->info('Sync skipped: No new conversions');
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        exit(0);
    }

    $clientId = $settings['google_client_id'] ?? '';
    $clientSecret = $settings['google_client_secret'] ?? '';

    if (empty($clientId) || empty($clientSecret)) {
        throw new Exception('Google OAuth credentials not configured');
    }

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token'
        ])
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception('Failed to refresh access token: ' . $result);
    }

    $tokenData = json_decode($result, true);
    if (!isset($tokenData['access_token'])) {
        throw new Exception('Invalid token response');
    }

    $accessToken = $tokenData['access_token'];

    $values = [];
    foreach ($newConversions as $conv) {
        $value = $conv['conversion_value'] !== null && $conv['conversion_value'] !== '' ?
            $conv['conversion_value'] : '';
        $values[] = [
            $conv['gclid'],
            $conv['conversion_name'],
            $conv['conversion_time'],
            $value,
            $conv['conversion_currency']
        ];
    }

    $batchSize = 100;
    for ($i = 0; $i < count($values); $i += $batchSize) {
        $batch = array_slice($values, $i, $batchSize);

        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/Sheet1:append";
        $url .= '?valueInputOption=RAW&insertDataOption=INSERT_ROWS';

        $payload = json_encode(['values' => $batch]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json'
            ]
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception('Failed to append to Google Sheet: ' . $result);
        }
    }

    $settings['last_sync_timestamp'] = date('Y-m-d H:i:s');
    file_put_contents($settingsPath, json_encode($settings, JSON_PRETTY_PRINT));

    $duration = round((microtime(true) - $startTime) * 1000, 2);
    $logger->info('Sync completed successfully', [
        'count' => count($values),
        'duration_ms' => $duration
    ]);

    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    exit(0);
} catch (Exception $e) {
    $logger->error('Sync failed: ' . $e->getMessage(), [
        'trace' => $e->getTraceAsString()
    ]);

    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    exit(1);
}
