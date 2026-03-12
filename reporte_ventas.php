<?php
// reporte_ventas.php - REPORTE PDF CORPORATIVO VANGUARD PRO (CORREGIDO)
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

    $desde = $_GET['desde'] ?? date('Y-m-01');
    $hasta = $_GET['hasta'] ?? date('Y-m-t');
    $buscar = $_GET['buscar'] ?? '';
    $f_cliente = $_GET['id_cliente'] ?? '';
    $f_usuario = $_GET['id_usuario'] ?? '';

    $condiciones = ["DATE(v.fecha) >= ?", "DATE(v.fecha) <= ?"];
    $parametros = [$desde, $hasta];

    if (!empty($buscar)) {
        if (is_numeric($buscar)) {
            $condiciones[] = "v.id = ?";
            $parametros[] = intval($buscar);
        } else {
            $condiciones[] = "c.nombre LIKE ?";
            $parametros[] = "%$buscar%";
        }
    }
    if ($f_cliente !== '') { $condiciones[] = "v.id_cliente = ?"; $parametros[] = $f_cliente; }
    if ($f_usuario !== '') { $condiciones[] = "v.id_usuario = ?"; $parametros[] = $f_usuario; }

    $sql = "SELECT v.*, c.nombre as cliente, u.usuario 
            FROM ventas v 
            LEFT JOIN clientes c ON v.id_cliente = c.id 
            JOIN usuarios u ON v.id_usuario = u.id 
            WHERE " . implode(" AND ", $condiciones) . " 
            ORDER BY v.fecha DESC";
    $stmt = $conexion->prepare($sql);
    $stmt->execute($parametros);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rango_texto = ($desde == $hasta) ? date('d/m/Y', strtotime($desde)) : date('d/m/Y', strtotime($desde)) . " al " . date('d/m/Y', strtotime($hasta));

    // --- CÁLCULOS DE RESUMEN ---
    $totalRecaudado = 0;
    $resumen_pagos = [];
    
    foreach($registros as $reg) {
        $totalRecaudado += $reg['total'];
        $metodo = strtoupper($reg['metodo_pago'] ?? 'INDEFINIDO');
        if(!isset($resumen_pagos[$metodo])) {
            $resumen_pagos[$metodo] = ['cantidad' => 0, 'monto' => 0];
        }
        $resumen_pagos[$metodo]['cantidad']++;
        $resumen_pagos[$metodo]['monto'] += $reg['total'];
    }

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
    <title>Reporte_Ventas_Premium</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: 'Roboto', sans-serif; background: #f0f0f0; margin: 0; padding: 20px; color: #333; }
        .report-page { background: white; width: 100%; max-width: 210mm; margin: 0 auto; padding: 20px; padding-bottom: 30px; box-sizing: border-box; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #102A57; padding-bottom: 10px; margin-bottom: 20px; }
        .empresa-info h1 { margin: 0; font-size: 18pt; color: #102A57; text-transform: uppercase; font-weight: 900; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #102A57; color: white !important; padding: 10px; text-align: left; font-size: 9pt; white-space: nowrap; }
        td { border-bottom: 1px solid #eee; padding: 10px; font-size: 9pt; vertical-align: middle; }
        .footer-section { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; page-break-inside: avoid; }
        .firma-area { width: 180px; text-align: center; }
        .firma-img { max-width: 150px; max-height: 80px; margin-bottom: -10px; }
        .firma-linea { border-top: 1px solid #000; padding-top: 5px; font-weight: bold; font-size: 9pt; }
        
        .no-print { position: fixed; bottom: 20px; right: 20px; z-index: 9999; display: flex; gap: 10px; }
        .btn-descargar { background: #dc3545; color: white; padding: 15px 25px; border-radius: 50px; border: none; cursor: pointer; font-weight: bold; box-shadow: 0 4px 10px rgba(0,0,0,0.3); font-size: 14px; }
        .btn-compartir { background: #102A57; color: white; padding: 15px 25px; border-radius: 50px; border: none; cursor: pointer; font-weight: bold; box-shadow: 0 4px 10px rgba(0,0,0,0.3); font-size: 14px; }
        
        .salto-pagina { page-break-after: always; }
        .resumen-card { background: #f8f9fa; border: 1px solid #ddd; padding: 15px; border-radius: 8px; margin-top: 20px; page-break-inside: avoid; }
        @media (max-device-width: 768px) {
            .no-print { left: 50%; right: auto; transform: translateX(-50%); bottom: 30px; width: 90%; justify-content: center; }
            .btn-descargar, .btn-compartir { padding: 20px; font-size: 14px; flex: 1; text-align: center; }
        }
        @media screen { .report-page { margin-bottom: 30px; } }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="descargarPDF()" class="btn-descargar"><i class="bi bi-file-pdf"></i> DESCARGAR</button>
        <button onclick="abrirModalCompartir()" class="btn-compartir"><i class="bi bi-share-fill"></i> COMPARTIR</button>
    </div>

    <div id="reporteContenido">
        <?php 
        // 1. CEREBRO DE PAGINACIÓN AJUSTADO (Respiro al final de la página)
        $filas_maximas = 21; // Reducido de 24 a 21 para que no choque con el pie de página
        $filas_max_con_footer = 6; 
        
        $chunks = []; $temp = [];
        foreach ($registros as $index => $r) {
            $temp[] = $r;
            if (count($temp) == $filas_maximas && (count($registros) - 1 - $index) > 0) {
                $chunks[] = $temp; $temp = [];
            }
        }
        if (!empty($temp)) {
            if (count($temp) > $filas_max_con_footer) { 
                $chunks[] = $temp; 
                $chunks[] = []; 
            } else { 
                $chunks[] = $temp; 
            }
        }
        if (empty($chunks)) $chunks = [[]]; 
        
        $total_paginas = count($chunks);
        
        foreach($chunks as $index => $chunk):
            $es_ultima_pagina = ($index == $total_paginas - 1);
        ?>
        <div class="report-page <?php echo (!$es_ultima_pagina) ? 'salto-pagina' : ''; ?>">
            <header>
                <div style="width: 20%;"><?php if(!empty($negocio['logo'])): ?><img src="<?php echo $negocio['logo']; ?>?v=<?php echo time(); ?>" style="max-height: 70px;"><?php endif; ?></div>
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

            <?php if(!empty($chunk)): ?>
            <h3 style="color: #102A57; border-left: 5px solid #102A57; padding-left: 10px; margin-bottom: 20px; margin-top:0; text-transform: uppercase;">Detalle de Ventas</h3>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 10%;">N° OP</th>
                            <th style="width: 20%;">FECHA</th>
                            <th style="width: 20%;">VENDEDOR</th>
                            <th style="width: 20%;">CLIENTE</th>
                            <th style="width: 15%;">PAGO</th>
                            <th style="width: 15%; text-align: right;">TOTAL</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($chunk as $r): ?>
                        <tr>
                            <td>#<?php echo str_pad($r['id'], 6, '0', STR_PAD_LEFT); ?></td>
                            <td><?php echo date('d/m/y H:i', strtotime($r['fecha'])); ?></td>
                            <td><?php echo strtoupper($r['usuario']); ?></td>
                            <td><?php echo strtoupper($r['cliente'] ?? 'CONSUMIDOR FINAL'); ?></td>
                            <td><span style="background: #eee; padding: 2px 6px; border-radius: 4px; font-size: 8pt; font-weight: bold;"><?php echo strtoupper($r['metodo_pago']); ?></span></td>
                            <td style="text-align: right; font-weight: bold; color:#198754;">$<?php echo number_format($r['total'], 2, ',', '.'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php elseif($index == 0 && empty($chunk)): ?>
            <div style="text-align:center; padding: 30px; color:#666;">No hubo ventas registradas en este periodo.</div>
            <?php endif; ?>

            <?php if($es_ultima_pagina && count($registros) > 0): ?>
            <div class="resumen-card">
                <h4 style="color: #102A57; border-bottom: 2px solid #102A57; padding-bottom: 5px; margin-bottom: 15px; margin-top:0; text-transform: uppercase; font-size: 11pt;">Resumen Ejecutivo de Recaudación</h4>
                
                <div style="display: flex; gap: 20px; margin-bottom: 20px;">
                    <div style="flex: 1; background: white; padding: 10px; border-radius: 5px; border: 1px solid #ddd; text-align: center;">
                        <small style="color: #666; font-weight: bold; text-transform: uppercase; font-size: 7pt;">Operaciones Totales</small>
                        <div style="font-size: 14pt; font-weight: 900; color: #102A57;"><?php echo count($registros); ?></div>
                    </div>
                    <div style="flex: 1; background: #e8fdf2; padding: 10px; border-radius: 5px; border: 1px solid #198754; text-align: center;">
                        <small style="color: #198754; font-weight: bold; text-transform: uppercase; font-size: 7pt;">Recaudación Bruta</small>
                        <div style="font-size: 16pt; font-weight: 900; color: #198754;">$<?php echo number_format($totalRecaudado, 2, ',', '.'); ?></div>
                    </div>
                </div>

                <table style="font-size: 8pt; background: white; margin-bottom: 15px;">
                    <thead><tr style="background: #102A57;"><th style="color: white !important; padding: 5px;">MÉTODO DE PAGO</th><th style="color: white !important; padding: 5px; text-align: center;">CANTIDAD OP.</th><th style="color: white !important; padding: 5px; text-align: right;">TOTAL RECAUDADO</th></tr></thead>
                    <tbody>
                        <?php foreach($resumen_pagos as $metodo => $data): ?>
                        <tr><td style="padding: 5px;"><strong><?php echo $metodo; ?></strong></td><td style="padding: 5px; text-align: center;"><?php echo $data['cantidad']; ?></td><td style="padding: 5px; text-align: right; font-weight: bold; color: #198754;">$<?php echo number_format($data['monto'], 2, ',', '.'); ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div style="display: flex; gap: 15px; flex-wrap: wrap; page-break-inside: avoid;">
                    <div style="flex: 1; min-width: 250px; background: white; padding: 10px; border-radius: 5px; border: 1px solid #ddd; text-align: center;">
                        <strong style="font-size: 8pt; color: #666; text-transform: uppercase;">Distribución de Ingresos ($)</strong>
                        <div style="position: relative; height: 140px; width: 100%; margin-top: 10px;"><canvas id="chartMonto"></canvas></div>
                    </div>
                    <div style="flex: 1; min-width: 250px; background: white; padding: 10px; border-radius: 5px; border: 1px solid #ddd; text-align: center;">
                        <strong style="font-size: 8pt; color: #666; text-transform: uppercase;">Uso de Medios de Pago</strong>
                        <div style="position: relative; height: 140px; width: 100%; margin-top: 10px;"><canvas id="chartCant"></canvas></div>
                    </div>
                </div>
            </div>
            
            <script> const datosPagos = <?php echo json_encode($resumen_pagos); ?>; </script>

            <div class="footer-section">
                <div style="width: 40%; font-size: 8pt; color: #666; line-height: 1.3;"><p><strong>DECLARACIÓN JURADA:</strong> Este reporte refleja fielmente los comprobantes de venta emitidos y cobrados en el sistema.</p></div>
                <div style="width: 30%; text-align: center;"><img src="<?php echo $qr_url; ?>" style="width: 70px; height: 70px;"><p style="font-size: 7pt; margin-top: 5px; color:#666;">Validar origen</p></div>
                <div class="firma-area" style="width: 30%;"><?php if(!empty($firmaUsuario)): ?><img src="<?php echo $firmaUsuario; ?>" class="firma-img"><?php endif; ?><div class="firma-linea"><?php echo strtoupper($firmante['nombre_completo']) . " | " . strtoupper($firmante['nombre_rol']); ?></div></div>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        if(typeof datosPagos !== 'undefined' && Object.keys(datosPagos).length > 0) {
            const labels = Object.keys(datosPagos);
            const dataMonto = labels.map(l => datosPagos[l].monto);
            const dataCant = labels.map(l => datosPagos[l].cantidad);
            const colores = ['#198754', '#102A57', '#ffc107', '#dc3545', '#0dcaf0', '#6610f2'];

            if(document.getElementById('chartMonto')) {
                new Chart(document.getElementById('chartMonto'), {
                    type: 'bar',
                    data: { labels: labels, datasets: [{ label: 'Recaudado ($)', data: dataMonto, backgroundColor: '#198754', borderRadius: 4 }] },
                    options: { responsive: true, maintainAspectRatio: false, animation: false, plugins: { legend: { display: false } } }
                });
            }

            if(document.getElementById('chartCant')) {
                new Chart(document.getElementById('chartCant'), {
                    type: 'doughnut',
                    data: { labels: labels, datasets: [{ data: dataCant, backgroundColor: colores, borderWidth: 1 }] },
                    options: { responsive: true, maintainAspectRatio: false, animation: false, plugins: { legend: { position: 'right', labels: { boxWidth: 10, font: { size: 9 } } } } }
                });
            }
        }
    });

    const optGlobal = { margin: [0,0,5,0], filename: 'Reporte_Ventas_Corporativo.pdf', image: { type: 'jpeg', quality: 0.98 }, html2canvas: { scale: 2, useCORS: true, scrollY: 0 }, jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' } };

    function descargarPDF() {
        const element = document.getElementById('reporteContenido');
        html2pdf().set(optGlobal).from(element).toPdf().get('pdf').then(function (pdf) { aplicarFormatoPaginas(pdf); }).save();
    }

    function aplicarFormatoPaginas(pdf) {
        const totalPages = pdf.internal.getNumberOfPages();
        for (let i = 1; i <= totalPages; i++) {
            pdf.setPage(i);
            pdf.setDrawColor(200, 200, 200); pdf.setLineWidth(0.5); pdf.line(10, 282, 200, 282);
            pdf.setFontSize(8); pdf.setTextColor(100, 100, 100);
            pdf.text('Reporte de Ventas Corporativo - <?php echo addslashes(strtoupper($negocio['nombre'])); ?>', 10, 287);
            pdf.text('Emisión: <?php echo date('d/m/Y H:i'); ?> | Auditor: <?php echo addslashes(strtoupper($operadorRow['usuario'] ?? 'S/D')); ?>', 105, 287, { align: 'center' });
            pdf.text('Página ' + i + ' de ' + totalPages, 200, 287, { align: 'right' });
            
            if (i < totalPages) {
                pdf.setFont('helvetica', 'italic'); pdf.setTextColor(150, 150, 150);
                pdf.text('Continúa en la página siguiente...', 105, 280, { align: 'center' });
            }
        }
    }

    function abrirModalCompartir() {
        Swal.fire({
            title: 'Compartir Reporte',
            text: '¿Cómo desea enviar este documento oficial?',
            icon: 'info',
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-whatsapp"></i> WhatsApp',
            confirmButtonColor: '#25D366',
            denyButtonText: '<i class="bi bi-envelope"></i> Correo',
            denyButtonColor: '#102A57',
            showDenyButton: true,
            cancelButtonText: 'Cerrar'
        }).then((result) => {
            if (result.isConfirmed) {
                const msj = `📈 *REPORTE DE VENTAS - <?php echo $negocio['nombre']; ?>*\n📅 Período: <?php echo $rango_texto; ?>\n📄 Ver online: ${window.location.href}`;
                window.open(`https://wa.me/?text=${encodeURIComponent(msj)}`, '_blank');
            } else if (result.isDenied) {
                enviarPorEmailReal();
            }
        });
    }

    function enviarPorEmailReal() {
        Swal.fire({
            title: 'Enviar por Correo', input: 'email', inputPlaceholder: 'Ingrese el correo del destinatario...',
            showCancelButton: true, confirmButtonText: 'ENVIAR AHORA', confirmButtonColor: '#102A57', showLoaderOnConfirm: true,
            preConfirm: (email) => {
                const element = document.getElementById('reporteContenido');
                return html2pdf().set(optGlobal).from(element).toPdf().get('pdf').then(function(pdf) {
                    aplicarFormatoPaginas(pdf); return pdf.output('blob');
                }).then(blob => {
                    let fData = new FormData();
                    fData.append('email', email); fData.append('pdf_file', blob, 'Reporte_Ventas.pdf');
                    return fetch('acciones/enviar_email_reporte_ventas.php', { method: 'POST', body: fData })
                    .then(r => { if(!r.ok) throw new Error(r.statusText); return r.json(); })
                    .catch(e => Swal.showValidationMessage(`Error: ${e}`));
                });
            },
            allowOutsideClick: () => !Swal.isLoading()
        }).then((r) => { if(r && r.isConfirmed && r.value.status === 'success') Swal.fire({ icon: 'success', title: '¡Enviado!', text: 'El reporte oficial ha sido enviado.' }); });
    }
    </script>
</body>
</html>