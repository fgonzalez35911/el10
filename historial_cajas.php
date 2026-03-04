<?php
// historial_cajas.php - AUDITORÍA INTERACTIVA (VERSIÓN FINAL LIMPIA)
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// --- CANDADOS DE SEGURIDAD ---
$permisos = $_SESSION['permisos'] ?? [];
$rol = $_SESSION['rol'] ?? 3;
$es_admin = ($rol <= 2);

if (!$es_admin && !in_array('ver_historial_cajas', $permisos)) { 
    header("Location: dashboard.php"); exit; 
}

// === A. MOTOR DE AUDITORÍA RÁPIDA (AJAX) ===
if (isset($_GET['ajax_detalle'])) {
    header('Content-Type: application/json');
    $id_ses = intval($_GET['ajax_detalle']);
    
    try {
        $stmtC = $conexion->prepare("SELECT c.*, u.nombre_completo FROM cajas_sesion c JOIN usuarios u ON c.id_usuario = u.id WHERE c.id = ?");
        $stmtC->execute([$id_ses]);
        $caja = $stmtC->fetch(PDO::FETCH_ASSOC);
        if (!$caja) { echo json_encode(['status' => 'error']); exit; }

        $stmtV = $conexion->prepare("SELECT metodo_pago, SUM(total) as monto FROM ventas WHERE id_caja_sesion = ? AND estado='completada' GROUP BY metodo_pago");
        $stmtV->execute([$id_ses]);
        $ventas = $stmtV->fetchAll(PDO::FETCH_ASSOC);

        $stmtG = $conexion->prepare("SELECT descripcion, monto, categoria FROM gastos WHERE id_caja_sesion = ?");
        $stmtG->execute([$id_ses]);
        $gastos = $stmtG->fetchAll(PDO::FETCH_ASSOC);
        
        $total_gastos_calc = 0;
        foreach($gastos as $g) { $total_gastos_calc += floatval($g['monto']); }

        echo json_encode([
            'status' => 'success',
            'caja' => $caja,
            'total_gastos' => $total_gastos_calc,
            'ventas' => $ventas,
            'gastos' => $gastos
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]); exit;
    }
}

