<?php
// reporte_cupones.php - REPORTE PDF CORPORATIVO (ESTÁNDAR VANGUARD PRO)
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

    $id_operador_gen = $_SESSION['usuario_id'] ?? ($_GET['gen_by'] ?? 1);
    $u_op = $conexion->prepare("SELECT usuario FROM usuarios WHERE id = ?");
    $u_op->execute([$id_operador_gen]);
    $operadorRow = $u_op->fetch(PDO::FETCH_ASSOC);

    // 2. Datos del Dueño para la Firma (Regla: Reporte = Firma Dueño)
    $u_owner = $conexion->query("SELECT u.id, u.nombre_completo, r.nombre as nombre_rol 
                                 FROM usuarios u JOIN roles r ON u.id_rol = r.id 
                                 WHERE r.nombre = 'dueño' OR r.nombre = 'DUEÑO' LIMIT 1");
    $ownerRow = $u_owner->fetch(PDO::FETCH_ASSOC);
    $firmante = $ownerRow ? $ownerRow : ['nombre_completo' => 'RESPONSABLE', 'nombre_rol' => 'AUTORIZADO', 'id' => 0];

    // 3. Firma física del Dueño
    $firmaUsuario = ""; 
    if($ownerRow && file_exists("img/firmas/usuario_{$ownerRow['id']}.png")) {
        $firmaUsuario = "img/firmas/usuario_{$ownerRow['id']}.png";
    } elseif(file_exists("img/firmas/firma_admin.png")) {
        $firmaUsuario = "img/firmas/firma_admin.png";
    }

    $desde = $_GET['desde'] ?? date('Y-01-01');
    $hasta = $_GET['hasta'] ?? date('Y-12-31', strtotime('+1 year'));
    $f_usu = $_GET['id_usuario'] ?? '';
    $buscar = trim($_GET['buscar'] ?? '');

    $cond = ["DATE(fecha_limite) >= ?", "DATE(fecha_limite) <= ?"];
    $params = [$desde, $hasta];
    if(!empty($buscar)) { $cond[] = "codigo LIKE ?"; $params[] = "%$buscar%"; }
    if($f_usu !== '') { $cond[] = "id_usuario = ?"; $params[] = $f_usu; }

    $sql = "SELECT * FROM cupones WHERE " . implode(" AND ", $cond) . " ORDER BY fecha_limite DESC";
    $stmt = $conexion->prepare($sql);
    $stmt->execute($params);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rango_texto = date('d/m/Y', strtotime($desde)) . " al " . date('d/m/Y', strtotime($hasta));

} catch (Exception $e) { die("Error: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=850">
    <title>Reporte de Cupones</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700;900&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        body { font-family: 'Roboto', sans-serif; background: #f0f0f0; margin: 0; padding: 20px; color: #333; }
        .report-page { background: white; width: 100%; max-width: 210mm; margin: 0 auto; padding: 20px; box-sizing: border-box; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; }
        th { background: #102A57; color: white; padding: 10px; text-align: left; font-size: 9pt; white-space: nowrap; text-transform: uppercase; }
        td { border-bottom: 1px solid #eee; padding: 10px; font-size: 9pt; vertical-align: middle; }
        .total-row td { border-top: 2px solid #102A57; font-weight: bold; font-size: 11pt; border-bottom: none; }
        .footer-section { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 10px; padding-top: 10px; }
        .firma-area { width: 180px; text-align: center; }
        .firma-img { max-width: 150px; max-height: 80px; margin-bottom: -10px; }
        .firma-linea { border-top: 1px solid #000; padding-top: 5px; font-weight: bold; font-size: 9pt; }
        .no-print { position: fixed; bottom: 20px; right: 20px; z-index: 9999; }
        .btn-descargar { background: #dc3545; color: white; padding: 15px 30px; border-radius: 50px; border: none; cursor: pointer; font-weight: bold; box-shadow: 0 4px 10px rgba(0,0,0,0.3); font-size: 16px; }
        .salto-pagina { page-break-after: always; }
        @media (max-device-width: 768px) {
            .no-print { left: 50%; right: auto; transform: translateX(-50%); bottom: 40px; width: 90%; display: flex; justify-content: center; }
            .btn-descargar { width: 100%; padding: 45px 20px; font-size: 40px; border-radius: 100px; box-shadow: 0 15px 35px rgba(0,0,0,0.4); }
        }
        @media screen { .report-page { margin-bottom: 30px; } }
    </style>
</head>
<body>
    <div class="no-print"><button onclick="descargarPDF()" class="btn-descargar">🚀 DESCARGAR REPORTE</button></div>
    <div id="reporteContenido">
        <?php 
        $filas_maximas = 16; $filas_max_con_footer = 10;
        $chunks = []; $temp = [];
        foreach ($registros as $index => $r) {
            $temp[] = $r;
            $faltan = count($registros) - 1 - $index;
            if (count($temp) == $filas_maximas && $faltan > 0) { $chunks[] = $temp; $temp = []; }
        }
        if (!empty($temp)) {
            if (count($temp) > $filas_max_con_footer && count($temp) > 1) {
                $ultimo = array_pop($temp); $chunks[] = $temp; $chunks[] = [$ultimo];
            } else { $chunks[] = $temp; }
        }
        if (empty($chunks)) $chunks = [[]];
        $total_paginas = count($chunks);
        foreach($chunks as $index => $chunk):
            $es_ultima_pagina = ($index == $total_paginas - 1);
        ?>
        <div class="report-page <?php echo (!$es_ultima_pagina) ? 'salto-pagina' : ''; ?>">
            <header style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #102A57; padding-bottom: 10px; margin-bottom: 20px;">
                <div style="width: 20%;"><?php if(!empty($negocio['logo'])): ?><img src="<?php echo $negocio['logo']; ?>?v=<?php echo time(); ?>" style="max-height: 70px;"><?php endif; ?></div>
                <div class="empresa-info" style="width: 50%; text-align: center;">
                    <h1 style="margin: 0; font-size: 18pt; color: #102A57;"><?php echo strtoupper($negocio['nombre']); ?></h1>
                    <p style="font-size: 9pt; margin: 3px 0;"><?php echo $negocio['direccion']; ?></p>
                    <p style="font-size: 9pt; margin: 0;"><strong>CUIT: <?php echo $negocio['cuit']; ?></strong></p>
                </div>
                <div style="text-align: right; width: 30%; font-size: 8pt;">
                    <strong>VENCIMIENTOS:</strong> <br><?php echo $rango_texto; ?><br>
                    <strong>EMISIÓN:</strong> <?php echo date('d/m/Y H:i'); ?>
                </div>
            </header>
            <h3 style="color: #102A57; border-left: 5px solid #102A57; padding-left: 10px; margin-bottom: 20px; text-transform: uppercase;">Estado de Cupones Promocionales</h3>
            <table>
                <thead>
                    <tr><th style="width: 25%;">CÓDIGO</th><th style="width: 25%;">DESCUENTO</th><th style="width: 25%;">VENCIMIENTO</th><th style="width: 25%; text-align: right;">USOS / LÍMITE</th></tr>
                </thead>
                <tbody>
                    <?php foreach($chunk as $r): $limite = $r['cantidad_limite'] > 0 ? $r['cantidad_limite'] : '∞'; ?>
                    <tr>
                        <td><strong><?php echo $r['codigo']; ?></strong></td>
                        <td style="font-weight: bold; color: #102A57;"><?php echo $r['descuento_porcentaje']; ?>% OFF</td>
                        <td><?php echo date('d/m/Y', strtotime($r['fecha_limite'])); ?></td>
                        <td style="text-align: right;"><?php echo $r['usos_actuales'] . " / " . $limite; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(!$es_ultima_pagina): ?>
                    <tr><td colspan="4" style="text-align: center; background-color: #f1f4f9; color: #666; font-size: 8.5pt; font-weight: bold; padding: 15px 10px;">--- CONTINÚA EN LA PÁGINA SIGUIENTE ---</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if($es_ultima_pagina): ?>
            <div class="footer-section">
                <div style="width: 40%; font-size: 8pt; color: #666; line-height: 1.3;">
                    <p><strong>DECLARACIÓN JURADA:</strong> Este reporte refleja fielmente las promociones y cupones vigentes en el sistema.</p>
                </div>
                <div style="width: 30%; text-align: center;">
                    <img src="<?php echo "https://api.qrserver.com/v1/create-qr-code/?size=70x70&margin=0&data=" . urlencode((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]/reporte_cupones.php?publico=1"); ?>" style="width: 70px; height: 70px;" alt="QR">
                    <p style="font-size: 7pt; margin-top: 5px; color:#666;">Verificar online</p>
                </div>
                <div class="firma-area">
                    <?php if(!empty($firmaUsuario)): ?><img src="<?php echo $firmaUsuario; ?>?v=<?php echo time(); ?>" class="firma-img"><?php endif; ?>
                    <div class="firma-linea"><?php echo strtoupper($firmante['nombre_completo']) . " | " . strtoupper($firmante['nombre_rol']); ?></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <script>
    function descargarPDF() {
        const btn = document.querySelector('.btn-descargar');
        const element = document.getElementById('reporteContenido');
        btn.innerHTML = "⌛ PROCESANDO..."; btn.disabled = true; window.scrollTo(0, 0);
        const opt = { margin: 0, filename: 'Reporte_Cupones.pdf', image: { type: 'jpeg', quality: 0.98 }, html2canvas: { scale: 2, useCORS: true }, jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' } };
        html2pdf().set(opt).from(element).toPdf().get('pdf').then(function (pdf) {
            const totalPages = pdf.internal.getNumberOfPages();
            for (let i = 1; i <= totalPages; i++) {
                pdf.setPage(i); pdf.setDrawColor(16, 42, 87); pdf.setLineWidth(0.5); pdf.line(10, 282, 200, 282);
                pdf.setFontSize(8); pdf.setTextColor(100, 100, 100);
                pdf.text('Reporte de Cupones - <?php echo addslashes(strtoupper($negocio['nombre'])); ?>', 10, 287);
                pdf.text('Pagina ' + i + ' de ' + totalPages, 200, 287, { align: 'right' });
                if (i < totalPages) { pdf.setFont('helvetica', 'italic'); pdf.text('Continúa en la página siguiente...', 105, 275, { align: 'center' }); }
            }
        }).save().then(() => { btn.innerHTML = "🚀 DESCARGAR REPORTE"; btn.disabled = false; });
    }
    </script>
</body>
</html>