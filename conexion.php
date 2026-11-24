<?php
// ==========================================================
//  FARMACIA VUELVE A LA VIDA - Conexión a la base de datos
// ==========================================================

$config = [
    'host'    => '127.0.0.1',
    'puerto'  => 3306,
    'bd'      => 'farmacia_vuelve_a_la_vida',
    'usuario' => 'root',
    'clave'   => '',
];

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};port={$config['puerto']};dbname={$config['bd']};charset=utf8mb4",
        $config['usuario'],
        $config['clave'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit('
    <div style="font-family:Arial,sans-serif;max-width:520px;margin:80px auto;padding:24px;background:#fff;border-top:4px solid #2ecc71;border-radius:10px;box-shadow:0 4px 12px rgba(0,0,0,.08);">
        <h2 style="margin:0 0 8px;color:#1e3d2f;">No se pudo conectar</h2>
        <p style="color:#555;">Enciende MySQL en XAMPP y verifica que la base <b>farmacia_vuelve_a_la_vida</b> exista.</p>
    </div>');
}