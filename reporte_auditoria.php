<?php
// reporte_auditoria.php - REPORTE PDF CORPORATIVO
session_start();

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

require_once 'includes/db.php';

try {
    $conf = $conexion->query("SELECT * FROM configuracion LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $id_usuario = $_SESSION['usuario_id'];
    $u = $conexion->prepare("SELECT usuario, id_rol, nombre_completo FROM usuarios WHERE id = ?");
    $u->execute([$id_usuario]);
    $userRow = $u->fetch(PDO::FETCH_ASSOC);
    
    $firmaUsuario = ""; 
    if ($userRow['id_rol'] <= 2) { 
        if(file_exists("img/firmas/firma_admin.png")) $firmaUsuario = "img/firmas/firma_admin.png"; 
    } else { 
        if(file_exists("img/firmas/usuario_{$id_usuario}.png")) $firmaUsuario = "img/firmas/usuario_{$id_usuario}.png"; 
    }

    $desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-1 week'));
    $hasta = $_GET['hasta'] ?? date('Y-m-d');
    $f_user   = $_GET['f_user'] ?? '';
    $f_accion = $_GET['f_accion'] ?? '';

    $sql = "SELECT a.fecha, a.accion, a.detalles, u.usuario 
            FROM auditoria a JOIN usuarios u ON a.id_usuario = u.id 
            WHERE DATE(a.fecha) >= ? AND DATE(a.fecha) <= ?";
    $params = [$desde, $hasta];
    
    if(!empty($f_user)) { $sql .= " AND a.id_usuario = ?"; $params[] = $f_user; }
    if(!empty($f_accion)) { $sql .= " AND a.accion LIKE ?"; $params[] = "%$f_accion%"; }
    
    $sql .= " ORDER BY a.fecha DESC";
    
    $stmt = $conexion->prepare($sql);
    $stmt->execute($params);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($desde == $hasta) {
        $rango_texto = date('d/m/Y', strtotime($desde));
    } else {
        $rango_texto = date('d/m/Y', strtotime($desde)) . " al " . date('d/m/Y', strtotime($hasta));
    }

} catch (Exception $e) { die("Error: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte_Auditoria</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        body { font-family: 'Roboto', sans-serif; font-size: 9pt; color: #333; margin: 0; padding: 0; background: #f0f0f0; }
        .page { background: white; width: 210mm; min-height: 296mm; padding: 15mm; margin: 0 auto; box-sizing: border-box; }
        header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #102A57; padding-bottom: 15px; margin-bottom: 20px; }
        .empresa-info h1 { margin: 0; font-size: 18pt; color: #102A57; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; table-layout: fixed; }
        th { background: #102A57; color: white; padding: 8px; text-align: left; font-size: 8pt; }
        td { border-bottom: 1px solid #ddd; padding: 8px; font-size: 8pt; word-wrap: break-word; }
        .footer-section { margin-top: 40px; display: flex; justify-content: space-between; align-items: flex-end; }
        .firma-area { width: 40%; text-align: center; position: relative; margin-top: 20px; }
        .firma-img { max-width: 250px; max-height: 110px; display: block; margin: 0 auto -28px auto; position: relative; z-index: 2; }
        .firma-linea { border-top: 1.5px solid #000; position: relative; z-index: 1; padding-top: 5px; font-weight: bold; font-size: 10pt; }
        .no-print { position: fixed; bottom: 20px; right: 20px; z-index: 9999; display: flex; justify-content: flex-end; }
        .btn-descargar { background: #dc3545; color: white; padding: 15px 30px; border-radius: 50px; border: none; cursor: pointer; font-weight: bold; font-size: 14px; box-shadow: 0 4px 15px rgba(0,0,0,0.3); }
        @media (max-width: 768px) { .no-print { left: 0; right: 0; bottom: 10px; padding: 0 15px; justify-content: center; } .btn-descargar { width: 100%; padding: 20px; font-size: 18px; text-transform: uppercase; } }
    </style>
</head>
<body>
    <div class="no-print"><button onclick="descargarPDF()" class="btn-descargar">📥 DESCARGAR REPORTE</button></div>

    <div id="reporteContenido" class="page">
        <header>
            <div style="width: 25%;"><?php if(!empty($conf['logo_url'])): ?><img src="<?php echo $conf['logo_url']; ?>" style="max-height: 75px;"><?php endif; ?></div>
            <div class="empresa-info" style="text-align: center; width: 40%;">
                <h1><?php echo strtoupper($conf['nombre_negocio'] ?? 'EMPRESA'); ?></h1>
                <p><?php echo $conf['direccion_local'] ?? ''; ?></p><p><strong>CUIT: <?php echo $conf['cuit'] ?? 'S/D'; ?></strong></p>
            </div>
            <div style="text-align: right; width: 35%; font-size: 9pt;">
                <strong>PERÍODO REPORTADO:</strong><br><?php echo $rango_texto; ?><br>
                <strong>FECHA EMISIÓN:</strong><br><?php echo date('d/m/Y H:i'); ?>
            </div>
        </header>

        <h3 style="color: #102A57; border-left: 5px solid #102A57; padding-left: 10px; margin-bottom: 20px;">REPORTE DE AUDITORÍA (CAJA NEGRA)</h3>

        <table>
            <thead>
                <tr>
                    <th style="width: 15%;">FECHA</th>
                    <th style="width: 15%;">USUARIO</th>
                    <th style="width: 15%;">ACCIÓN</th>
                    <th style="width: 55%;">DETALLE DEL MOVIMIENTO</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($registros as $r): ?>
                <tr>
                    <td><?php echo date('d/m/y H:i', strtotime($r['fecha'])); ?></td>
                    <td><?php echo strtoupper($r['usuario']); ?></td>
                    <td style="font-weight: bold;"><?php echo strtoupper($r['accion']); ?></td>
                    <td><?php echo htmlspecialchars($r['detalles']); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($registros)): ?><tr><td colspan="4" style="text-align:center;">No hubo movimientos registrados en este período.</td></tr><?php endif; ?>
            </tbody>
        </table>

        <div class="footer-section">
            <div style="width: 55%; font-size: 8pt; color: #666; line-height: 1.4;">
                <p><strong>DECLARACIÓN JURADA:</strong> Este documento refleja la actividad inalterable (Caja Negra) registrada por los usuarios en el sistema durante el período seleccionado.</p>
            </div>
            <div class="firma-area">
                <?php if(!empty($firmaUsuario)): ?><img src="<?php echo $firmaUsuario; ?>?v=<?php echo time(); ?>" class="firma-img" alt="Firma"><?php else: ?><div style="height: 70px;"></div><?php endif; ?>
                <div class="firma-linea"><?php echo strtoupper($userRow['nombre_completo'] ?? 'FIRMA AUTORIZADA'); ?></div>
            </div>
        </div>
    </div>

    <script>
    function descargarPDF() {
        const element = document.getElementById('reporteContenido');
        const opt = { margin: 0, filename: 'Reporte_Auditoria.pdf', image: { type: 'jpeg', quality: 0.98 }, html2canvas: { scale: 2, useCORS: true }, jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' } };
        html2pdf().set(opt).from(element).save();
    }
    </script>
</body>
</html>