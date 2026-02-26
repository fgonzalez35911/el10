<?php
// importador_maestro.php - VERSIÓN FINAL (FIX VENCIMIENTOS + FORMATO CLARO)
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

// 1. CONEXIÓN
$rutas_db = [__DIR__ . '/db.php', __DIR__ . '/includes/db.php', 'db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }
$permisos = $_SESSION['permisos'] ?? [];
$es_admin = (($_SESSION['rol'] ?? 3) <= 2);
if (!$es_admin && !in_array('importacion_masiva', $permisos)) { header("Location: dashboard.php"); exit; }

$mensaje = ""; $tipo_mensaje = "";

// 3. COLOR DINÁMICO
$color_sistema = '#102A57';
try {
    $resColor = $conexion->query("SELECT color_barra_nav FROM configuracion WHERE id=1");
    if ($resColor) {
        $dataC = $resColor->fetch(PDO::FETCH_ASSOC);
        if (isset($dataC['color_barra_nav'])) $color_sistema = $dataC['color_barra_nav'];
    }
} catch (Exception $e) { }

// 4. PROCESAR IMPORTACIÓN (LÓGICA PARA FECHAS DD/MM/AAAA)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['archivo_csv'])) {
    if ($_FILES['archivo_csv']['error'] === UPLOAD_ERR_OK) {
        $tmp_name = $_FILES['archivo_csv']['tmp_name'];
        $handle_check = fopen($tmp_name, "r");
        $linea1 = fgets($handle_check);
        fclose($handle_check);
        $separador = (strpos($linea1, ';') !== false) ? ';' : ',';
        $handle = fopen($tmp_name, "r");
        
        if ($handle) {
            $fila = 0; $procesados = 0; $actualizados = 0;
            try {
                $conexion->beginTransaction();
                $sql = "INSERT INTO productos (codigo_barras, descripcion, id_categoria, id_proveedor, tipo, precio_costo, precio_venta, precio_oferta, stock_actual, stock_minimo, activo, fecha_vencimiento, dias_alerta, es_vegano, es_celiaco, es_apto_celiaco, es_apto_vegano) VALUES (:cod, :desc, :cat, :prov, :tipo, :costo, :venta, :oferta, :stock, :min, 1, :venc, :alert, :veg, :cel, :cel, :veg) ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion), precio_costo = VALUES(precio_costo), precio_venta = VALUES(precio_venta), precio_oferta = VALUES(precio_oferta), stock_actual = VALUES(stock_actual), stock_minimo = VALUES(stock_minimo), activo = 1, fecha_vencimiento = VALUES(fecha_vencimiento)";
                $stmt = $conexion->prepare($sql);

                while (($data = fgetcsv($handle, 10000, $separador)) !== FALSE) {
                    $fila++; if ($fila == 1 || empty($data[0])) continue;
                    
                    $codigo = preg_replace('/[^0-9]/', '', $data[0]); 
                    $descripcion = strtoupper(trim($data[1]));
                    
                    // Procesar Fecha (Convierte DD/MM/AAAA a AAAA-MM-DD para MySQL)
                    $vencimiento = NULL;
                    if (!empty($data[10])) {
                        $vencRaw = trim($data[10]);
                        $parts = explode('/', $vencRaw);
                        if(count($parts) == 3) {
                            $vencimiento = $parts[2].'-'.$parts[1].'-'.$parts[0];
                        }
                    }

                    $stmt->execute([
                        ':cod' => $codigo, ':desc' => $descripcion, 
                        ':cat' => NULL, ':prov' => NULL, ':tipo' => 'unitario',
                        ':costo' => limpiarNumero($data[5] ?? 0), 
                        ':venta' => limpiarNumero($data[6] ?? 0),
                        ':oferta' => limpiarNumero($data[9] ?? 0),
                        ':stock' => limpiarNumero($data[7] ?? 0),
                        ':min' => limpiarNumero($data[8] ?? 5),
                        ':venc' => $vencimiento, ':alert' => 30, ':veg' => 0, ':cel' => 0
                    ]);
                    if ($stmt->rowCount() == 1) $procesados++; 
                    if ($stmt->rowCount() == 2) $actualizados++; 
                }
                $conexion->commit();
                $mensaje = "Sincronización Exitosa: $procesados nuevos, $actualizados actualizados."; $tipo_mensaje = "success";
            } catch (Exception $e) { $conexion->rollBack(); $mensaje = "Error: " . $e->getMessage(); $tipo_mensaje = "danger"; }
            fclose($handle);
        }
    }
}

function limpiarNumero($str) {
    $str = str_replace(['$', ' '], '', $str);
    return floatval(str_replace(',', '.', $str));
}
?>

<?php include 'includes/layout_header.php'; ?>

