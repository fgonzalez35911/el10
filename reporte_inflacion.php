<?php
// reporte_inflacion.php - REPORTE PDF CORPORATIVO BLINDADO
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

    $id_operador_gen = $_SESSION['usuario_id'];
    $u_op = $conexion->prepare("SELECT usuario FROM usuarios WHERE id = ?");
    $u_op->execute([$id_operador_gen]);
    $operadorRow = $u_op->fetch(PDO::FETCH_ASSOC);

    $u_owner = $conexion->query("SELECT u.id, u.nombre_completo, r.nombre as nombre_rol 
                                 FROM usuarios u JOIN roles r ON u.id_rol = r.id 
                                 WHERE r.nombre = 'dueño' OR r.nombre = 'DUEÑO' LIMIT 1");
    $ownerRow = $u_owner->fetch(PDO::FETCH_ASSOC);
    $firmante = $ownerRow ? $ownerRow : ['nombre_completo' => 'RESPONSABLE', 'nombre_rol' => 'AUTORIZADO', 'id' => 0];

    $firmaUsuario = ""; 
    if($ownerRow && file_exists("img/firmas/usuario_{$ownerRow['id']}.png")) {
        $firmaUsuario = "img/firmas/usuario_{$ownerRow['id']}.png";
    } elseif(file_exists("img/firmas/firma_admin.png")) {
        $firmaUsuario = "img/firmas/firma_admin.png";
    }

    $desde = $_GET['desde'] ?? date('Y-m-01');
    $hasta = $_GET['hasta'] ?? date('Y-m-t');

    $sql = "SELECT h.*, u.usuario FROM historial_inflacion h LEFT JOIN usuarios u ON h.id_usuario = u.id WHERE DATE(h.fecha) >= ? AND DATE(h.fecha) <= ? ORDER BY h.fecha DESC";
    $stmt = $conexion->prepare($sql);
    $stmt->execute([$desde, $hasta]);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rango_texto = ($desde == $hasta) ? date('d/m/Y', strtotime($desde)) : date('d/m/Y', strtotime($desde)) . " al " . date('d/m/Y', strtotime($hasta));

} catch (Exception $e) { die("Error: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=850">
    <title>Reporte de Inflación</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700;900&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        /* RESET TOTAL PARA EVITAR PÁGINAS EN BLANCO */
        html, body { 
            margin: 0 !important; 
            padding: 0 !important; 
            height: 100%;
            background: #e9ecef; 
            font-family: 'Roboto', sans-serif; 
        }
        
        .page {
            background: white;
            width: 210mm;
            height: 296mm; /* Ajuste milimétrico Vanguard POS */
            margin: 0 auto !important;
            padding: 15mm;
            box-sizing: border-box;
            box-shadow: 0 0 20px rgba(0,0,0,0.2);
            overflow: hidden;
            position: relative;
            display: block;
        }

        header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #102A57; padding-bottom: 10px; margin-bottom: 20px; }
        .empresa-info h1 { margin: 0; font-size: 22pt; color: #102A57; font-weight: 900; }
        .empresa-info p { margin: 2px 0; font-size: 10pt; }

        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th { background: #102A57; color: white; padding: 10px; text-align: left; font-size: 8pt; text-transform: uppercase; }
        td { border-bottom: 1px solid #dee2e6; padding: 10px; font-size: 9pt; }

        .footer-section { margin-top: 50px; display: flex; justify-content: space-between; align-items: flex-end; }
        .firma-area { width: 40%; text-align: center; }
        .firma-img { max-width: 200px; max-height: 90px; display: block; margin: 0 auto -20px auto; }
        .firma-linea { border-top: 1.5px solid #000; padding-top: 5px; font-weight: bold; font-size: 10pt; }

        /* BOTÓN FLOTANTE GIGANTE */
        .no-print { position: fixed; bottom: 0; left: 0; right: 0; background: rgba(255,255,255,0.9); padding: 20px; display: flex; justify-content: center; z-index: 1000; backdrop-filter: blur(5px); }
        .btn-descargar {
            background: #dc3545; color: white; width: 95%; max-width: 750px; padding: 28px; border-radius: 60px;
            border: none; cursor: pointer; font-weight: 900; font-size: 24px; text-transform: uppercase;
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.4); display: flex; align-items: center; justify-content: center; gap: 10px;
        }
    </style>
</head>
<body>

    <div class="no-print">
        <button onclick="descargarPDF()" class="btn-descargar">🚀 DESCARGAR REPORTE</button>
    </div>

    <div id="reporteContenido" class="page">
        <header>
            <div style="width: 25%;"><?php if(!empty($negocio['logo'])): ?><img src="<?php echo $negocio['logo']; ?>?v=<?php echo time(); ?>" style="max-height: 80px;"><?php endif; ?></div>
            <div class="empresa-info" style="text-align: center; width: 40%;">
                <h1><?php echo strtoupper($negocio['nombre']); ?></h1>
                <p><?php echo $negocio['direccion']; ?></p>
                <p><strong>CUIT: <?php echo $negocio['cuit']; ?></strong></p>
            </div>
            <div style="text-align: right; width: 35%; font-size: 9pt;">
                <div style="background: #f8f9fa; padding: 8px; border-radius: 5px; border-right: 4px solid #102A57;">
                    <strong>PERÍODO REPORTADO:</strong><br><?php echo $rango_texto; ?><br>
                    <strong>FECHA EMISIÓN:</strong><br><?php echo date('d/m/Y H:i'); ?>
                </div>
            </div>
        </header>

        <h3 style="color: #102A57; border-left: 5px solid #102A57; padding-left: 10px; margin-bottom: 20px; text-transform: uppercase; font-weight: 900;">Historial de Inflación Aplicada</h3>

        <table>
            <thead>
                <tr>
                    <th style="width: 20%;">Fecha / Hora</th>
                    <th style="width: 15%;">Operador</th>
                    <th style="width: 35%;">Grupo Afectado</th>
                    <th style="width: 15%;">Productos</th>
                    <th style="width: 15%; text-align: right;">Aumento</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($registros as $r): ?>
                <tr>
                    <td><?php echo date('d/m/Y H:i', strtotime($r['fecha'])); ?></td>
                    <td><?php echo strtoupper($r['usuario']); ?></td>
                    <td>
                        <strong><?php echo strtoupper($r['grupo_afectado']); ?></strong><br>
                        <small style="color:#777;"><?php echo $r['accion']=='COSTO'?'Impactó Costo y Venta':'Solo Venta'; ?></small>
                    </td>
                    <td><?php echo $r['cantidad_productos']; ?> u.</td>
                    <td style="text-align: right; font-weight: 900; color:#dc3545;">+<?php echo floatval($r['porcentaje']); ?>%</td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($registros)): ?><tr><td colspan="5" style="text-align:center;">No hubo registros en este período.</td></tr><?php endif; ?>
            </tbody>
        </table>

        <div class="footer-section">
            <div style="width: 50%; font-size: 8.5pt; color: #666; line-height: 1.5;">
                <p><strong>DECLARACIÓN:</strong> Documento generado por Vanguard POS. Refleja las actualizaciones masivas de precios realizadas en el período seleccionado.</p>
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
            filename: 'Reporte_Inflacion_<?php echo date('d_m_Y'); ?>.pdf', 
            image: { type: 'jpeg', quality: 0.98 }, 
            html2canvas: { 
                scale: 2, 
                useCORS: true, 
                logging: false,
                scrollY: 0,
                scrollX: 0
            }, 
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait', compress: true }
        };

        html2pdf().set(opt).from(element).save().then(() => {
            btn.innerHTML = "🚀 DESCARGAR REPORTE";
            btn.disabled = false;
        }).catch(err => {
            btn.innerHTML = "❌ ERROR";
            console.error(err);
        });
    }
    </script>
</body>
</html>