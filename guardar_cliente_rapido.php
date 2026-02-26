<?php
// acciones/guardar_cliente_rapido.php - VERSIÓN PRO + EMAIL
session_start();
require_once '../includes/db.php';
header('Content-Type: application/json');
if (!isset($_SESSION['usuario_id'])) { die(json_encode(['status' => 'error', 'msg' => 'Expiró'])); }

$nombre = trim($_POST['nombre'] ?? ''); $dni = trim($_POST['dni'] ?? '');
$email = trim($_POST['email'] ?? '');
$telefono = trim($_POST['telefono'] ?? '');

if (empty($nombre)) { die(json_encode(['status' => 'error', 'msg' => 'Nombre requerido'])); }

try {
    if (!empty($dni)) {
        $check = $conexion->prepare("SELECT id, nombre FROM clientes WHERE dni = ? OR dni_cuit = ?");
        $check->execute([$dni, $dni]); $existe = $check->fetch(); 
        if ($existe) { die(json_encode(['status' => 'success', 'id' => $existe['id'], 'nombre' => $existe['nombre'], 'msg' => 'Ya existe'])); }
    }
    $sql = "INSERT INTO clientes (nombre, dni_cuit, dni, telefono, email, fecha_registro, limite_credito, saldo_deudor, puntos_acumulados) 
            VALUES (?, ?, ?, ?, ?, NOW(), 0, 0, 0)";
    $stmt = $conexion->prepare($sql);
    $stmt->execute([$nombre, $dni, $dni, $telefono, $email]);
    echo json_encode(['status' => 'success', 'id' => $conexion->lastInsertId(), 'nombre' => $nombre]);
} catch (PDOException $e) { echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]); }