<div class="header-blue" style="background: <?php echo $color_sistema; ?> !important; border-radius: 0 !important; width: 100vw; margin-left: calc(-50vw + 50%); padding: 40px 0; position: relative; overflow: hidden; z-index: 10;">
    <i class="bi bi-file-earmark-spreadsheet bg-icon-large"></i>
    <div class="container position-relative">
        <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
                <h2 class="font-cancha mb-0 text-white">Importador Maestro</h2>
                <p class="opacity-75 mb-0 text-white small">Gestión masiva de productos y vencimientos.</p>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-6 col-md-4"><div class="header-widget" onclick="location.href='productos.php'" style="cursor: pointer;"><div><div class="widget-label">Ver</div><div class="widget-value text-white">PRODUCTOS</div></div><div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-box-seam"></i></div></div></div>
            <div class="col-6 col-md-4"><div class="header-widget" onclick="descargarPlantilla('csv')" style="cursor: pointer;"><div><div class="widget-label">Formato</div><div class="widget-value text-white" style="font-size: 1rem;">PLANTILLA CSV</div></div><div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-filetype-csv"></i></div></div></div>
            <div class="col-12 col-md-4"><div class="header-widget" onclick="descargarPlantilla('excel')" style="cursor: pointer; background: rgba(255,255,255,0.2) !important;"><div><div class="widget-label text-warning fw-bold">Dueño</div><div class="widget-value text-white" style="font-size: 1rem;">DESCARGAR EXCEL</div></div><div class="icon-box bg-success bg-opacity-50 text-white"><i class="bi bi-file-earmark-excel"></i></div></div></div>
        </div>
    </div>
</div>

<div class="container pb-5 mt-4">
    <div class="row justify-content-center">
        <div class="col-md-10">
            <div class="card card-custom border-0 shadow-sm">
                <div class="card-body p-4 p-md-5">
                    <?php if($mensaje): ?><div class="alert alert-<?php echo $tipo_mensaje; ?> border-0 shadow-sm"><?php echo $mensaje; ?></div><?php endif; ?>
                    
                    <div class="alert alert-light border shadow-sm rounded-4 mb-4 p-3">
                        <div class="d-flex align-items-center mb-2">
                            <i class="bi bi-calendar-check-fill text-primary me-2"></i>
                            <h6 class="fw-bold mb-0">Formato de Fecha requerido:</h6>
                        </div>
                        <p class="small text-muted mb-0">En la columna de vencimiento, escriba siempre: <b>DÍA/MES/AÑO</b> (Ejemplo: 31/12/2026).</p>
                    </div>

                    <form method="POST" enctype="multipart/form-data" class="text-center p-5 border border-2 border-dashed rounded-4 bg-light">
                        <div class="mb-4">
                            <div class="display-1 text-primary opacity-25 mb-3"><i class="bi bi-cloud-arrow-up"></i></div>
                            <h5 class="fw-bold">Subir Archivo (.csv)</h5>
                            <input type="file" name="archivo_csv" class="form-control form-control-lg mx-auto shadow-sm" style="max-width: 450px;" accept=".csv" required>
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg fw-bold rounded-pill px-5 shadow">PROCESAR E IMPORTAR</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function descargarPlantilla(tipo) {
    // Encabezados con formato de fecha explícito
    const headers = ["CODIGO", "DESCRIPCION", "CATEGORIA", "PROVEEDOR", "TIPO", "COSTO", "VENTA", "STOCK", "MINIMO", "OFERTA", "VENCIMIENTO (DD/MM/AAAA)", "DIAS_ALERTA", "VEGANO", "CELIACO"];
    
    if (tipo === 'excel') {
        let rowsHtml = "";
        for(let i=0; i<50; i++) {
            if(i === 0) {
                // Fila de ejemplo con FECHA real
                rowsHtml += `<tr><td class="txt">00779123456789</td><td>PRODUCTO DE PRUEBA</td><td>ALMACEN</td><td>PROVEEDOR</td><td>UNITARIO</td><td>100.50</td><td>150.00</td><td>50</td><td>10</td><td>145.00</td><td>31/12/2026</td><td>30</td><td>NO</td><td>SI</td></tr>`;
            } else {
                rowsHtml += `<tr><td class="txt"></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>`;
            }
        }

        let html = `
        <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
        <head><meta charset="utf-8">
        <style>
            table { border-collapse: collapse; }
            th { background-color: #f2f2f2; border: 0.5pt solid #ddd; font-family: Arial; font-size: 10pt; padding: 6px; color: #444; }
            td { border: 0.5pt solid #eee; font-family: Arial; font-size: 10pt; padding: 6px; height: 20pt; }
            .txt { mso-number-format:"\\@"; }
        </style>
        </head>
        <body>
            <table>
                <thead><tr>${headers.map(h => `<th>${h}</th>`).join('')}</tr></thead>
                <tbody>${rowsHtml}</tbody>
            </table>
        </body>
        </html>`;
        
        let blob = new Blob([html], { type: 'application/vnd.ms-excel' });
        let link = document.createElement("a");
        link.href = URL.createObjectURL(blob);
        link.download = "PARA_EL_DUENO_COMPLETAR.xls";
        link.click();
    } else {
        // CSV para el sistema (mantiene el mismo orden)
        let csv = headers.join(",") + "\n" + "00779123456789,EJEMPLO,ALMACEN,PROV,UNITARIO,100,150,20,5,0,31/12/2026,30,NO,NO";
        let blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        let link = document.createElement("a");
        link.href = URL.createObjectURL(blob);
        link.download = "PLANTILLA_SISTEMA.csv";
        link.click();
    }
}
</script>

<?php include 'includes/layout_footer.php'; ?>