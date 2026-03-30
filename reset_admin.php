<?php
// salvavidas.php - Archivo temporal para recuperar acceso
require_once 'includes/db.php';

$mensaje = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_usuario'])) {
    $id = intval($_POST['id_usuario']);
    
    // Generamos la contraseña '123456' encriptada (formato estándar de PHP)
    $nueva_clave = password_hash('123456', PASSWORD_DEFAULT);
    $nueva_clave_md5 = md5('123456'); // Por si tu sistema usa el formato viejo

    try {
        // Intentamos actualizar la columna si se llama 'clave'
        $stmt = $conexion->prepare("UPDATE usuarios SET clave = ? WHERE id = ?");
        $stmt->execute([$nueva_clave, $id]);
        $mensaje = "¡ÉXITO! Tu contraseña ahora es: <b>123456</b> (Columna 'clave' actualizada).";
    } catch (Exception $e) {
        try {
            // Si falla, probamos con el nombre de columna 'password'
            $stmt = $conexion->prepare("UPDATE usuarios SET password = ? WHERE id = ?");
            $stmt->execute([$nueva_clave, $id]);
            $mensaje = "¡ÉXITO! Tu contraseña ahora es: <b>123456</b> (Columna 'password' actualizada).";
        } catch (Exception $e2) {
            $mensaje = "Error: No se encontró la columna de contraseña. Avisame y lo ajustamos.";
        }
    }
}

// Traemos los usuarios para que elijas
$usuarios = $conexion->query("SELECT * FROM usuarios")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Recuperar Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark text-white p-5">
    <div class="container" style="max-width: 500px;">
        <div class="card bg-light text-dark shadow">
            <div class="card-header bg-danger text-white fw-bold text-center">
                ⚠️ MÓDULO DE RECUPERACIÓN ⚠️
            </div>
            <div class="card-body">
                
                <?php if($mensaje): ?>
                    <div class="alert alert-success fw-bold text-center"><?= $mensaje ?></div>
                    <a href="index.php" class="btn btn-primary w-100 fw-bold">IR AL LOGIN Y PROBAR</a>
                <?php else: ?>
                    <p class="small text-muted text-center">Seleccioná tu usuario administrador para resetearle la clave a <b>123456</b>.</p>
                    <form method="POST">
                        <select name="id_usuario" class="form-select mb-3" required>
                            <option value="">-- Elegir usuario --</option>
                            <?php foreach($usuarios as $u): ?>
                                <option value="<?= $u['id'] ?>">
                                    <?= isset($u['nombre_completo']) ? $u['nombre_completo'] : (isset($u['usuario']) ? $u['usuario'] : 'ID: '.$u['id']) ?> 
                                    (Rol: <?= $u['id_rol'] ?? 'N/A' ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-danger w-100 fw-bold py-2">RESETEAR A 123456</button>
                    </form>
                <?php endif; ?>
                
            </div>
        </div>
    </div>
</body>
</html>