<?php
// reporte_premios.php - REPORTE PDF CORPORATIVO PREMIUM
session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }
require_once 'includes/db.php';

try {
    $conf = $conexion->query("SELECT * FROM configuracion LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    // Usamos los datos del Dueño para la firma general del catálogo
    $u_owner = $conexion->query("SELECT u.id, u.nombre_completo, r.nombre as nombre_rol FROM usuarios u JOIN roles r ON u.id_rol = r.id WHERE r.nombre = 'dueño' OR r.nombre = 'DUEÑO' LIMIT 1");
    $userRow = $u_owner->fetch(PDO::FETCH_ASSOC);
    
    $firmaUsuarioBase64 = "";
    $ruta_firma = "img/firmas/firma_admin.png";
    if ($userRow && file_exists("img/firmas/usuario_{$userRow['id']}.png")) {
        $ruta_firma = "img/firmas/usuario_{$userRow['id']}.png";
    }
    
    if (file_exists($ruta_firma)) {
        $imgData = base64_encode(file_get_contents($ruta_firma));
        $firmaUsuarioBase64 = 'data:image/png;base64,' . $imgData;
    }

    $desde_p = $_GET['desde_p'] ?? ($_GET['desde'] ?? '0');
    $hasta_p = $_GET['hasta_p'] ?? ($_GET['hasta'] ?? '999999');
    $buscar = trim($_GET['buscar'] ?? '');

    $condiciones = ["p.puntos_necesarios BETWEEN ? AND ?"];
    $parametros = [$desde_p, $hasta_p];

    if (!empty($buscar)) {
        $condiciones[] = "(p.nombre LIKE ? OR p.id = ?)";
        $parametros[] = "%$buscar%";
        $parametros[] = intval($buscar);
    }

    $sql = "SELECT p.*, 
             u.usuario as creador_usuario,
             CASE 
                WHEN p.tipo_articulo = 'producto' THEN (SELECT descripcion FROM productos WHERE id = p.id_articulo)
                WHEN p.tipo_articulo = 'combo' THEN (SELECT nombre FROM combos WHERE id = p.id_articulo)
                ELSE NULL 
             END as nombre_vinculo
             FROM premios p 
             LEFT JOIN usuarios u ON p.id_usuario = u.id
             WHERE " . implode(" AND ", $condiciones) . " 
             ORDER BY p.puntos_necesarios ASC";
    $stmt = $conexion->prepare($sql);
    $stmt->execute($parametros);
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
        .firma-area { width: 180px; text-align: center; }
        .firma-img { max-width: 150px; max-height: 80px; margin-bottom: -10px; }
        .firma-linea { border-top: 1px solid #000; padding-top: 5px; font-weight: bold; font-size: 9pt; }

        .no-print { position: fixed; bottom: 20px; right: 20px; z-index: 9999; }
        .btn-descargar { 
            background: #dc3545; color: white; padding: 15px 30px; border-radius: 50px; 
            border: none; cursor: pointer; font-weight: bold; box-shadow: 0 4px 10px rgba(0,0,0,0.3);
        }

        @media (max-device-width: 768px) {
            .no-print { left: 50%; right: auto; transform: translateX(-50%); bottom: 40px; width: 90%; display: flex; justify-content: center; }
            .btn-descargar { width: 100%; padding: 45px 20px; font-size: 45px; border-radius: 100px; box-shadow: 0 15px 35px rgba(0,0,0,0.4); }
        }
    </style>
</head>
<body>
    <div class="no-print"><button onclick="descargarPDF()" class="btn-descargar">📥 DESCARGAR REPORTE</button></div>

    <div id="reporteContenido" class="page">
        <header>
            <div style="width: 25%;">
                <?php if(!empty($conf['logo_url'])): ?>
                    <img src="<?php echo $conf['logo_url']; ?>?v=<?php echo time(); ?>" style="max-height: 75px;">
                <?php endif; ?>
            </div>
            <div class="empresa-info" style="text-align: center; width: 40%;">
                <h1><?php echo strtoupper($conf['nombre_negocio'] ?? 'EMPRESA'); ?></h1>
                <p style="font-size: 9pt; margin: 3px 0;"><?php echo $conf['direccion_local'] ?? ''; ?></p>
                <p style="font-size: 9pt; margin: 0;"><strong>CUIT: <?php echo $conf['cuit'] ?? 'S/D'; ?></strong></p>
            </div>
            <div style="text-align: right; width: 35%; font-size: 8pt;">
                <strong>CATÁLOGO DE PREMIOS</strong><br>
                <strong>FECHA EMISIÓN:</strong><br><?php echo date('d/m/Y H:i'); ?>
            </div>
        </header>

        <h3 style="color: #102A57; border-left: 5px solid #102A57; padding-left: 10px; margin-bottom: 20px; text-transform: uppercase;">Detalle de Premios para Canje</h3>

        <table>
            <thead><tr><th>ID</th><th>PREMIO</th><th>OPERADOR</th><th>TIPO / VÍNCULO</th><th>STOCK</th><th style="text-align: right;">COSTO PUNTOS</th></tr></thead>
            <tbody>
                <?php foreach($registros as $r): ?>
                <tr>
                    <td>#<?php echo $r['id']; ?></td>
                    <td><strong><?php echo strtoupper($r['nombre']); ?></strong><br><?php echo $r['es_cupon'] ? 'Cupón de Dinero ($'.$r['monto_dinero'].')' : 'Artículo Físico'; ?></td>
                    <td><?php echo strtoupper($r['creador_usuario'] ?: 'SISTEMA'); ?></td>
                    <td><?php echo strtoupper($r['nombre_vinculo'] ?: 'General'); ?></td>
                    <td><?php echo $r['stock']; ?> u.</td>
                    <td style="text-align: right; font-weight: bold; color:#102A57;"><?php echo number_format($r['puntos_necesarios'], 0, ',', '.'); ?> pts</td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($registros)): ?><tr><td colspan="6" style="text-align:center; padding: 30px;">No hay premios registrados en este periodo.</td></tr><?php endif; ?>
            </tbody>
        </table>

        <div class="footer-section">
            <div style="width: 60%; font-size: 8pt; color: #666; line-height: 1.3;">
                <p><strong>DECLARACIÓN JURADA:</strong> Documento generado por el sistema de gestión. Refleja el catálogo de beneficios vigentes para el programa de fidelización.</p>
            </div>
            <div class="firma-area" style="width: 30%;">
                <?php if(!empty($firmaUsuarioBase64)): ?>
                    <img src="<?php echo $firmaUsuarioBase64; ?>" class="firma-img" alt="Firma">
                <?php else: ?>
                    <div style="height: 70px;"></div>
                <?php endif; ?>
                <div class="firma-linea">
                    <?php echo strtoupper($userRow['nombre_completo'] ?? 'FIRMA AUTORIZADA'); ?> | <?php echo strtoupper($userRow['nombre_rol'] ?? 'ADMINISTRADOR'); ?>
                </div>
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