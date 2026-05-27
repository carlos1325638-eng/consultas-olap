<?php
// ── Configuración de conexión a MySQL (XAMPP) ──
$host     = "localhost";
$user     = "root";
$password = "";          // XAMPP por defecto no tiene contraseña
$database = "pet_store";

$conn = new mysqli($host, $user, $password, $database);
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    http_response_code(500);
    die(json_encode(["error" => "Error de conexión: " . $conn->connect_error]));
}
?>
