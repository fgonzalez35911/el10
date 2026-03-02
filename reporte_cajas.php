<?php
// reporte_cajas.php - VERSIÓN FINAL ESTABLE Y RESPONSIVA
session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }
require_once 'includes/db.php';

try {
    $conf = $conexion->query("SELECT * FROM configuracion LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $negocio = [
        'nombre' => $conf['nombre_negocio'] ?? 'EMPRESA',
        'direccion' => $conf['direccion_local'] ?? '',
        'logo' => $conf['logo_url'] ?? '',
        'cuit' => $conf['cuit'] ?? 'S/D'
    ];

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

    $desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-2 months'));
    $hasta = $_GET['hasta'] ?? date('Y-m-d');
    $f_estado = $_GET['estado'] ?? '';
    $f_usuario = $_GET['id_usuario'] ?? '';

    $condiciones = ["DATE(c.fecha_apertura) >= ?", "DATE(c.fecha_apertura) <= ?"];
    $parametros = [$desde, $hasta];

    if ($f_estado !== '') { $condiciones[] = "c.estado = ?"; $parametros[] = $f_estado; }
    if ($f_usuario !== '') { $condiciones[] = "c.id_usuario = ?"; $parametros[] = $f_usuario; }

    $sql = "SELECT c.*, u.usuario FROM cajas_sesion c JOIN usuarios u ON c.id_usuario = u.id WHERE " . implode(" AND ", $condiciones) . " ORDER BY c.id DESC";
    $stmt = $conexion->prepare($sql);
    $stmt->execute($parametros);
    $cajas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalVentas = 0; $totalDiferencia = 0;
    foreach($cajas as $c) { 
        if($c['estado'] != 'abierta') {
            $totalVentas += floatval($c['total_ventas']); 
            $totalDiferencia += floatval($c['diferencia']);
        }
    }
    $rango_texto = ($desde == $hasta) ? date('d/m/Y', strtotime($desde)) : date('d/m/Y', strtotime($desde)) . " al " . date('d/m/Y', strtotime($hasta));

} catch (Exception $e) { die("Error: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte_Cajas</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        body { font-family: 'Roboto', sans-serif; background: #f0f0f0; margin: 0; padding: 20px; color: #333; }
        
        .report-page {
            background: white;
            width: 100%;
            max-width: 210mm;
            margin: 0 auto;
            padding: 20px;
            box-sizing: border-box;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }

        header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #102A57; padding-bottom: 10px; margin-bottom: 20px; }
        .empresa-info h1 { margin: 0; font-size: 18pt; color: #102A57; }
        
        /* Contenedor con scroll para móviles */
        .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: collapse; min-width: 650px; } /* Mantiene ancho mínimo para que no se amontone */
        th { background: #102A57; color: white; padding: 10px; text-align: left; font-size: 9pt; white-space: nowrap; }
        td { border-bottom: 1px solid #eee; padding: 10px; font-size: 9pt; vertical-align: middle; }
        
        .total-row td { border-top: 2px solid #102A57; font-weight: bold; font-size: 11pt; }
        .footer-section { margin-top: 30px; display: flex; justify-content: space-between; align-items: flex-end; }
        .firma-area { width: 180px; text-align: center; }
        .firma-img { max-width: 150px; max-height: 80px; margin-bottom: -10px; }
        .firma-linea { border-top: 1px solid #000; padding-top: 5px; font-weight: bold; font-size: 9pt; }

        .no-print { position: fixed; bottom: 20px; right: 20px; z-index: 9999; }
        .btn-descargar { 
            background: #dc3545; color: white; padding: 15px 30px; border-radius: 50px; 
            border: none; cursor: pointer; font-weight: bold; box-shadow: 0 4px 10px rgba(0,0,0,0.3);
        }

        @media (max-width: 768px) {
            body { padding: 10px; }
            header { flex-direction: column; text-align: center; gap: 10px; }
            .no-print { left: 10px; right: 10px; bottom: 10px; }
            .btn-descargar { width: 100%; }
        }
    </style>
</head>
<body>

    <div class="no-print">
        <button onclick="descargarPDF()" class="btn-descargar">📥 DESCARGAR REPORTE PDF</button>
    </div>

    <div id="reporteContenido" class="report-page">
        <header>
            <div style="width: 20%;">
                <?php if(!empty($negocio['logo'])): ?>
                    <img src="<?php echo $negocio['logo']; ?>?v=<?php echo time(); ?>" style="max-height: 70px;">
                <?php endif; ?>
            </div>
            <div class="empresa-info" style="width: 50%; text-align: center;">
                <h1><?php echo strtoupper($negocio['nombre']); ?></h1>
                <p style="font-size: 9pt; margin: 3px 0;"><?php echo $negocio['direccion']; ?></p>
                <p style="font-size: 9pt; margin: 0;"><strong>CUIT: <?php echo $negocio['cuit']; ?></strong></p>
            </div>
            <div style="text-align: right; width: 30%; font-size: 8pt;">
                <strong>PERÍODO:</strong> <?php echo $rango_texto; ?><br>
                <strong>EMISIÓN:</strong> <?php echo date('d/m/Y H:i'); ?>
            </div>
        </header>

        <h3 style="color: #102A57; border-left: 5px solid #102A57; padding-left: 10px; margin-bottom: 20px; text-transform: uppercase;">Detalle de Auditoría de Cajas</h3>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>SESIÓN / OPERADOR</th>
                        <th>APERTURA</th>
                        <th>CIERRE</th>
                        <th style="text-align: right;">VENTAS</th>
                        <th style="text-align: right;">DIFERENCIA</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($cajas)): ?>
                        <tr><td colspan="5" style="text-align:center; padding: 30px;">No se encontraron registros.</td></tr>
                    <?php else: ?>
                        <?php foreach($cajas as $c): 
                            $es_abierta = ($c['estado'] == 'abierta');
                            $dif = floatval($c['diferencia']);
                        ?>
                        <tr>
                            <td><strong>CJ-<?php echo str_pad($c['id'], 6, '0', STR_PAD_LEFT); ?></strong><br><small style="color:#666;"><?php echo strtoupper($c['usuario']); ?></small></td>
                            <td><?php echo date('d/m/y H:i', strtotime($c['fecha_apertura'])); ?></td>
                            <td><?php echo $es_abierta ? 'ABIERTA' : date('d/m/y H:i', strtotime($c['fecha_cierre'])); ?></td>
                            <td style="text-align: right; font-weight: bold; color: #198754;">$<?php echo number_format($c['total_ventas'], 2, ',', '.'); ?></td>
                            <td style="text-align: right; font-weight: bold; color: <?php echo ($dif < 0) ? '#dc3545' : ($dif > 0 ? '#198754' : '#333'); ?>;">
                                <?php echo $es_abierta ? '-' : '$' . number_format($dif, 2, ',', '.'); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <tr class="total-row">
                        <td colspan="3" style="text-align: right;">TOTALES CONSOLIDADOS:</td>
                        <td style="text-align: right; color: #198754;">$<?php echo number_format($totalVentas, 2, ',', '.'); ?></td>
                        <td style="text-align: right; color: <?php echo ($totalDiferencia < 0) ? '#dc3545' : ($totalDiferencia > 0 ? '#198754' : '#333'); ?>;">
                            $<?php echo number_format($totalDiferencia, 2, ',', '.'); ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="footer-section">
            <div style="width: 55%; font-size: 8pt; color: #666; line-height: 1.3;">
                <p><strong>DECLARACIÓN JURADA:</strong> Este reporte refleja fielmente las sesiones de caja registradas en el sistema. Las cajas abiertas no se incluyen en los totales.</p>
            </div>
            <div class="firma-area">
                <?php if(!empty($firmaUsuario)): ?>
                    <img src="<?php echo $firmaUsuario; ?>?v=<?php echo time(); ?>" class="firma-img">
                <?php endif; ?>
                <div class="firma-linea"><?php echo strtoupper($userRow['nombre_completo'] ?? 'RESPONSABLE'); ?></div>
            </div>
        </div>
    </div>

    <script>
    function descargarPDF() {
        const element = document.getElementById('reporteContenido');
        const opt = {
            margin: 5,
            filename: 'Reporte_Cajas.pdf',
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2, useCORS: true, letterRendering: true },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
        };
        html2pdf().set(opt).from(element).save();
    }
    </script>
</body>
</html>