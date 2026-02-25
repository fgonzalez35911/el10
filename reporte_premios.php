<?php
// reporte_premios.php - REPORTE PDF CORPORATIVO PREMIUM
session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }
require_once 'includes/db.php';

try {
    $conf = $conexion->query("SELECT * FROM configuracion LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $id_usuario = $_SESSION['usuario_id'];
    $u = $conexion->prepare("SELECT usuario, id_rol, nombre_completo FROM usuarios WHERE id = ?");
    $u->execute([$id_usuario]);
    $userRow = $u->fetch(PDO::FETCH_ASSOC);
    
    $firmaUsuario = (file_exists("img/firmas/firma_admin.png")) ? "img/firmas/firma_admin.png" : (file_exists("img/firmas/usuario_{$id_usuario}.png") ? "img/firmas/usuario_{$id_usuario}.png" : "");

    $desde_p = $_GET['desde'] ?? '0';
    $hasta_p = $_GET['hasta'] ?? '999999';

    $sql = "SELECT p.*, 
             CASE 
                WHEN p.tipo_articulo = 'producto' THEN (SELECT descripcion FROM productos WHERE id = p.id_articulo)
                WHEN p.tipo_articulo = 'combo' THEN (SELECT nombre FROM combos WHERE id = p.id_articulo)
                ELSE NULL 
             END as nombre_vinculo
             FROM premios p 
             WHERE p.puntos_necesarios BETWEEN ? AND ?
             ORDER BY p.puntos_necesarios ASC";
    $stmt = $conexion->prepare($sql);
    $stmt->execute([$desde_p, $hasta_p]);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) { die("Error: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte_Premios</title>
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
                <strong>CATÁLOGO DE PREMIOS</strong><br>
                <strong>FECHA EMISIÓN:</strong><br><?php echo date('d/m/Y H:i'); ?>
            </div>
        </header>

        <h3 style="color: #102A57; border-left: 5px solid #102A57; padding-left: 10px; margin-bottom: 20px;">DETALLE DE PREMIOS PARA CANJE</h3>

        <table>
            <thead><tr><th>PREMIO</th><th>TIPO / VÍNCULO</th><th>STOCK</th><th style="text-align: right;">COSTO PUNTOS</th></tr></thead>
            <tbody>
                <?php foreach($registros as $r): ?>
                <tr>
                    <td><strong><?php echo strtoupper($r['nombre']); ?></strong><br><?php echo $r['es_cupon'] ? 'Cupón de Dinero ($'.$r['monto_dinero'].')' : 'Artículo Físico'; ?></td>
                    <td><?php echo strtoupper($r['nombre_vinculo'] ?: 'General'); ?></td>
                    <td><?php echo $r['stock']; ?> u.</td>
                    <td style="text-align: right; font-weight: bold; color:#102A57;"><?php echo number_format($r['puntos_necesarios'], 0, ',', '.'); ?> pts</td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($registros)): ?><tr><td colspan="4" style="text-align:center;">No hay premios registrados.</td></tr><?php endif; ?>
            </tbody>
        </table>

        <div class="footer-section">
            <div style="width: 55%; font-size: 8pt; color: #666; line-height: 1.4;">
                <p><strong>DECLARACIÓN:</strong> Documento generado por el sistema de gestión. Refleja el catálogo de beneficios vigentes para el programa de fidelización.</p>
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
        html2pdf().set({ margin: 0, filename: 'Reporte_Premios.pdf', image: { type: 'jpeg', quality: 0.98 }, html2canvas: { scale: 2 }, jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' } }).from(element).save();
    }
    </script>
</body>
</html>