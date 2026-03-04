<?php
// reporte_clientes.php - ADAPTADO EXACTAMENTE DE REPORTE_GASTOS.PHP
session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

require_once 'includes/db.php';

try {
    $conf = $conexion->query("SELECT * FROM configuracion LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $negocio = [
        'nombre' => $conf['nombre_negocio'] ?? 'EMPRESA',
        'direccion' => $conf['direccion_local'] ?? '',
        'telefono' => $conf['telefono_whatsapp'] ?? '',
        'cuit' => $conf['cuit'] ?? 'S/D',
        'logo' => $conf['logo_url'] ?? ''
    ];

    // 1. Datos del Operador
    $id_operador = $_SESSION['usuario_id'];
    $u_op = $conexion->prepare("SELECT usuario FROM usuarios WHERE id = ?");
    $u_op->execute([$id_operador]);
    $operadorRow = $u_op->fetch(PDO::FETCH_ASSOC);

    // 2. Datos del Dueño para Firma
    $u_owner = $conexion->query("SELECT u.id, u.nombre_completo, r.nombre as nombre_rol 
                                 FROM usuarios u JOIN roles r ON u.id_rol = r.id 
                                 WHERE r.nombre = 'dueño' OR r.nombre = 'DUEÑO' LIMIT 1");
    $ownerRow = $u_owner->fetch(PDO::FETCH_ASSOC);
    $firmante = $ownerRow ? $ownerRow : ['nombre_completo' => 'RESPONSABLE', 'nombre_rol' => 'AUTORIZADO', 'id' => 0];

    // 3. Firma física
    $firmaUsuario = ""; 
    if($ownerRow && file_exists("img/firmas/usuario_{$ownerRow['id']}.png")) {
        $firmaUsuario = "img/firmas/usuario_{$ownerRow['id']}.png";
    } elseif(file_exists("img/firmas/firma_admin.png")) {
        $firmaUsuario = "img/firmas/firma_admin.png";
    }

    // --- LÓGICA DE FILTROS (Igual a clientes.php) ---
    $cond = [];
    $titulo_reporte = "LISTADO GENERAL DE CLIENTES";

    if (isset($_GET['filtro']) && $_GET['filtro'] == 'cumple') {
        $cond[] = "MONTH(c.fecha_nacimiento) = MONTH(CURDATE()) AND DAY(c.fecha_nacimiento) = DAY(CURDATE())";
        $titulo_reporte = "REPORTE DE CUMPLEAÑOS DEL DÍA";
    }
    if (isset($_GET['estado'])) {
        if ($_GET['estado'] == 'deuda') {
            $cond[] = "(SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = c.id AND tipo = 'debe') - (SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = c.id AND tipo = 'haber') > 0.1";
            $titulo_reporte = "REPORTE DE CLIENTES DEUDORES";
        }
    }
    
    $where_clause = (count($cond) > 0) ? " WHERE " . implode(" AND ", $cond) : "";
    $sql = "SELECT c.*, 
            (SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = c.id AND tipo = 'debe') - 
            (SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = c.id AND tipo = 'haber') as saldo_calculado
            FROM clientes c $where_clause ORDER BY c.nombre ASC";

    $clientes = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) { die("Error: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte_Clientes_<?php echo date('d_m_Y'); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        body { font-family: 'Roboto', sans-serif; background: #f0f0f0; margin: 0; padding: 20px; color: #333; }
        .page { background: white; width: 100%; max-width: 210mm; margin: 0 auto; padding: 20px; box-sizing: border-box; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #102A57; padding-bottom: 10px; margin-bottom: 20px; }
        .empresa-info h1 { margin: 0; font-size: 18pt; color: #102A57; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #102A57; color: white; padding: 8px; text-align: left; font-size: 9pt; }
        td { border-bottom: 1px solid #ddd; padding: 8px; font-size: 8.5pt; }
        .footer-section { margin-top: 40px; display: flex; justify-content: space-between; align-items: flex-end; }
        .firma-area { width: 40%; text-align: center; position: relative; }
        .firma-img { max-width: 200px; max-height: 80px; display: block; margin: 0 auto -20px auto; position: relative; z-index: 2; }
        .firma-linea { border-top: 1.5px solid #000; padding-top: 5px; font-weight: bold; font-size: 9pt; }
        .btn-descargar { background: #dc3545; color: white; padding: 15px 30px; border-radius: 50px; text-decoration: none; font-weight: bold; border: none; cursor: pointer; position: fixed; bottom: 20px; right: 20px; }
        @media print { .btn-descargar { display: none; } }
    </style>
</head>
<body>

    <button onclick="descargarPDF()" class="btn-descargar">📥 DESCARGAR REPORTE</button>

    <div id="reporteContenido" class="page">
        <header>
            <div style="width: 25%;"><?php if(!empty($negocio['logo'])): ?><img src="<?php echo $negocio['logo']; ?>?v=<?php echo time(); ?>" style="max-height: 70px;"><?php endif; ?></div>
            <div class="empresa-info" style="text-align: center; width: 50%;">
                <h1><?php echo strtoupper($negocio['nombre']); ?></h1>
                <p><?php echo $negocio['direccion']; ?> | <strong>CUIT: <?php echo $negocio['cuit']; ?></strong></p>
            </div>
            <div style="text-align: right; width: 25%; font-size: 9pt;">
                <strong>FECHA EMISIÓN:</strong><br><?php echo date('d/m/Y H:i'); ?><br>
                <strong>USUARIO:</strong><br><?php echo strtoupper($operadorRow['usuario']); ?>
            </div>
        </header>

        <h3 style="color: #102A57; border-left: 5px solid #102A57; padding-left: 10px; margin-bottom: 15px;"><?php echo $titulo_reporte; ?></h3>

        <table>
            <thead>
                <tr>
                    <th>CLIENTE / TELÉFONO</th>
                    <th>DNI / USUARIO</th>
                    <th style="text-align: center;">PUNTOS</th>
                    <th style="text-align: right;">SALDO DEUDOR</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($clientes as $c): ?>
                <tr>
                    <td><strong><?php echo strtoupper($c['nombre']); ?></strong><br><?php echo $c['telefono'] ?: 'S/D'; ?></td>
                    <td><?php echo $c['dni'] ?: 'S/D'; ?><br><small>@<?php echo $c['usuario'] ?: 'invitado'; ?></small></td>
                    <td style="text-align: center;"><?php echo number_format($c['puntos_acumulados'], 0); ?></td>
                    <td style="text-align: right; font-weight: bold; color: <?php echo $c['saldo_calculado'] > 0 ? '#dc3545' : '#28a745'; ?>;">
                        $<?php echo number_format($c['saldo_calculado'], 2, ',', '.'); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="footer-section">
            <div style="width: 55%; font-size: 8pt; color: #666;">
                <p><strong>REPORTE CONFIDENCIAL:</strong> Este documento contiene información sensible de la cartera de clientes de <?php echo $negocio['nombre']; ?>. Su divulgación no autorizada está prohibida.</p>
            </div>
            <div class="firma-area">
                <?php if(!empty($firmaUsuario)): ?><img src="<?php echo $firmaUsuario; ?>?v=<?php echo time(); ?>" class="firma-img"><?php endif; ?>
                <div class="firma-linea"><?php echo strtoupper($firmante['nombre_completo']) . " | " . strtoupper($firmante['nombre_rol']); ?></div>
            </div>
        </div>
    </div>

    <script>
    function descargarPDF() {
        const element = document.getElementById('reporteContenido');
        const opt = { 
            margin: 0, 
            filename: 'Reporte_Clientes_<?php echo date('d_m_Y'); ?>.pdf', 
            image: { type: 'jpeg', quality: 0.98 }, 
            html2canvas: { scale: 2, useCORS: true }, 
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' } 
        };
        html2pdf().set(opt).from(element).save();
    }
    </script>
</body>
</html>