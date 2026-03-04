<?php
session_start();
header('Content-Type: application/json');

$PASS_SHA = '2d277a2fac660fa0d6d67bdd3d4d69e630e470e6004d5cf801abf179ef375f45';

$action = $_GET['action'] ?? '';

if ($action === 'check') {
    echo json_encode(['ok' => !empty($_SESSION['authed'])]);
    exit;
}

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $pw = $input['password'] ?? '';
    if (hash_equals($PASS_SHA, hash('sha256', $pw))) {
        $_SESSION['authed'] = true;
        echo json_encode(['ok' => true]);
    } else {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Wrong password']);
    }
    exit;
}

if ($action === 'logout') {
    session_destroy();
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
