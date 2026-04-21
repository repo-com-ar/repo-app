<?php
header('Content-Type: application/manifest+json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
echo json_encode([
  'name'             => 'Repo Super Online',
  'short_name'       => 'Repo Super Online',
  'description'      => 'Pedidos de comestibles rápido y fácil',
  'start_url'        => './index.php',
  'display'          => 'standalone',
  'orientation'      => 'portrait',
  'background_color' => '#32363C',
  'theme_color'      => '#ff6b35',
  'icons'            => [
    ['src' => 'favicon/favicon-32x32.png',        'sizes' => '32x32',   'type' => 'image/png'],
    ['src' => 'favicon/favicon-96x96.png',        'sizes' => '96x96',   'type' => 'image/png'],
    ['src' => 'favicon/android-icon-192x192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
    ['src' => 'assets/img/splash.png',            'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
  ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
