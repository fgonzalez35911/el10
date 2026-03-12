<?php
// reporte_gastos.php - REPORTE PDF CORPORATIVO (ESTANDARIZADO VANGUARD PRO)
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
    
    $id_operador = $_SESSION['usuario_id'] ?? ($_GET['gen_by'] ?? 1);
    $u_op = $conexion->prepare("SELECT usuario FROM usuarios WHERE id = ?");
    $u_op->execute([$id_operador]);
    $operadorRow = $u_op->fetch(PDO::FETCH_ASSOC);

    $u_owner = $conexion->query("SELECT u.id, u.nombre_completo, r.nombre as nombre_rol 
                                 FROM usuarios u 
                                 JOIN roles r ON u.id_rol = r.id 
                                 WHERE r.nombre = 'dueño' OR r.nombre = 'DUEÑO' LIMIT 1");
    $ownerRow = $u_owner->fetch(PDO::FETCH_ASSOC);

    $firmante = $ownerRow ? $ownerRow : ['nombre_completo' => 'RESPONSABLE', 'nombre_rol' => 'AUTORIZADO', 'id' => 0];

    $firmaUsuario = ""; 
    if($ownerRow && file_exists("img/firmas/usuario_{$ownerRow['id']}.png")) {
        $firmaUsuario = 'data:image/png;base64,' . base64_encode(file_get_contents("img/firmas/usuario_{$ownerRow['id']}.png"));
    } elseif(file_exists("img/firmas/firma_admin.png")) {
        $firmaUsuario = 'data:image/png;base64,' . base64_encode(file_get_contents("img/firmas/firma_admin.png"));
    }

    $desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-2 months'));
    $hasta = $_GET['hasta'] ?? date('Y-m-d');
    $f_cat = $_GET['categoria_filtro'] ?? '';
    $f_usu = $_GET['id_usuario'] ?? '';
    $buscar = trim($_GET['buscar'] ?? '');

    $condG = ["DATE(g.fecha) >= ?", "DATE(g.fecha) <= ?"];
    $paramsG = [$desde, $hasta];
    if($f_cat !== '' && $f_cat !== 'Mermas') { $condG[] = "g.categoria = ?"; $paramsG[] = $f_cat; }
    if($f_usu !== '') { $condG[] = "g.id_usuario = ?"; $paramsG[] = $f_usu; }
    if(!empty($buscar)) { $condG[] = "(g.descripcion LIKE ? OR g.id = ?)"; array_push($paramsG, "%$buscar%", intval($buscar)); }

    $condM = ["DATE(m.fecha) >= ?", "DATE(m.fecha) <= ?", "m.motivo NOT LIKE 'Devolución #%'"];
    $paramsM = [$desde, $hasta];
    if($f_usu !== '') { $condM[] = "m.id_usuario = ?"; $paramsM[] = $f_usu; }
    if($f_cat !== '' && $f_cat !== 'Mermas') { $condM[] = "1=0"; } 
    if(!empty($buscar)) { $condM[] = "(m.motivo LIKE ? OR m.id = ?)"; array_push($paramsM, "%$buscar%", intval($buscar)); }

    $sql = "(SELECT g.id, g.monto, g.categoria, g.fecha, g.descripcion, u.usuario, 'gasto' as tipo
             FROM gastos g JOIN usuarios u ON g.id_usuario = u.id 
             WHERE " . implode(" AND ", $condG) . ")
            UNION 
            (SELECT m.id, (m.cantidad * p.precio_costo) as monto, 'Mermas' as categoria, m.fecha, m.motivo as descripcion, u.usuario, 'merma' as tipo
             FROM mermas m 
             JOIN usuarios u ON m.id_usuario = u.id 
             JOIN productos p ON m.id_producto = p.id
             WHERE " . implode(" AND ", $condM) . ")
            ORDER BY fecha DESC";

    $stmt = $conexion->prepare($sql);
    $stmt->execute(array_merge($paramsG, $paramsM));
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rango_texto = ($desde == $hasta) ? date('d/m/Y', strtotime($desde)) : date('d/m/Y', strtotime($desde)) . " al " . date('d/m/Y', strtotime($hasta));
    $totalEgresos = 0;

    $url_actual = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    if (strpos($url_actual, 'publico=1') === false) {
        $separador = parse_url($url_actual, PHP_URL_QUERY) ? '&' : '?';
        $url_publica = $url_actual . $separador . 'publico=1&gen_by=' . $id_operador;
    } else { $url_publica = $url_actual; }
    $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&margin=0&data=" . urlencode($url_publica);

} catch (Exception $e) { die("Error: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte_Gastos</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        body { font-family: 'Roboto', sans-serif; background: #f0f0f0; margin: 0; padding: 20px; color: #333; }
        .report-page { background: white; width: 100%; max-width: 210mm; margin: 0 auto; padding: 20px; box-sizing: border-box; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; }
        th { background: #102A57; color: white; padding: 10px; text-align: left; font-size: 9pt; white-space: nowrap; }
        td { border-bottom: 1px solid #eee; padding: 10px; font-size: 9pt; vertical-align: middle; }
        .total-row td { border-top: 2px solid #102A57; font-weight: bold; font-size: 11pt; border-bottom: none; }
        .footer-section { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; }
        .firma-area { width: 180px; text-align: center; }
        .firma-img { max-width: 150px; max-height: 80px; margin-bottom: -10px; }
        .firma-linea { border-top: 1px solid #000; padding-top: 5px; font-weight: bold; font-size: 9pt; }
        .no-print { position: fixed; bottom: 20px; right: 20px; z-index: 9999; }
        .btn-descargar { background: #dc3545; color: white; padding: 15px 30px; border-radius: 50px; border: none; cursor: pointer; font-weight: bold; box-shadow: 0 4px 10px rgba(0,0,0,0.3); }
        .salto-pagina { page-break-after: always; }
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
        // 1. CEREBRO DE PAGINACIÓN: Calcula las hojas antes de dibujar nada
        $filas_maximas = 16; 
        $filas_max_con_footer = 10; 
        
        $chunks = [];
        $temp = [];
        foreach ($registros as $index => $r) {
            $temp[] = $r;
            $faltan = count($registros) - 1 - $index;
            
            // Si la hoja normal se llenó, corta y arranca otra
            if (count($temp) == $filas_maximas && $faltan > 0) {
                $chunks[] = $temp;
                $temp = [];
            }
        }
        if (!empty($temp)) {
            // Si en la última hoja quedaron muchos gastos y el footer se va a romper,
            // saca el último gasto y lo manda a una nueva hoja para que todo baje junto.
            if (count($temp) > $filas_max_con_footer && count($temp) > 1) {
                $ultimo_gasto = array_pop($temp);
                $chunks[] = $temp;
                $chunks[] = [$ultimo_gasto];
            } else {
                $chunks[] = $temp;
            }
        }
        if (empty($chunks)) $chunks = [[]]; // Por si no hay datos
        
        $total_paginas = count($chunks);
        $totalEgresosAcumulado = 0; // Sumador continuo
        
        // 2. DIBUJA CADA PÁGINA (Asegurando que el logo y el encabezado existan siempre)
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
                    <strong>PERÍODO REPORTADO:</strong> <br><?php echo $rango_texto; ?><br>
                    <strong>EMISIÓN:</strong> <?php echo date('d/m/Y H:i'); ?>
                </div>
            </header>

            <h3 style="color: #102A57; border-left: 5px solid #102A57; padding-left: 10px; margin-bottom: 20px; text-transform: uppercase;">Detalle de Gastos y Retiros</h3>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 15%;">FECHA</th>
                            <th style="width: 45%;">CONCEPTO / OPERADOR</th>
                            <th style="width: 20%;">CATEGORÍA</th>
                            <th style="width: 20%; text-align: right;">MONTO</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($chunk)): ?>
                            <tr><td colspan="4" style="text-align:center; padding: 30px;">No hubo egresos en este periodo.</td></tr>
                        <?php else: ?>
                            <?php foreach($chunk as $r): $totalEgresosAcumulado += $r['monto']; ?>
                            <tr>
                                <td><?php echo date('d/m/y H:i', strtotime($r['fecha'])); ?></td>
                                <td><strong><?php echo strtoupper($r['descripcion']); ?></strong><br><small style="color:#666;">(<?php echo strtoupper($r['usuario']); ?>)</small></td>
                                <td><span style="background: #eee; padding: 2px 6px; border-radius: 4px; font-size: 8pt; font-weight: bold;"><?php echo strtoupper($r['categoria']); ?></span></td>
                                <td style="text-align: right; font-weight: bold; color:#dc3545; white-space: nowrap !important;">-$<?php echo number_format($r['monto'], 2, ',', '.'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <?php if(!$es_ultima_pagina): ?>
                        <tr>
                            <td colspan="4" style="text-align: center; background-color: #f1f4f9; color: #666; font-size: 8.5pt; font-weight: bold; letter-spacing: 2px; padding: 15px 10px; border-top: 1px solid #ddd; border-bottom: 1px solid #ddd;">
                                --- SIN MÁS MOVIMIENTOS EN ESTA PÁGINA ---
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php if($es_ultima_pagina): ?>
                        <tr class="total-row">
                            <td colspan="3" style="text-align: right;">TOTAL EGRESOS:</td>
                            <td style="text-align: right; color: #dc3545; white-space: nowrap !important;">-$<?php echo number_format($totalEgresosAcumulado, 2, ',', '.'); ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if($es_ultima_pagina): ?>
            <div class="footer-section">
                <div style="width: 40%; font-size: 8pt; color: #666; line-height: 1.3;">
                    <p><strong>DECLARACIÓN JURADA:</strong> Este reporte refleja fielmente las operaciones de egresos de caja registradas en el sistema.</p>
                </div>
                <div style="width: 30%; text-align: center;">
                    <img src="<?php echo $qr_url; ?>" style="width: 70px; height: 70px;" alt="QR Verificación">
                    <p style="font-size: 7pt; margin-top: 5px; color:#666;">Verificar online</p>
                </div>
                <div class="firma-area" style="width: 30%;">
                    <?php if(!empty($firmaUsuario)): ?><img src="<?php echo $firmaUsuario; ?>" class="firma-img"><?php endif; ?>
                    <div class="firma-linea"><?php echo strtoupper($firmante['nombre_completo']) . " | " . strtoupper($firmante['nombre_rol']); ?></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <script>
    function descargarPDF() {
        const element = document.getElementById('reporteContenido');
        const opt = {
            margin: 0,
            filename: 'Reporte_Gastos.pdf',
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2, useCORS: true, scrollY: 0 },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
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
                pdf.text('Documento de Reporte de Gastos - <?php echo addslashes(strtoupper($negocio['nombre'])); ?>', 10, 287);
                pdf.text('Emision: <?php echo date('d/m/Y H:i'); ?> | Op: <?php echo addslashes(strtoupper($operadorRow['usuario'] ?? 'S/D')); ?>', 105, 287, { align: 'center' });
                pdf.text('Pagina ' + i + ' de ' + totalPages, 200, 287, { align: 'right' });
                
                // Agrega "Continúa..." si NO es la última página para justificar el espacio en blanco
                if (i < totalPages) {
                    pdf.setFont('helvetica', 'italic');
                    pdf.setTextColor(150, 150, 150); // Color gris claro
                    pdf.text('Continúa en la página siguiente...', 105, 275, { align: 'center' });
                }
            }
        }).save();
    }
    </script>
</body>
</html>