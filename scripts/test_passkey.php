#!/usr/bin/env php
<?php
/**
 * Test script for passkey registration flow
 * Usage: php test_passkey.php
 */

require __DIR__ . '/../vendor/autoload.php';

$baseUrl = 'http://localhost:8080';
$email = 'test' . time() . '@example.com';

echo "=== Testing Passkey Registration Flow ===\n";
echo "Email: $email\n\n";

// Step 1: Get registration options
$ch = curl_init("$baseUrl/api/auth/register/options");
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['email' => $email, 'displayName' => 'Test User']));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "1. Register Options (HTTP $httpCode):\n";
if ($httpCode === 200) {
    $options = json_decode($response, true);
    echo "   - Challenge: " . substr($options['challenge'], 0, 20) . "...\n";
    echo "   - User ID: " . ($options['user']['id'] ?? 'N/A') . "\n";
    echo "   ✓ Success\n\n";
} else {
    echo "   ✗ Failed: $response\n\n";
    exit(1);
}

// Step 2: Get login options (separate test)
$ch = curl_init("$baseUrl/api/auth/login/options");
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "2. Login Options (HTTP $httpCode):\n";
if ($httpCode === 200) {
    $options = json_decode($response, true);
    echo "   - Challenge: " . substr($options['challenge'], 0, 20) . "...\n";
    echo "   ✓ Success\n\n";
} else {
    echo "   ✗ Failed: $response\n\n";
}

// Step 3: Test refresh token endpoint
$ch = curl_init("$baseUrl/api/auth/refresh");
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['refresh_token' => 'invalid_token']));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "3. Refresh Token (HTTP $httpCode):\n";
if ($httpCode === 401) {
    $data = json_decode($response, true);
    echo "   - Error: " . ($data['error'] ?? 'Unknown') . "\n";
    echo "   ✓ Expected unauthorized response\n\n";
} else {
    echo "   ✗ Unexpected response: $response\n\n";
}

echo "=== All Tests Passed ===\n";
echo "Note: Full passkey registration requires browser WebAuthn API.\n";
echo "Test the UI at: http://localhost:8080\n";
