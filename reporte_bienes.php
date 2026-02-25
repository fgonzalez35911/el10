<?php
// reporte_bienes.php - REPORTE PDF CORPORATIVO
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

    $desde = $_GET['desde'] ?? date('Y-01-01', strtotime('-5 years'));
    $hasta = $_GET['hasta'] ?? date('Y-12-31');

    $sql = "SELECT * FROM bienes_uso WHERE (DATE(fecha_compra) >= ? AND DATE(fecha_compra) <= ?) OR fecha_compra IS NULL ORDER BY id DESC";
    $stmt = $conexion->prepare($sql);
    $stmt->execute([$desde, $hasta]);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalValor = 0;
} catch (Exception $e) { die("Error: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte_Activos</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        body { font-family: 'Roboto', sans-serif; font-size: 10pt; color: #333; margin: 0; padding: 0; background: #f0f0f0; }
        .page { background: white; width: 210mm; min-height: 296mm; padding: 15mm; margin: 0 auto; box-sizing: border-box; }
        header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #102A57; padding-bottom: 15px; margin-bottom: 20px; }
        .empresa-info h1 { margin: 0; font-size: 18pt; color: #102A57; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #102A57; color: white; padding: 8px; text-align: left; font-size: 9pt; }
        td { border-bottom: 1px solid #ddd; padding: 8px; font-size: 9pt; }
        .total-row td { border-top: 2px solid #102A57; font-weight: bold; font-size: 12pt; padding-top: 10px; }
        .footer-section { margin-top: 40px; display: flex; justify-content: space-between; align-items: flex-end; }
        .firma-area { width: 40%; text-align: center; position: relative; margin-top: 20px; }
        .firma-img { max-width: 250px; max-height: 110px; display: block; margin: 0 auto -28px auto; position: relative; z-index: 2; }
        .firma-linea { border-top: 1.5px solid #000; position: relative; z-index: 1; padding-top: 5px; font-weight: bold; font-size: 10pt; }
        .no-print { position: fixed; bottom: 20px; right: 20px; z-index: 9999; display: flex; justify-content: flex-end; }
        .btn-descargar { background: #dc3545; color: white; padding: 15px 30px; border-radius: 50px; border: none; cursor: pointer; font-weight: bold; font-size: 14px; box-shadow: 0 4px 15px rgba(0,0,0,0.3); }
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
                <strong>INVENTARIO DE ACTIVOS</strong><br>
                <strong>FECHA EMISIÓN:</strong><br><?php echo date('d/m/Y H:i'); ?>
            </div>
        </header>

        <h3 style="color: #102A57; border-left: 5px solid #102A57; padding-left: 10px; margin-bottom: 20px;">DETALLE DE BIENES DE USO</h3>

        <table>
            <thead><tr><th>FECHA COMPRA</th><th>EQUIPO</th><th>MARCA / MODELO</th><th>ESTADO</th><th>UBICACIÓN</th><th style="text-align: right;">VALOR COMPRA</th></tr></thead>
            <tbody>
                <?php foreach($registros as $r): 
                    $totalValor += (float)$r['costo_compra'];
                ?>
                <tr>
                    <td><?php echo $r['fecha_compra'] ? date('d/m/Y', strtotime($r['fecha_compra'])) : 'Sin fecha'; ?></td>
                    <td><?php echo strtoupper($r['nombre']); ?><br><small style="color:#888;">S/N: <?php echo $r['numero_serie'] ?: '-'; ?></small></td>
                    <td><?php echo strtoupper($r['marca'] . ' ' . $r['modelo']); ?></td>
                    <td><?php echo $r['estado']; ?></td>
                    <td><?php echo $r['ubicacion']; ?></td>
                    <td style="text-align: right; font-weight: bold; color:#198754;">$<?php echo number_format($r['costo_compra'], 2, ',', '.'); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($registros)): ?><tr><td colspan="6" style="text-align:center;">No hay activos registrados en este filtro.</td></tr><?php endif; ?>
                
                <tr class="total-row">
                    <td colspan="5" style="text-align: right;">VALOR TOTAL INVENTARIO:</td>
                    <td style="text-align: right; color: #198754;">$<?php echo number_format($totalValor, 2, ',', '.'); ?></td>
                </tr>
            </tbody>
        </table>

        <div class="footer-section">
            <div style="width: 55%; font-size: 8pt; color: #666; line-height: 1.4;">
                <p><strong>DECLARACIÓN:</strong> Documento generado por el sistema de gestión. Refleja el inventario de bienes de uso físicos de la empresa y su valuación histórica de compra.</p>
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
        html2pdf().set({ margin: 0, filename: 'Reporte_Activos.pdf', image: { type: 'jpeg', quality: 0.98 }, html2canvas: { scale: 2 }, jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' } }).from(element).save();
    }
    </script>
</body>
</html>