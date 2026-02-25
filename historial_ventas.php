<?php
// historial_ventas.php - GESTIÓN INTEGRAL (CON FILTRO FECHAS Y REPORTE)
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

$color_sistema = '#102A57';
try {
    $resColor = $conexion->query("SELECT color_barra_nav FROM configuracion WHERE id=1");
    if ($resColor) {
        $dataC = $resColor->fetch(PDO::FETCH_ASSOC);
        if (isset($dataC['color_barra_nav'])) $color_sistema = $dataC['color_barra_nav'];
    }
} catch (Exception $e) { }

// FILTROS DESDE / HASTA
$desde = $_GET['desde'] ?? date('Y-m-d');
$hasta = $_GET['hasta'] ?? date('Y-m-d');

// KPIs FILTRADOS
$stats = $conexion->prepare("SELECT COUNT(*) as total_operaciones, SUM(total) as monto_total FROM ventas WHERE DATE(fecha) >= ? AND DATE(fecha) <= ? AND estado = 'completada'");
$stats->execute([$desde, $hasta]);
$stats = $stats->fetch(PDO::FETCH_OBJ);

$stmtVentas = $conexion->prepare("SELECT v.*, c.nombre as cliente, u.usuario FROM ventas v LEFT JOIN clientes c ON v.id_cliente = c.id JOIN usuarios u ON v.id_usuario = u.id WHERE DATE(v.fecha) >= ? AND DATE(v.fecha) <= ? ORDER BY v.fecha DESC");
$stmtVentas->execute([$desde, $hasta]);
$ventas = $stmtVentas->fetchAll(PDO::FETCH_OBJ);

include 'includes/layout_header.php';
?>

<div class="header-blue" style="background: <?php echo $color_sistema; ?> !important; border-radius: 0 !important; width: 100vw; margin-left: calc(-50vw + 50%); padding: 40px 0; position: relative; overflow: hidden; z-index: 10;">
    <i class="bi bi-receipt bg-icon-large" style="z-index: 0;"></i>
    <div class="container position-relative" style="z-index: 2;">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="font-cancha mb-0 text-white">Historial de Ventas</h2>
                <p class="opacity-75 mb-0 text-white small">Registro histórico de tickets y comprobantes.</p>
            </div>
            <a href="reporte_ventas.php?desde=<?php echo $desde; ?>&hasta=<?php echo $hasta; ?>" target="_blank" class="btn btn-danger fw-bold rounded-pill px-4 shadow-sm">
                <i class="bi bi-file-earmark-pdf-fill me-2"></i> REPORTE PDF
            </a>
        </div>

        <div class="bg-white bg-opacity-10 p-3 rounded-4 shadow-sm d-inline-block border border-white border-opacity-25 mt-2 mb-4">
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

        <div class="row g-3">
            <div class="col-6 col-md-4">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Ventas Filtradas</div>
                        <div class="widget-value text-white"><?php echo $stats->total_operaciones; ?></div>
                    </div>
                    <div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-ticket-perforated"></i></div>
                </div>
            </div>
            <div class="col-6 col-md-4">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Recaudación</div>
                        <div class="widget-value text-white">$<?php echo number_format($stats->monto_total ?? 0, 0, ',', '.'); ?></div>
                    </div>
                    <div class="icon-box bg-success bg-opacity-20 text-white"><i class="bi bi-cash-stack"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5 mt-4">
    <div class="card card-custom border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light small text-uppercase text-muted">
                    <tr>
                        <th class="ps-4 py-3">ID / Fecha</th>
                        <th>Cliente</th>
                        <th>Vendedor</th>
                        <th>Método</th>
                        <th class="text-end">Total</th>
                        <th class="text-center pe-4">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($ventas)): ?>
                        <tr><td colspan="6" class="text-center py-5 text-muted">No se encontraron ventas en estas fechas.</td></tr>
                    <?php endif; ?>
                    
                    <?php foreach($ventas as $v): ?>
                    <tr>
                        <td class="ps-4">
                            <div class="fw-bold text-dark">#<?php echo str_pad($v->id, 6, '0', STR_PAD_LEFT); ?></div>
                            <small class="text-muted"><?php echo date('d/m/Y H:i', strtotime($v->fecha)); ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($v->cliente ?? 'Consumidor Final'); ?></td>
                        <td><span class="badge bg-light text-dark border"><i class="bi bi-person me-1"></i><?php echo strtoupper($v->usuario); ?></span></td>
                        <td><small class="fw-bold"><?php echo $v->metodo_pago; ?></small></td>
                        <td class="text-end fw-bold text-primary">$<?php echo number_format($v->total, 2, ',', '.'); ?></td>
                        <td class="text-center pe-4">
                            <button class="btn btn-sm btn-outline-dark rounded-pill px-3 shadow-sm fw-bold" onclick="verTicketDetalle(<?php echo $v->id; ?>)">
                                <i class="bi bi-receipt me-1"></i> VER
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function verTicketDetalle(id) {
    Swal.fire({
        title: 'Cargando Ticket...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    // El ticket de venta mantiene su propia estructura interna
    fetch('ajax_ticket_detalle.php?id=' + id)
        .then(response => response.text())
        .then(html => {
            Swal.fire({
                width: 350,
                html: html,
                showConfirmButton: true,
                confirmButtonText: '<i class="bi bi-printer-fill"></i> IMPRIMIR',
                showCloseButton: true,
                customClass: {
                    confirmButton: 'btn btn-primary rounded-pill px-4'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const win = window.open('ticket.php?id=' + id, '_blank');
                    win.focus();
                }
            });
        });
}
</script>

<?php include 'includes/layout_footer.php'; ?>