<?php
// historial_inflacion.php - PANEL CON FILTRO DE RANGO DE FECHAS
session_start();
require_once 'includes/db.php';
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] > 2) { header("Location: dashboard.php"); exit; }

$color_sistema = '#102A57';
try {
    $resColor = $conexion->query("SELECT color_barra_nav FROM configuracion WHERE id=1");
    if ($resColor) {
        $dataC = $resColor->fetch(PDO::FETCH_ASSOC);
        if (isset($dataC['color_barra_nav'])) $color_sistema = $dataC['color_barra_nav'];
    }
} catch (Exception $e) { }

// Lógica de Fechas Desde / Hasta (Por defecto el mes actual)
$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-t');

$sql = "SELECT h.*, u.usuario FROM historial_inflacion h LEFT JOIN usuarios u ON h.id_usuario = u.id WHERE DATE(h.fecha) >= ? AND DATE(h.fecha) <= ? ORDER BY h.fecha DESC";
$stmt = $conexion->prepare($sql);
$stmt->execute([$desde, $hasta]);
$historial = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Historial de Inflación</title>
</head>
<body class="bg-light">
    <?php include 'includes/layout_header.php'; ?>
    
    <div class="header-blue" style="background: <?php echo $color_sistema; ?> !important; border-radius: 0 !important; width: 100vw; margin-left: calc(-50vw + 50%); padding: 40px 0; position: relative; overflow: hidden; z-index: 10;">
        <i class="bi bi-graph-up-arrow bg-icon-large"></i>
        <div class="container position-relative text-white">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="font-cancha mb-0 text-white">Historial de Inflación</h2>
                    <p class="opacity-75 mb-0 text-white small">Registro de aumentos masivos</p>
                </div>
                <div>
                    <a href="precios_masivos.php" class="btn btn-light text-primary fw-bold rounded-pill px-4 shadow-sm me-2"><i class="bi bi-arrow-left me-2"></i> VOLVER</a>
                    <a href="reporte_inflacion.php?desde=<?php echo $desde; ?>&hasta=<?php echo $hasta; ?>" target="_blank" class="btn btn-danger fw-bold rounded-pill px-4 shadow-sm"><i class="bi bi-file-earmark-pdf-fill me-2"></i> REPORTE PDF</a>
                </div>
            </div>
            
            <div class="bg-white bg-opacity-10 p-3 rounded-4 shadow-sm d-inline-block border border-white border-opacity-25 mt-2">
                <form method="GET" class="d-flex align-items-center gap-3 mb-0">
                    <div class="d-flex align-items-center">
                        <span class="small fw-bold text-white text-uppercase me-2">Desde:</span>
                        <input type="date" name="desde" class="form-control border-0 shadow-sm rounded-3 fw-bold" value="<?php echo $desde; ?>" required style="max-width: 150px;">
                    </div>
                    <div class="d-flex align-items-center">
                        <span class="small fw-bold text-white text-uppercase me-2">Hasta:</span>
                        <input type="date" name="hasta" class="form-control border-0 shadow-sm rounded-3 fw-bold" value="<?php echo $hasta; ?>" required style="max-width: 150px;">
                    </div>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow"><i class="bi bi-search me-2"></i> FILTRAR</button>
                </form>
            </div>
        </div>
    </div>

    <div class="container mt-4 pb-5">
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light text-muted small text-uppercase">
                        <tr><th class="ps-4">Fecha</th><th>Grupo Afectado</th><th>Cant. Prod.</th><th>Impacto</th><th class="text-end pe-4">Aumento</th></tr>
                    </thead>
                    <tbody>
                        <?php if(!$historial): ?><tr><td colspan="5" class="text-center py-5 text-muted">No hay aumentos registrados en este rango de fechas.</td></tr><?php endif; ?>
                        <?php foreach($historial as $h): ?>
                        <tr>
                            <td class="ps-4 py-3"><div class="fw-bold text-dark"><?php echo date('d/m/Y', strtotime($h['fecha'])); ?></div><small class="text-muted"><?php echo date('H:i', strtotime($h['fecha'])); ?> - <i class="bi bi-person-fill"></i> <?php echo htmlspecialchars($h['usuario']); ?></small></td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($h['grupo_afectado']); ?></span></td>
                            <td><span class="badge bg-light text-dark border"><?php echo $h['cantidad_productos']; ?> ítems</span></td>
                            <td><span class="badge <?php echo $h['accion']=='COSTO'?'bg-danger':'bg-primary'; ?>"><?php echo $h['accion']=='COSTO'?'COSTO Y VENTA':'SOLO VENTA'; ?></span></td>
                            <td class="text-end pe-4 fw-bold text-danger fs-5">+<?php echo floatval($h['porcentaje']); ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <?php include 'includes/layout_footer.php'; ?>
</body>
</html>