<?php
// public/qrcode.php

declare(strict_types=1);

// En veldig enkel QR-proxy som bruker en ekstern QR-tjeneste.
// IMG-taggen i header.php kaller denne filen med ?data=<otpauth-uri>

$data = $_GET['data'] ?? '';

if ($data === '') {
    http_response_code(400);
    exit('Missing data parameter');
}

$size = 200; // bredde x høyde i px

$remoteUrl = 'https://api.qrserver.com/v1/create-qr-code/?size='
    . $size . 'x' . $size
    . '&data=' . rawurlencode($data);

// Send redirect til faktisk QR-bilde
header('Location: ' . $remoteUrl);
exit;
