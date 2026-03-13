<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

$PASS_SHA = '2d277a2fac660fa0d6d67bdd3d4d69e630e470e6004d5cf801abf179ef375f45';

// Google OAuth Client ID — set this after creating in Google Cloud Console
$GOOGLE_CLIENT_ID = '874837483845-ms07npvkph4rd1t6o92o493gf5laavh2.apps.googleusercontent.com';

$action = $_GET['action'] ?? '';

if ($action === 'check') {
    echo json_encode([
        'ok' => !empty($_SESSION['authed']),
        'email' => $_SESSION['user_email'] ?? null,
        'name' => $_SESSION['user_name'] ?? null,
    ]);
    exit;
}

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $pw = $input['password'] ?? '';
    if (hash_equals($PASS_SHA, hash('sha256', $pw))) {
        $_SESSION['authed'] = true;
        $_SESSION['user_email'] = 'shared';
        $_SESSION['user_name'] = 'Shared Login';
        echo json_encode(['ok' => true, 'email' => 'shared', 'name' => 'Shared Login']);
    } else {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Wrong password']);
    }
    exit;
}

if ($action === 'google' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$GOOGLE_CLIENT_ID) {
        http_response_code(500);
        echo json_encode(['error' => 'Google OAuth not configured']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $credential = $input['credential'] ?? '';
    if (!$credential) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing credential']);
        exit;
    }

    // Verify JWT with Google
    $verifyUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($credential);
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $resp = @file_get_contents($verifyUrl, false, $ctx);
    if (!$resp) {
        http_response_code(401);
        echo json_encode(['error' => 'Token verification failed']);
        exit;
    }

    $payload = json_decode($resp, true);
    if (!$payload || ($payload['aud'] ?? '') !== $GOOGLE_CLIENT_ID) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid token audience']);
        exit;
    }

    $email = $payload['email'] ?? '';
    $name = $payload['name'] ?? $email;

    // Check user is allowed
    $db = getDb();
    $stmt = $db->prepare("SELECT allowed FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !$user['allowed']) {
        http_response_code(403);
        echo json_encode(['error' => 'User not authorized']);
        exit;
    }

    $_SESSION['authed'] = true;
    $_SESSION['user_email'] = $email;
    $_SESSION['user_name'] = $name;
    echo json_encode(['ok' => true, 'email' => $email, 'name' => $name]);
    exit;
}

if ($action === 'logout') {
    session_destroy();
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
