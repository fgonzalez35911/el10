<?php
// historial_ventas.php - GESTIÓN INTEGRAL (RESTRUCTURADO Y CORREGIDO)
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// --- CANDADOS DE SEGURIDAD ---
$permisos = $_SESSION['permisos'] ?? [];
$es_admin = (($_SESSION['rol'] ?? 3) <= 2);

if (!$es_admin && !in_array('ver_historial_ventas', $permisos)) { 
    header("Location: dashboard.php"); exit; 
}

$color_sistema = '#102A57';
try {
    $resColor = $conexion->query("SELECT color_barra_nav FROM configuracion WHERE id=1");
    if ($resColor) {
        $dataC = $resColor->fetch(PDO::FETCH_ASSOC);
        if ($dataC && isset($dataC['color_barra_nav'])) $color_sistema = $dataC['color_barra_nav'];
    }
} catch (Exception $e) { }

// FILTROS: Predeterminado últimos 2 meses
$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-2 months'));
$hasta = $_GET['hasta'] ?? date('Y-m-d');

// KPIs FILTRADOS (Corregido: Ahora usamos $stats para los widgets)
$stats = $conexion->prepare("SELECT COUNT(*) as total_operaciones, SUM(total) as monto_total FROM ventas WHERE DATE(fecha) >= ? AND DATE(fecha) <= ? AND estado = 'completada'");
$stats->execute([$desde, $hasta]);
$stats = $stats->fetch(PDO::FETCH_OBJ);

$totalFiltrado = $stats->monto_total ?? 0;

$stmtVentas = $conexion->prepare("SELECT v.*, c.nombre as cliente, u.usuario FROM ventas v LEFT JOIN clientes c ON v.id_cliente = c.id JOIN usuarios u ON v.id_usuario = u.id WHERE DATE(v.fecha) >= ? AND DATE(v.fecha) <= ? ORDER BY v.fecha DESC");
$stmtVentas->execute([$desde, $hasta]);
$ventas = $stmtVentas->fetchAll(PDO::FETCH_OBJ);

include 'includes/layout_header.php';
?>

<?php
// --- DEFINICIÓN DEL BANNER DINÁMICO ---
$titulo = "Historial de Ventas";
$subtitulo = "Consulta y gestión de transacciones realizadas.";
$icono_bg = "bi-clock-history";

$botones = [
    ['texto' => 'PDF', 'link' => "reporte_ventas.php?desde=$desde&hasta=$hasta", 'icono' => 'bi-file-earmark-pdf-fill', 'class' => 'btn btn-danger btn-sm fw-bold rounded-pill px-3 shadow-sm', 'target' => '_blank']
];

$widgets = [
    ['label' => 'Ventas Filtradas', 'valor' => '$'.number_format($totalFiltrado, 0, ',', '.'), 'icono' => 'bi-cash-stack', 'border' => 'border-danger', 'icon_bg' => 'bg-danger bg-opacity-20'],
    ['label' => 'Tickets', 'valor' => count($ventas), 'icono' => 'bi-receipt', 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'Ticket Promedio', 'valor' => '$'.((count($ventas) > 0) ? number_format($totalFiltrado / count($ventas), 0, ',', '.') : '0'), 'icono' => 'bi-graph-up', 'border' => 'border-info', 'icon_bg' => 'bg-info bg-opacity-20']
];

include 'includes/componente_banner.php'; 
?>

<div class="container pb-5 mt-n4">
    <div class="card border-0 shadow-sm rounded-4 mb-4" style="position: relative; z-index: 20;">
        <div class="card-body p-3">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4 col-6">
                    <label class="small fw-bold text-muted text-uppercase mb-1">Desde</label>
                    <input type="date" name="desde" class="form-control border-light-subtle fw-bold" value="<?php echo $desde; ?>">
                </div>
                <div class="col-md-4 col-6">
                    <label class="small fw-bold text-muted text-uppercase mb-1">Hasta</label>
                    <input type="date" name="hasta" class="form-control border-light-subtle fw-bold" value="<?php echo $hasta; ?>">
                </div>
                <div class="col-md-4 col-12">
                    <button type="submit" class="btn btn-primary w-100 fw-bold rounded-pill shadow-sm">
                        <i class="bi bi-funnel-fill me-2"></i> APLICAR FILTROS
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card card-custom border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light text-muted small text-uppercase">
                    <tr>
                        <th class="ps-4">Fecha</th>
                        <th>Cliente</th>
                        <th>Vendedor</th>
                        <th>Método</th>
                        <th class="text-end pe-4">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($ventas)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-5 text-muted">No se encontraron ventas en este período.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($ventas as $v): ?>
                        <tr style="cursor: pointer;" onclick="verTicketDetalle(<?php echo $v->id; ?>)">
                            <td class="ps-4">
                                <div class="fw-bold"><?php echo date('d/m/Y', strtotime($v->fecha)); ?></div>
                                <small class="text-muted"><?php echo date('H:i', strtotime($v->fecha)); ?> hs</small>
                            </td>
                            <td><?php echo htmlspecialchars($v->cliente ?? 'Consumidor Final'); ?></td>
                            <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($v->usuario ?? 'N/A'); ?></span></td>
                            <td><small class="fw-bold text-uppercase"><?php echo $v->metodo_pago; ?></small></td>
                            <td class="text-end fw-bold text-primary pe-4">$<?php echo number_format($v->total, 2, ',', '.'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
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

    fetch('ajax_ticket_detalle.php?id=' + id)
        .then(response => response.text())
        .then(html => {
            Swal.fire({
                width: 350,
                html: html,
                showConfirmButton: true,
                confirmButtonText: '<i class="bi bi-printer-fill"></i> IMPRIMIR',
                showCloseButton: true,
                customClass: { confirmButton: 'btn btn-primary rounded-pill px-4' }
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