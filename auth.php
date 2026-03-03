<?php
session_start();
header('Content-Type: application/json');

$PASS_HASH = '$2y$12$ANawHOPFzg7fsbcjkMwwyeq2ywbRubuy0v/CDZAToMCn4XKLy72H.';

$action = $_GET['action'] ?? '';

if ($action === 'check') {
    echo json_encode(['ok' => !empty($_SESSION['authed'])]);
    exit;
}

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $pw = $input['password'] ?? '';
    if (password_verify($pw, $PASS_HASH)) {
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
