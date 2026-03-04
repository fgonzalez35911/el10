<?php
// reporte_gastos.php - DISEÑO A4 PREMIUM ESTÁNDAR VANGUARD POS
session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

require_once 'includes/db.php';

try {
    $conf = $conexion->query("SELECT * FROM configuracion LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $negocio = [
        'nombre' => $conf['nombre_negocio'] ?? 'EMPRESA',
        'direccion' => $conf['direccion_local'] ?? '',
        'cuit' => $conf['cuit'] ?? 'S/D',
        'logo' => $conf['logo_url'] ?? ''
    ];

    $desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-2 months'));
    $hasta = $_GET['hasta'] ?? date('Y-m-d');
    $f_cat = $_GET['categoria_filtro'] ?? '';
    $f_usu = $_GET['id_usuario'] ?? '';

    // Filtros para Gastos
    $condG = ["DATE(g.fecha) >= ?", "DATE(g.fecha) <= ?"];
    $paramsG = [$desde, $hasta];
    if($f_cat !== '' && $f_cat !== 'Mermas') { $condG[] = "g.categoria = ?"; $paramsG[] = $f_cat; }
    if($f_usu !== '') { $condG[] = "g.id_usuario = ?"; $paramsG[] = $f_usu; }

    // Filtros para Mermas
    $condM = ["DATE(m.fecha) >= ?", "DATE(m.fecha) <= ?", "m.motivo NOT LIKE 'Devolución #%'"];
    $paramsM = [$desde, $hasta];
    if($f_usu !== '') { $condM[] = "m.id_usuario = ?"; $paramsM[] = $f_usu; }
    if($f_cat !== '' && $f_cat !== 'Mermas') { $condM[] = "1=0"; } 

    $sql = "(SELECT g.monto, g.categoria, g.fecha, g.descripcion, u.usuario, 'gasto' as tipo
             FROM gastos g JOIN usuarios u ON g.id_usuario = u.id 
             WHERE " . implode(" AND ", $condG) . ")
            UNION 
            (SELECT (m.cantidad * p.precio_costo) as monto, 'Mermas' as categoria, m.fecha, m.motivo as descripcion, u.usuario, 'merma' as tipo
             FROM mermas m 
             JOIN usuarios u ON m.id_usuario = u.id 
             JOIN productos p ON m.id_producto = p.id
             WHERE " . implode(" AND ", $condM) . ")
            ORDER BY fecha DESC";

    $stmt = $conexion->prepare($sql);
    $stmt->execute(array_merge($paramsG, $paramsM));
    $gastos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total = 0;
    foreach($gastos as $g) { $total += $g['monto']; }
    $u_owner = $conexion->query("SELECT u.id, u.nombre_completo, r.nombre as nombre_rol FROM usuarios u JOIN roles r ON u.id_rol = r.id WHERE r.nombre = 'dueño' OR r.nombre = 'DUEÑO' LIMIT 1");
    $ownerRow = $u_owner->fetch(PDO::FETCH_ASSOC);
    $firmante = $ownerRow ? $ownerRow : ['nombre_completo' => 'RESPONSABLE', 'nombre_rol' => 'AUTORIZADO', 'id' => 0];

    $firmaUsuario = ""; 
    if($ownerRow && file_exists("img/firmas/usuario_{$ownerRow['id']}.png")) { $firmaUsuario = "img/firmas/usuario_{$ownerRow['id']}.png"; }
    elseif(file_exists("img/firmas/firma_admin.png")) { $firmaUsuario = "img/firmas/firma_admin.png"; }

    $rango_texto = ($desde == $hasta) ? date('d/m/Y', strtotime($desde)) : date('d/m/Y', strtotime($desde)) . " al " . date('d/m/Y', strtotime($hasta));

} catch (Exception $e) { die("Error: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=850"> <title>Reporte de Gastos</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700;900&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        body { font-family: 'Roboto', sans-serif; background: #e9ecef; margin: 0; padding: 0; color: #333; }
        
        /* LA HOJA A4 */
       .page {
            background: white;
            width: 210mm;
            height: 295mm; /* Altura de seguridad */
            margin: 0 auto; 
            padding: 15mm;
            box-sizing: border-box;
            box-shadow: 0 0 20px rgba(0,0,0,0.2);
            overflow: hidden; 
            position: relative;
            display: block; /* Forzamos que sea un bloque sólido */
        }

        /* CABECERA */
        header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #102A57; padding-bottom: 10px; margin-bottom: 20px; }
        .empresa-info h1 { margin: 0; font-size: 22pt; color: #102A57; font-weight: 900; }
        .empresa-info p { margin: 2px 0; font-size: 10pt; font-weight: 400; }

        /* TABLA */
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th { background: #102A57; color: white; padding: 10px; text-align: left; font-size: 9pt; text-transform: uppercase; }
        td { border-bottom: 1px solid #dee2e6; padding: 10px; font-size: 9.5pt; }
        .total-row td { border-top: 2px solid #102A57; font-weight: 900; font-size: 13pt; padding-top: 15px; }

        /* FIRMA Y PIE */
        .footer-section { margin-top: 50px; display: flex; justify-content: space-between; align-items: flex-end; }
        .firma-area { width: 40%; text-align: center; }
        .firma-img { max-width: 200px; max-height: 90px; display: block; margin: 0 auto -20px auto; }
        .firma-linea { border-top: 1.5px solid #000; padding-top: 5px; font-weight: bold; font-size: 10pt; }

        /* EL BOTÓN ROJO DE LA IMAGEN */
        .no-print {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(255,255,255,0.9);
            padding: 20px;
            display: flex;
            justify-content: center;
            z-index: 1000;
            backdrop-filter: blur(5px);
        }
        .btn-descargar {
            background: #dc3545;
            color: white;
            width: 95%; /* Más ancho */
            max-width: 750px; /* Casi todo el ancho de la hoja */
            padding: 28px; /* Más alto/gordo */
            border-radius: 60px;
            border: none;
            cursor: pointer;
            font-weight: 900;
            font-size: 24px; 
            text-transform: uppercase;
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
    </style>
</head>
<body>

    <div class="no-print">
        <button onclick="descargarPDF()" class="btn-descargar">
            🚀 DESCARGAR REPORTE
        </button>
    </div>

    <div id="reporteContenido" class="page">
        <header>
            <div style="width: 25%;"><?php if(!empty($negocio['logo'])): ?><img src="<?php echo $negocio['logo']; ?>?v=<?php echo time(); ?>" style="max-height: 80px;"><?php endif; ?></div>
            <div class="empresa-info" style="text-align: center; width: 40%;">
                <h1><?php echo strtoupper($negocio['nombre']); ?></h1>
                <p><?php echo $negocio['direccion']; ?></p>
                <p><strong>CUIT: <?php echo $negocio['cuit']; ?></strong></p>
            </div>
            <div style="text-align: right; width: 35%; font-size: 9pt; line-height: 1.4;">
                <div style="background: #f8f9fa; padding: 8px; border-radius: 5px; border-right: 4px solid #102A57;">
                    <strong>PERÍODO REPORTADO:</strong><br><?php echo $rango_texto; ?><br>
                    <strong>FECHA EMISIÓN:</strong><br><?php echo date('d/m/Y H:i'); ?>
                </div>
            </div>
        </header>

        <h3 style="color: #102A57; border-left: 5px solid #102A57; padding-left: 10px; margin-bottom: 20px; text-transform: uppercase; font-weight: 900;">Detalle de Gastos y Retiros</h3>

        <table>
            <thead>
                <tr>
                    <th style="width: 20%;">Fecha</th>
                    <th style="width: 45%;">Concepto / Operador</th>
                    <th style="width: 15%;">Categoría</th>
                    <th style="width: 20%; text-align: right;">Monto</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($gastos as $g): ?>
                <tr>
                    <td><?php echo date('d/m/y H:i', strtotime($g['fecha'])); ?></td>
                    <td><strong><?php echo strtoupper($g['descripcion']); ?></strong><br><small style="color:#666;">(<?php echo strtoupper($g['usuario']); ?>)</small></td>
                    <td><span style="background: #eee; padding: 2px 6px; border-radius: 4px; font-size: 8pt; font-weight: bold;"><?php echo strtoupper($g['categoria']); ?></span></td>
                    <td style="text-align: right; font-weight: 900; color: #dc3545;">-$<?php echo number_format($g['monto'], 2, ',', '.'); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="3" style="text-align: right;">TOTAL EGRESOS:</td>
                    <td style="text-align: right; color: #dc3545;">-$<?php echo number_format($total, 2, ',', '.'); ?></td>
                </tr>
            </tbody>
        </table>

        <div class="footer-section">
            <div style="width: 50%; font-size: 8.5pt; color: #666; line-height: 1.5;">
                <p><strong>DECLARACIÓN JURADA:</strong> Este documento refleja de forma exacta las salidas de caja registradas en el sistema por el usuario responsable durante el período indicado.</p>
            </div>
            <div class="firma-area">
                <?php if(!empty($firmaUsuario)): ?><img src="<?php echo $firmaUsuario; ?>?v=<?php echo time(); ?>" class="firma-img"><?php endif; ?>
                <div class="firma-linea"><?php echo strtoupper($firmante['nombre_completo']) . " | " . strtoupper($firmante['nombre_rol']); ?></div>
            </div>
        </div>
    </div>

    <script>
    function descargarPDF() {
        const btn = document.querySelector('.btn-descargar');
        const element = document.getElementById('reporteContenido');
        
        btn.innerHTML = "⌛ PROCESANDO...";
        btn.disabled = true;

        window.scrollTo(0, 0);

        const opt = { 
            margin: 0, 
            filename: 'Reporte_Vanguard_POS.pdf', 
            image: { type: 'jpeg', quality: 0.98 }, 
            html2canvas: { scale: 2, useCORS: true, logging: false }, 
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait', compress: true },
            // CORREGIDO: Sin el 'before' que creaba la página en blanco
            pagebreak: { mode: 'avoid-all' } 
        };

        html2pdf().set(opt).from(element).save().then(() => {
            btn.innerHTML = "🚀 DESCARGAR REPORTE";
            btn.disabled = false;
        });
    }
    </script>
</body>
</html>