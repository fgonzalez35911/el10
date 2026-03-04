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

// OBTENER USUARIOS PARA EL FILTRO
$stmtUsu = $conexion->query("SELECT id, usuario FROM usuarios ORDER BY usuario ASC");
$usuarios_lista = $stmtUsu->fetchAll(PDO::FETCH_ASSOC);

// FILTROS
$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-2 months'));
$hasta = $_GET['hasta'] ?? date('Y-m-d');
$f_estado = $_GET['estado'] ?? '';
$f_usuario = $_GET['id_usuario'] ?? '';

$condiciones = ["DATE(v.fecha) >= ?", "DATE(v.fecha) <= ?"];
$parametros = [$desde, $hasta];

if ($f_estado !== '') { $condiciones[] = "v.estado = ?"; $parametros[] = $f_estado; }
if ($f_usuario !== '') { $condiciones[] = "v.id_usuario = ?"; $parametros[] = $f_usuario; }

$where_sql = " WHERE " . implode(" AND ", $condiciones);

// KPIs FILTRADOS
$sqlStats = "SELECT COUNT(*) as total_operaciones, SUM(total) as monto_total FROM ventas v $where_sql AND v.estado = 'completada'";
$stmtStats = $conexion->prepare($sqlStats);
$stmtStats->execute($parametros);
$stats = $stmtStats->fetch(PDO::FETCH_OBJ);

$totalFiltrado = $stats->monto_total ?? 0;

// LISTADO
$sqlVentas = "SELECT v.*, c.nombre as cliente, u.usuario FROM ventas v LEFT JOIN clientes c ON v.id_cliente = c.id JOIN usuarios u ON v.id_usuario = u.id $where_sql ORDER BY v.fecha DESC";
$stmtVentas = $conexion->prepare($sqlVentas);
$stmtVentas->execute($parametros);
$ventas = $stmtVentas->fetchAll(PDO::FETCH_OBJ);

$query_filtros = !empty($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : "desde=$desde&hasta=$hasta";

include 'includes/layout_header.php';
?>

<?php
// --- DEFINICIÓN DEL BANNER DINÁMICO ---
$titulo = "Historial de Ventas";
$subtitulo = "Consulta y gestión de transacciones realizadas.";
$icono_bg = "bi-clock-history";

$botones = [
    ['texto' => 'PDF', 'link' => "reporte_ventas.php?$query_filtros", 'icono' => 'bi-file-earmark-pdf-fill', 'class' => 'btn btn-danger btn-sm fw-bold rounded-pill px-3 shadow-sm', 'target' => '_blank']
];

$widgets = [
    ['label' => 'Ventas Filtradas', 'valor' => '$'.number_format($totalFiltrado, 0, ',', '.'), 'icono' => 'bi-cash-stack', 'border' => 'border-danger', 'icon_bg' => 'bg-danger bg-opacity-20'],
    ['label' => 'Tickets', 'valor' => count($ventas), 'icono' => 'bi-receipt', 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'Ticket Promedio', 'valor' => '$'.((count($ventas) > 0) ? number_format($totalFiltrado / count($ventas), 0, ',', '.') : '0'), 'icono' => 'bi-graph-up', 'border' => 'border-info', 'icon_bg' => 'bg-info bg-opacity-20']
];

include 'includes/componente_banner.php'; 
?>

<div class="container mt-n4 pb-5" style="position: relative; z-index: 20;">
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-2 p-md-3">
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-end w-100">
                <div class="flex-grow-1" style="min-width: 120px;">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Desde</label>
                    <input type="date" name="desde" class="form-control form-control-sm border-light-subtle fw-bold" value="<?php echo $desde; ?>">
                </div>
                <div class="flex-grow-1" style="min-width: 120px;">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Hasta</label>
                    <input type="date" name="hasta" class="form-control form-control-sm border-light-subtle fw-bold" value="<?php echo $hasta; ?>">
                </div>
                <div class="flex-grow-1" style="min-width: 120px;">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Estado</label>
                    <select name="estado" class="form-select form-select-sm border-light-subtle fw-bold">
                        <option value="">Todos</option>
                        <option value="completada" <?php echo ($f_estado === 'completada') ? 'selected' : ''; ?>>Completada</option>
                        <option value="anulada" <?php echo ($f_estado === 'anulada') ? 'selected' : ''; ?>>Anulada</option>
                    </select>
                </div>
                <div class="flex-grow-1" style="min-width: 120px;">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Vendedor</label>
                    <select name="id_usuario" class="form-select form-select-sm border-light-subtle fw-bold">
                        <option value="">Todos</option>
                        <?php foreach($usuarios_lista as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo ($f_usuario == $u['id']) ? 'selected' : ''; ?>><?php echo strtoupper($u['usuario']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex-grow-0 d-flex gap-2 mt-2 mt-md-0">
                    <button type="submit" class="btn btn-primary btn-sm fw-bold rounded-3 shadow-sm px-3" style="height: 31px;">
                        <i class="bi bi-funnel-fill me-1"></i> FILTRAR
                    </button>
                    <a href="historial_ventas.php" class="btn btn-light btn-sm fw-bold rounded-3 border px-3" style="height: 31px; display: flex; align-items: center;">
                        <i class="bi bi-trash3-fill"></i> LIMPIAR
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light text-muted small uppercase">
                    <tr>
                        <th class="ps-4">TICKET</th>
                        <th>Fecha/Hora</th>
                        <th>Cliente</th>
                        <th>Vendedor</th>
                        <th>Método</th>
                        <th class="text-end">Total</th>
                        <th class="text-end pe-4">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($ventas)): ?>
                        <tr><td colspan="7" class="text-center py-5 text-muted">No se encontraron registros.</td></tr>
                    <?php endif; ?>
                    <?php foreach($ventas as $v): ?>
                    <tr style="cursor: pointer;" onclick="verTicketDetalle(<?php echo $v->id; ?>)">
                        <td class="ps-4 fw-bold text-muted">#<?php echo str_pad($v->id, 6, '0', STR_PAD_LEFT); ?></td>
                        <td>
                            <div class="fw-bold"><?php echo date('d/m/y', strtotime($v->fecha)); ?></div>
                            <small class="text-muted"><?php echo date('H:i', strtotime($v->fecha)); ?> hs</small>
                        </td>
                        <td><div class="fw-bold"><?php echo htmlspecialchars($v->cliente ?? 'CONSUMIDOR FINAL'); ?></div></td>
                        <td><div class="small fw-bold text-uppercase text-muted"><?php echo htmlspecialchars($v->usuario ?? 'N/A'); ?></div></td>
                        <td><span class="badge bg-light text-dark border fw-bold"><?php echo strtoupper($v->metodo_pago); ?></span></td>
                        <td class="text-end fw-bold text-primary">$<?php echo number_format($v->total, 2, ',', '.'); ?></td>
                        <td class="text-end pe-4"><button class="btn btn-sm btn-outline-primary rounded-pill px-3">VER TICKET</button></td>
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