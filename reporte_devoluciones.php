<?php
// reporte_devoluciones.php - REPORTE PDF CORPORATIVO
session_start();
$es_publico = isset($_GET['publico']) && $_GET['publico'] == '1';
if (!isset($_SESSION['usuario_id']) && !$es_publico) { header("Location: index.php"); exit; }
require_once 'includes/db.php';

try {
    $conf = $conexion->query("SELECT * FROM configuracion LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $negocio = [
        'nombre' => $conf['nombre_negocio'] ?? 'EMPRESA',
        'direccion' => $conf['direccion_local'] ?? '',
        'logo' => $conf['logo_url'] ?? '',
        'cuit' => $conf['cuit'] ?? 'S/D'
    ];
    // 1. Datos de quien genera el reporte (Operador para el pie de página jsPDF)
    $id_operador = $_SESSION['usuario_id'] ?? ($_GET['gen_by'] ?? 1);
    $u_op = $conexion->prepare("SELECT usuario FROM usuarios WHERE id = ?");
    $u_op->execute([$id_operador]);
    $operadorRow = $u_op->fetch(PDO::FETCH_ASSOC);

    // 2. Datos del Dueño para la Firma (Buscamos al usuario con rol 'dueño')
    $u_owner = $conexion->query("SELECT u.id, u.nombre_completo, r.nombre as nombre_rol 
                                 FROM usuarios u 
                                 JOIN roles r ON u.id_rol = r.id 
                                 WHERE r.nombre = 'dueño' OR r.nombre = 'DUEÑO' LIMIT 1");
    $ownerRow = $u_owner->fetch(PDO::FETCH_ASSOC);

    // Respaldo por si no existe el rol 'dueño' en la tabla
    $firmante = $ownerRow ? $ownerRow : ['nombre_completo' => 'RESPONSABLE', 'nombre_rol' => 'AUTORIZADO', 'id' => 0];

    // 3. Buscar la firma física del Dueño (usuario_ID.png)
    $firmaUsuario = ""; 
    if($ownerRow && file_exists("img/firmas/usuario_{$ownerRow['id']}.png")) {
        $firmaUsuario = "img/firmas/usuario_{$ownerRow['id']}.png";
    } elseif(file_exists("img/firmas/firma_admin.png")) {
        $firmaUsuario = "img/firmas/firma_admin.png";
    }
    $desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-2 months'));
    $hasta = $_GET['hasta'] ?? date('Y-m-d');
    $buscar = $_GET['buscar'] ?? '';
    $f_cliente = $_GET['id_cliente'] ?? '';
    $f_usuario = $_GET['id_usuario'] ?? '';

    $condiciones = ["DATE(d.fecha) >= ?", "DATE(d.fecha) <= ?"];
    $parametros = [$desde, $hasta];

    if(!empty($buscar)) { 
        if(is_numeric($buscar)) { $condiciones[] = "v.id = ?"; $parametros[] = intval($buscar); } 
        else { $condiciones[] = "c.nombre LIKE ?"; $parametros[] = "%$buscar%"; } 
    }
    if ($f_cliente !== '') { $condiciones[] = "v.id_cliente = ?"; $parametros[] = $f_cliente; }
    if ($f_usuario !== '') { $condiciones[] = "d.id_usuario = ?"; $parametros[] = $f_usuario; }

    $sql = "SELECT d.*, p.descripcion, u.usuario 
            FROM devoluciones d 
            JOIN productos p ON d.id_producto = p.id 
            JOIN usuarios u ON d.id_usuario = u.id 
            JOIN ventas v ON d.id_venta_original = v.id
            LEFT JOIN clientes c ON v.id_cliente = c.id
            WHERE " . implode(" AND ", $condiciones) . " ORDER BY d.fecha DESC";
    $stmt = $conexion->prepare($sql);
    $stmt->execute($parametros);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rango_texto = ($desde == $hasta) ? date('d/m/Y', strtotime($desde)) : date('d/m/Y', strtotime($desde)) . " al " . date('d/m/Y', strtotime($hasta));
    $totalReintegrado = 0;

    $url_actual = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    if (strpos($url_actual, 'publico=1') === false) {
        $separador = parse_url($url_actual, PHP_URL_QUERY) ? '&' : '?';
        $url_publica = $url_actual . $separador . 'publico=1&gen_by=' . $id_usuario;
    } else {
        $url_publica = $url_actual;
    }
    $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&margin=0&data=" . urlencode($url_publica);

} catch (Exception $e) { die("Error: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte_Devoluciones</title>
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
        
        .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: collapse; min-width: 650px; }
        th { background: #102A57; color: white; padding: 10px; text-align: left; font-size: 9pt; white-space: nowrap; }
        td { border-bottom: 1px solid #eee; padding: 10px; font-size: 9pt; vertical-align: middle; }
        tr { page-break-inside: avoid; break-inside: avoid; }
        
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

        @media (max-device-width: 768px) {
            .no-print { left: 50%; right: auto; transform: translateX(-50%); bottom: 40px; width: 90%; display: flex; justify-content: center; }
            .btn-descargar { width: 100%; padding: 45px 20px; font-size: 45px; border-radius: 100px; box-shadow: 0 15px 35px rgba(0,0,0,0.4); }
        }
        @media screen { .report-page { margin-bottom: 30px; } }
    </style>
</head>
<body>
    <div class="no-print"><button onclick="descargarPDF()" class="btn-descargar">📥 DESCARGAR REPORTE</button></div>

    <div id="reporteContenido">
        <?php 
        $filas_por_pagina = 18;
        $chunks = array_chunk($registros, $filas_por_pagina);
        if(empty($chunks)) $chunks = [[]];
        $total_paginas = count($chunks);
        foreach($chunks as $index => $chunk):
            $es_ultima_pagina = ($index == $total_paginas - 1);
        ?>
        <div class="report-page" style="<?php echo (!$es_ultima_pagina) ? 'page-break-after: always;' : ''; ?>">
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
                    <strong>PERÍODO REPORTADO:</strong> <br><?php echo $rango_texto; ?><br>
                    <strong>EMISIÓN:</strong> <?php echo date('d/m/Y H:i'); ?>
                </div>
            </header>

            <h3 style="color: #102A57; border-left: 5px solid #102A57; padding-left: 10px; margin-bottom: 20px; text-transform: uppercase;">Detalle de Devoluciones y Reintegros</h3>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>FECHA</th>
                            <th>OPERADOR</th>
                            <th>TICKET ORIGEN</th>
                            <th>PRODUCTO</th>
                            <th>MOTIVO</th>
                            <th style="text-align: right;">REINTEGRO</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($chunk)): ?>
                            <tr><td colspan="6" style="text-align:center; padding: 30px;">No hubo devoluciones en este periodo.</td></tr>
                        <?php else: ?>
                            <?php foreach($chunk as $r): 
                                $totalReintegrado += $r['monto_devuelto'];
                            ?>
                            <tr>
                                <td><?php echo date('d/m/y H:i', strtotime($r['fecha'])); ?></td>
                                <td><?php echo strtoupper($r['usuario']); ?></td>
                                <td>#<?php echo $r['id_venta_original']; ?></td>
                                <td><?php echo strtoupper($r['descripcion']); ?> (<?php echo floatval($r['cantidad']); ?> u.)</td>
                                <td><?php echo $r['motivo'] ?? 'Reingreso'; ?></td>
                                <td style="text-align: right; font-weight: bold; color:#dc3545;">-$<?php echo number_format($r['monto_devuelto'], 2, ',', '.'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <?php if($es_ultima_pagina): ?>
                        <tr class="total-row">
                            <td colspan="5" style="text-align: right;">TOTAL REINTEGRADO:</td>
                            <td style="text-align: right; color: #dc3545;">-$<?php echo number_format($totalReintegrado, 2, ',', '.'); ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if($es_ultima_pagina): ?>
            <div class="footer-section">
                <div style="width: 40%; font-size: 8pt; color: #666; line-height: 1.3;">
                    <p><strong>DECLARACIÓN JURADA:</strong> Este reporte refleja fielmente las operaciones de devolución y reintegros registradas en el sistema.</p>
                </div>
                <div style="width: 30%; text-align: center;">
                    <img src="<?php echo $qr_url; ?>" style="width: 70px; height: 70px;" alt="QR Verificación">
                    <p style="font-size: 7pt; margin-top: 5px; color:#666;">Verificar online</p>
                </div>
                <div class="firma-area" style="width: 30%;">
                    <?php if(!empty($firmaUsuario)): ?>
                        <img src="<?php echo $firmaUsuario; ?>?v=<?php echo time(); ?>" class="firma-img">
                    <?php endif; ?>
                    <div class="firma-linea">
                        <?php 
                            echo strtoupper($firmante['nombre_completo']) . " | " . strtoupper($firmante['nombre_rol']); 
                        ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <script>
    function descargarPDF() {
        const element = document.getElementById('reporteContenido');
        
        const tableContainer = element.querySelector('.table-responsive');
        if (tableContainer) tableContainer.style.overflowX = 'visible';

        const opt = {
            margin: [10, 10, 10, 10],
            filename: 'Reporte_Devoluciones.pdf',
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2, useCORS: true, scrollY: 0 },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
            pagebreak: { mode: ['css', 'legacy'], avoid: 'tr' }
        };
        
        html2pdf().set(opt).from(element).toPdf().get('pdf').then(function (pdf) {
            const totalPages = pdf.internal.getNumberOfPages();
            for (let i = 1; i <= totalPages; i++) {
                pdf.setPage(i);
                
                pdf.setDrawColor(200, 200, 200);
                pdf.setLineWidth(0.5);
                pdf.line(10, 282, 200, 282);
                
                pdf.setFontSize(8);
                pdf.setTextColor(100, 100, 100);
                pdf.text('Documento de Reporte de Devoluciones - <?php echo addslashes(strtoupper($negocio['nombre'])); ?>', 10, 287);
                pdf.text('Emision: <?php echo date('d/m/Y H:i'); ?> | Op: <?php echo addslashes(strtoupper($operadorRow['usuario'] ?? 'S/D')); ?>', 105, 287, { align: 'center' });
                pdf.text('Pagina ' + i + ' de ' + totalPages, 200, 287, { align: 'right' });
            }
        }).save().then(() => {
            if (tableContainer) tableContainer.style.overflowX = 'auto';
        });
    }
    </script>
</body>
</html>