// === B. LÓGICA DE CARGA NORMAL ===
$color_sistema = '#102A57';
try {
    $resColor = $conexion->query("SELECT color_barra_nav FROM configuracion WHERE id=1");
    if ($resColor) {
        $dataC = $resColor->fetch(PDO::FETCH_ASSOC);
        if (isset($dataC['color_barra_nav'])) $color_sistema = $dataC['color_barra_nav'];
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

$condiciones = ["DATE(c.fecha_apertura) >= ?", "DATE(c.fecha_apertura) <= ?"];
$parametros = [$desde, $hasta];

if ($f_estado !== '') { $condiciones[] = "c.estado = ?"; $parametros[] = $f_estado; }
if ($f_usuario !== '') { $condiciones[] = "c.id_usuario = ?"; $parametros[] = $f_usuario; }

$sql = "SELECT c.*, u.usuario, u.nombre_completo FROM cajas_sesion c JOIN usuarios u ON c.id_usuario = u.id WHERE " . implode(" AND ", $condiciones) . " ORDER BY c.id DESC";
$stmt = $conexion->prepare($sql);
$stmt->execute($parametros);
$cajas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_ventas_hist = 0; $dif_neta = 0; $cajas_con_error = 0;
foreach($cajas as $c) {
    if($c['estado'] == 'cerrada') {
        $total_ventas_hist += floatval($c['total_ventas']);
        $dif_neta += floatval($c['diferencia']);
        if(abs(floatval($c['diferencia'])) > 0.01) $cajas_con_error++;
    }
}

$query_filtros = $_SERVER['QUERY_STRING'] ? $_SERVER['QUERY_STRING'] : "desde=$desde&hasta=$hasta";

include 'includes/layout_header.php'; 
?>

<?php
// --- BANNER DINÁMICO ---
$titulo = "Historial de Cajas";
$subtitulo = "Auditoría microscópica de cierres y control de diferencias.";
$icono_bg = "bi-clock-history";

$botones = [
    ['texto' => 'PDF', 'link' => "reporte_cajas.php?$query_filtros", 'icono' => 'bi-file-earmark-pdf-fill', 'class' => 'btn btn-danger btn-sm fw-bold rounded-pill px-3 shadow-sm', 'target' => '_blank']
];

$widgets = [
    ['label' => 'Ventas Filtradas', 'valor' => '$'.number_format($total_ventas_hist, 0, ',', '.'), 'icono' => 'bi-cash-stack', 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'Balance de Diferencia', 'valor' => '$'.number_format($dif_neta, 2, ',', '.'), 'icono' => 'bi-intersect', 'border' => ($dif_neta < 0 ? 'border-danger' : 'border-success'), 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'Alertas de Error', 'valor' => $cajas_con_error, 'icono' => 'bi-exclamation-octagon', 'border' => 'border-danger', 'icon_bg' => 'bg-danger bg-opacity-20']
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
                        <option value="abierta" <?php echo ($f_estado === 'abierta') ? 'selected' : ''; ?>>Abierta</option>
                        <option value="cerrada" <?php echo ($f_estado === 'cerrada') ? 'selected' : ''; ?>>Cerrada</option>
                    </select>
                </div>
                <div class="flex-grow-1" style="min-width: 120px;">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Responsable</label>
                    <select name="id_usuario" class="form-select form-select-sm border-light-subtle fw-bold">
                        <option value="">Todos</option>
                        <?php foreach($usuarios_lista as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo ($f_usuario == $u['id']) ? 'selected' : ''; ?>><?php echo strtoupper($u['usuario']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex-grow-0 d-flex gap-2 mt-2 mt-md-0">
                    <button type="submit" class="btn btn-primary btn-sm fw-bold rounded-3 shadow-sm px-3" style="height: 31px;">
                        <i class="bi bi-funnel-fill"></i> FILTRAR
                    </button>
                    <a href="historial_cajas.php" class="btn btn-light btn-sm fw-bold rounded-3 border px-3" style="height: 31px; display: flex; align-items: center;">
                        <i class="bi bi-trash3-fill"></i> LIMPIAR
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="card card-custom border-0 shadow-sm rounded-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light text-muted small uppercase">
                    <tr><th class="ps-4">SESIÓN</th><th>Responsable</th><th>Apertura/Cierre</th><th class="text-end">Ventas</th><th class="text-center">Estado</th><th class="text-end pe-4">Acciones</th></tr>
                </thead>
                <tbody>
                    <?php if(empty($cajas)): ?>
                        <tr><td colspan="6" class="text-center py-5 text-muted">No se encontraron registros.</td></tr>
                    <?php endif; ?>
                    <?php foreach($cajas as $c): 
                        $dif = floatval($c['diferencia']);
                        $apertura = date('d/m/y H:i', strtotime($c['fecha_apertura']));
                        $cierre = $c['fecha_cierre'] ? date('d/m/y H:i', strtotime($c['fecha_cierre'])) : '-';
                        $badge = ($c['estado'] == 'abierta') ? '<span class="badge bg-primary">ABIERTA</span>' : (abs($dif) < 0.01 ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-danger">ERROR</span>');
                    ?>
                    <tr style="cursor: pointer;" onclick="abrirAuditoria(<?php echo $c['id']; ?>)">
                        <td class="ps-4 fw-bold text-muted">CJ-<?php echo str_pad($c['id'], 6, '0', STR_PAD_LEFT); ?></td>
                        <td><div class="fw-bold"><?php echo htmlspecialchars($c['usuario']); ?></div></td>
                        <td><div class="small">In: <?php echo $apertura; ?><br>Out: <?php echo $cierre; ?></div></td>
                        <td class="text-end fw-bold">$<?php echo number_format($c['total_ventas'], 2, ',', '.'); ?></td>
                        <td class="text-center"><?php echo $badge; ?></td>
                        <td class="text-end pe-4"><button class="btn btn-sm btn-outline-primary rounded-pill px-3">AUDITAR</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAuditoria" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content rounded-4 border-0">
            <div id="headerAuditoria" class="modal-header text-white">
                <h5 class="fw-bold mb-0" id="tituloModal">Auditoría de Sesión</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="cuerpoAuditoria"></div>
        </div>
    </div>
</div>

<script>
function abrirAuditoria(id) {
    const modal = new bootstrap.Modal(document.getElementById('modalAuditoria'));
    const cuerpo = document.getElementById('cuerpoAuditoria');
    const header = document.getElementById('headerAuditoria');
    const titulo = document.getElementById('tituloModal');

    cuerpo.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div></div>';
    modal.show();

    fetch(`historial_cajas.php?ajax_detalle=${id}`)
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                const c = data.caja;
                const gastos = data.total_gastos;
                const esAbierta = (c.estado === 'abierta');
                const esperado = parseFloat(c.monto_inicial) + parseFloat(c.total_ventas) - gastos;
                const dif = esAbierta ? 0 : parseFloat(c.diferencia || 0);

                header.className = `modal-header text-white ${esAbierta ? 'bg-primary' : (Math.abs(dif) < 0.01 ? 'bg-success' : 'bg-danger')}`;
                titulo.innerText = `Sesión CJ-${String(c.id).padStart(6, '0')} - ${c.nombre_completo}`;

                cuerpo.innerHTML = `
                    <div class="row g-4">
                        <div class="col-md-6">
                            <h6 class="fw-bold text-muted small text-uppercase mb-3">Balance de Efectivo</h6>
                            <div class="list-group list-group-flush border rounded-3">
                                <div class="list-group-item d-flex justify-content-between"><span>Efectivo Inicial</span><span>$${format(c.monto_inicial)}</span></div>
                                <div class="list-group-item d-flex justify-content-between"><span>Ventas (+)</span><span class="text-success fw-bold">+$${format(c.total_ventas)}</span></div>
                                <div class="list-group-item d-flex justify-content-between"><span>Egresos (-)</span><span class="text-danger fw-bold">-$${format(gastos)}</span></div>
                                <div class="list-group-item d-flex justify-content-between bg-light fw-bold"><span>SALDO ESPERADO</span><span>$${format(esperado)}</span></div>
                                <div class="list-group-item d-flex justify-content-between bg-dark text-white">
                                    <span>EFECTIVO DECLARADO</span>
                                    <span class="h5 mb-0 fw-bold">${esAbierta ? 'PENDIENTE' : '$' + format(c.monto_final)}</span>
                                </div>
                            </div>
                            <div class="mt-3 p-3 rounded-3 text-center ${esAbierta ? 'bg-light border text-muted' : (Math.abs(dif) < 0.01 ? 'bg-success text-white' : 'bg-danger text-white')}">
                                <div class="small text-uppercase fw-bold">Diferencia</div>
                                <div class="h4 mb-0 fw-bold">${esAbierta ? 'CAJA ABIERTA' : '$' + format(dif)}</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6 class="fw-bold text-muted small text-uppercase mb-3">Ventas por Método</h6>
                            <table class="table table-sm mb-4">
                                ${data.ventas.map(v => `<tr><td>${v.metodo_pago.toUpperCase()}</td><td class="text-end fw-bold">$${format(v.monto)}</td></tr>`).join('')}
                                ${data.ventas.length === 0 ? '<tr><td colspan=\"2\" class=\"text-center text-muted small py-2\">Sin ventas</td></tr>' : ''}
                            </table>
                            <h6 class="fw-bold text-muted small text-uppercase mb-3">Detalle de Gastos</h6>
                            <table class="table table-sm">
                                ${data.gastos.map(g => `<tr><td>${g.descripcion}<br><small class=\"text-muted\">${g.categoria}</small></td><td class=\"text-end text-danger\">-$${format(g.monto)}</td></tr>`).join('')}
                                ${data.gastos.length === 0 ? '<tr><td colspan=\"2\" class=\"text-center text-muted small py-2\">Sin gastos</td></tr>' : ''}
                            </table>
                        </div>
                    </div>`;
            }
        });
}
function format(n) { return parseFloat(n).toLocaleString('es-AR', { minimumFractionDigits: 2 }); }
</script>

<?php include 'includes/layout_footer.php'; ?>