<?php
// clientes.php - VERSIÓN RESTAURADA (DISEÑO TOTAL + FIX BANDERITAS + GRID/LISTA)
session_start();
error_reporting(E_ALL); 
ini_set('display_errors', 1);

// 1. CONEXIÓN
$rutas_db = ['db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// --- CANDADOS DE SEGURIDAD ---
$permisos = $_SESSION['permisos'] ?? [];
$es_admin = ($_SESSION['rol'] <= 2);

if (!$es_admin && !in_array('ver_clientes', $permisos)) { 
    header("Location: dashboard.php"); exit; 
}

// 2. LÓGICA DE ACCIONES
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['reset_pass'])) {
    $nombre    = trim($_POST['nombre'] ?? '');
    $dni       = trim($_POST['dni'] ?? '');
    $telefono  = trim($_POST['telefono'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $fecha_nac = !empty($_POST['fecha_nacimiento']) ? $_POST['fecha_nacimiento'] : null;
    $limite    = floatval($_POST['limite'] ?? 0);
    $user_form = trim($_POST['usuario_form'] ?? '');
    $id_edit   = $_POST['id_edit'] ?? '';

    if ($id_edit && !$es_admin && !in_array('editar_cliente', $permisos)) die("Sin permiso para editar.");
    if (!$id_edit && !$es_admin && !in_array('crear_cliente', $permisos)) die("Sin permiso para crear.");

    if (!empty($nombre) && !empty($dni) && !empty($email)) {
        try {
            $tel_val = !empty($telefono) ? $telefono : null;
            $dir_val = !empty($direccion) ? $direccion : null;
            $nac_val = !empty($fecha_nac) ? $fecha_nac : null;

            if ($id_edit) {
                $stmtOld = $conexion->prepare("SELECT * FROM clientes WHERE id = ?");
                $stmtOld->execute([$id_edit]);
                $old = $stmtOld->fetch(PDO::FETCH_ASSOC);

                $cambios = [];
                if($old['nombre'] != $nombre) $cambios[] = "Nombre: " . $old['nombre'] . " -> " . $nombre;
                if($old['dni'] != $dni) $cambios[] = "DNI: " . ($old['dni']?:'-') . " -> " . $dni;
                if($old['telefono'] != $tel_val) $cambios[] = "Tel: " . ($old['telefono']?:'-') . " -> " . ($tel_val?:'-');
                if($old['email'] != $email) $cambios[] = "Email: " . ($old['email']?:'-') . " -> " . $email;
                if($old['direccion'] != $dir_val) $cambios[] = "Dir: " . ($old['direccion']?:'-') . " -> " . ($dir_val?:'-');
                if($old['fecha_nacimiento'] != $nac_val) $cambios[] = "Nac: " . ($old['fecha_nacimiento']?:'-') . " -> " . ($nac_val?:'-');
                if(floatval($old['limite_credito']) != $limite) $cambios[] = "Límite Fiado: $" . floatval($old['limite_credito']) . " -> $" . $limite;
                if($old['usuario'] != $user_form) $cambios[] = "Usuario Web: " . ($old['usuario']?:'-') . " -> " . ($user_form?:'-');

                $sql = "UPDATE clientes SET nombre=?, telefono=?, email=?, direccion=?, dni=?, dni_cuit=?, limite_credito=?, fecha_nacimiento=?, usuario=? WHERE id=?";
                $conexion->prepare($sql)->execute([$nombre, $tel_val, $email, $dir_val, $dni, $dni, $limite, $nac_val, $user_form, $id_edit]);

                if(!empty($cambios)) {
                    $detalles_audit = "Cliente Editado: " . $old['nombre'] . " | " . implode(" | ", $cambios);
                    $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'CLIENTE_EDITADO', ?, NOW())")->execute([$_SESSION['usuario_id'], $detalles_audit]);
                }

            } else {
                $sql = "INSERT INTO clientes (nombre, dni, dni_cuit, telefono, email, fecha_nacimiento, limite_credito, usuario, direccion, fecha_registro, saldo_deudor, puntos_acumulados) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 0, 0)";
                $conexion->prepare($sql)->execute([$nombre, $dni, $dni, $tel_val, $email, $nac_val, $limite, $user_form, $dir_val]);
                
                $detalles_audit = "Cliente Nuevo: " . $nombre . " (DNI: " . $dni . ")";
                $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'CLIENTE_NUEVO', ?, NOW())")->execute([$_SESSION['usuario_id'], $detalles_audit]);
            }

            header("Location: clientes.php?msg=ok"); exit;
            
        } catch (Exception $e) { die("Error Crítico DB: " . $e->getMessage()); }
    }
}

if (isset($_GET['borrar'])) {
    if (!$es_admin && !in_array('eliminar_cliente', $permisos)) die("Sin permiso para eliminar.");
    $id_b = intval($_GET['borrar']);
    try {
        $stmtDel = $conexion->prepare("SELECT nombre, dni FROM clientes WHERE id = ?");
        $stmtDel->execute([$id_b]);
        $oldDel = $stmtDel->fetch(PDO::FETCH_ASSOC);

        $conexion->prepare("UPDATE ventas SET id_cliente = 1 WHERE id_cliente = ?")->execute([$id_b]);
        $conexion->prepare("DELETE FROM movimientos_cc WHERE id_cliente = ?")->execute([$id_b]);
        $conexion->prepare("DELETE FROM clientes WHERE id = ?")->execute([$id_b]);

        if($oldDel) {
            $d_aud = "Cliente Eliminado: " . $oldDel['nombre'] . " (DNI: " . $oldDel['dni'] . ")";
            $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'CLIENTE_ELIMINADO', ?, NOW())")->execute([$_SESSION['usuario_id'], $d_aud]);
        }

        header("Location: clientes.php?msg=eliminado"); exit;
    } catch (Exception $e) { header("Location: clientes.php?error=db"); exit; }
}

$buscar = $_GET['buscar'] ?? ''; 
$estado = $_GET['estado'] ?? '';
$filtro_esp = $_GET['filtro'] ?? '';
$query_filtros = "buscar=" . urlencode($buscar) . "&estado=" . urlencode($estado) . "&filtro=" . urlencode($filtro_esp);

$cond = [];
if (isset($_GET['filtro']) && $_GET['filtro'] == 'cumple') {
    $cond[] = "MONTH(c.fecha_nacimiento) = MONTH(CURDATE()) AND DAY(c.fecha_nacimiento) = DAY(CURDATE())";
}
if (isset($_GET['estado'])) {
    if ($_GET['estado'] == 'deuda') $cond[] = "(SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = c.id AND tipo = 'debe') - (SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = c.id AND tipo = 'haber') > 0.1";
    if ($_GET['estado'] == 'aldia') $cond[] = "(SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = c.id AND tipo = 'debe') - (SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = c.id AND tipo = 'haber') <= 0.1";
}

if (!empty($buscar)) {
    $cond[] = "(c.nombre LIKE '%$buscar%' OR c.dni LIKE '%$buscar%')";
}

$where_clause = (count($cond) > 0) ? " WHERE " . implode(" AND ", $cond) : "";

$sql = "SELECT c.*, 
        (SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = c.id AND tipo = 'debe') - 
        (SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = c.id AND tipo = 'haber') as saldo_calculado,
        (SELECT MAX(fecha) FROM ventas WHERE id_cliente = c.id) as ultima_venta_fecha
        FROM clientes c $where_clause ORDER BY c.nombre ASC";

$clientes_query = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$clientes_json = [];
$totalDeudaCalle = 0; $cntDeudores = 0; $cntAlDia = 0;
$totalClientes = count($clientes_query);

foreach($clientes_query as $c) {
    $saldo = floatval($c['saldo_calculado']);
    if($saldo > 0.1) { $totalDeudaCalle += $saldo; $cntDeudores++; } else { $cntAlDia++; }
    
    $clientes_json[$c['id']] = [
        'id' => $c['id'], 'nombre' => htmlspecialchars($c['nombre']), 'dni' => $c['dni'] ?? '',
        'email' => $c['email'] ?? '', 'fecha_nacimiento' => $c['fecha_nacimiento'], 'telefono' => $c['telefono'] ?? '',
        'limite' => $c['limite_credito'], 'deuda' => $saldo, 'puntos' => $c['puntos_acumulados'],
        'usuario' => $c['usuario'] ?? '', 'direccion' => $c['direccion'] ?? '',
        'ultima_venta' => $c['ultima_venta_fecha'] ? date('d/m/Y', strtotime($c['ultima_venta_fecha'])) : 'Nunca'
    ];
}

$color_sistema = '#102A57';
try {
    $resColor = $conexion->query("SELECT color_barra_nav FROM configuracion WHERE id=1");
    if ($resColor) {
        $dataC = $resColor->fetch(PDO::FETCH_ASSOC);
        if (isset($dataC['color_barra_nav'])) $color_sistema = $dataC['color_barra_nav'];
    }
} catch (Exception $e) { }

include 'includes/layout_header.php'; 
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/css/intlTelInput.css">
<style>
    .cliente-row { transition: all 0.3s ease; border-radius: 12px; margin-bottom: 8px; }
    .cliente-row:hover { background-color: #f8faff !important; transform: scale(1.002); box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
    .avatar-wrapper { position: relative; }
    .status-dot { position: absolute; bottom: 0; right: 0; width: 12px; height: 12px; border: 2px solid #fff; border-radius: 50%; }
    .btn-action-custom { width: 35px; height: 35px; display: flex; align-items: center; justify-content: center; border-radius: 10px; transition: all 0.2s; background: #f1f4f9; border: none; }
    .btn-action-custom:hover { transform: translateY(-2px); }

    /* Filtros y Responsive */
    .filter-bar.sticky-desktop { position: sticky; top: 65px; z-index: 1000; background: #fff; margin-bottom: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
    @media (max-width: 768px) {
        .filter-bar.sticky-desktop { top: 10px; position: relative; padding: 10px; }
        .filter-content-wrapper { display: none; flex-direction: column; gap: 10px; padding-top: 15px; }
        .filter-content-wrapper.show { display: flex; }
        .tabla-movil-ajustada td, .tabla-movil-ajustada th { padding: 0.4rem 0.2rem !important; white-space: nowrap; }
        .tabla-movil-ajustada .fw-bold { font-size: 0.8rem !important; }
        .tabla-movil-ajustada .small { font-size: 0.65rem !important; }
        .avatar-wrapper .bg-primary { width: 35px !important; height: 35px !important; font-size: 0.9rem !important; border-radius: 10px !important; }
        .status-dot { width: 10px; height: 10px; }
    }
    @media (min-width: 769px) {
        .filter-content-wrapper { display: flex !important; width: 100%; gap: 10px; align-items: center; }
        .btn-toggle-filters { display: none; }
    }
</style>

<?php
$titulo = "Cartera de Clientes";
$subtitulo = "Gestión de cuentas y fidelización";
$icono_bg = "bi-people-fill";

$botones = [
    ['texto' => 'NUEVO CLIENTE', 'icono' => 'bi-person-plus-fill', 'class' => 'btn btn-light text-success fw-bold rounded-pill px-4 shadow-sm me-2', 'link' => 'javascript:abrirModalCrear()'],
    ['texto' => 'Reporte PDF', 'link' => "reporte_clientes.php?$query_filtros", 'icono' => 'bi-file-earmark-pdf-fill', 'class' => 'btn btn-danger fw-bold rounded-pill px-3 px-md-4 py-2 shadow-sm', 'target' => '_blank']
];
$widgets = [
    ['label' => 'Total Clientes', 'valor' => $totalClientes, 'icono' => 'bi-people', 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'Deuda en Calle', 'valor' => '$'.number_format($totalDeudaCalle, 0, ',', '.'), 'icono' => 'bi-graph-down-arrow', 'border' => 'border-danger', 'icon_bg' => 'bg-danger bg-opacity-20'],
    ['label' => 'Clientes al Día', 'valor' => $cntAlDia, 'icono' => 'bi-shield-check', 'border' => 'border-success', 'icon_bg' => 'bg-success bg-opacity-20']
];

include 'includes/componente_banner.php'; 
?>

<div class="container pb-5 mt-n4" style="position: relative; z-index: 20;">

    <div class="filter-bar sticky-desktop rounded-4 p-3 shadow-sm bg-white border">
        <div class="d-flex gap-2 w-100 d-md-none mb-2">
            <button class="btn btn-primary fw-bold btn-toggle-filters flex-fill m-0 shadow-sm" type="button" onclick="toggleFiltrosMovil()">
                <i class="bi bi-funnel-fill"></i> MOSTRAR FILTROS
            </button>
        </div>

        <div id="wrapperFiltros" class="filter-content-wrapper mt-md-0 mt-2">
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-center w-100 mb-0">
                <div class="search-group flex-fill mb-0" style="min-width: 200px;">
                    <i class="bi bi-search search-icon"></i>
                    <input type="text" name="buscar" id="buscador" class="search-input w-100" placeholder="Buscar por Nombre o DNI..." value="<?php echo htmlspecialchars($buscar); ?>" onkeyup="filtrarClientesLocal()">
                </div>
                <select name="estado" class="form-select form-select-sm border-light-subtle fw-bold" style="width: auto;" onchange="this.form.submit()">
                    <option value="">Todos los Estados</option>
                    <option value="deuda" <?php echo ($estado == 'deuda') ? 'selected' : ''; ?>>Con Deuda</option>
                    <option value="aldia" <?php echo ($estado == 'aldia') ? 'selected' : ''; ?>>Al Día</option>
                </select>
                <select name="filtro" class="form-select form-select-sm border-light-subtle fw-bold" style="width: auto;" onchange="this.form.submit()">
                    <option value="">Filtros Extra</option>
                    <option value="cumple" <?php echo ($filtro_esp == 'cumple') ? 'selected' : ''; ?>>🎂 Cumpleaños Hoy</option>
                </select>
                <a href="clientes.php" class="btn btn-light btn-sm fw-bold rounded-3 border px-3 d-flex align-items-center" style="height: 31px;">
                    <i class="bi bi-trash3-fill"></i>
                </a>
            </form>
        </div>
    </div>

    <div class="alert py-2 small mb-3 text-center fw-bold border-0 shadow-sm rounded-3" style="background-color: #e9f2ff; color: #102A57;">
        <i class="bi bi-hand-index-thumb-fill me-1"></i> Toca o haz clic en un cliente para ver su perfil y opciones
    </div>

    <div class="row g-3" id="gridProductos">
        <?php foreach($clientes_query as $c): 
            $deuda = floatval($c['saldo_calculado']);
            $limite = floatval($c['limite_credito']);
        ?>
        <div class="col-12 col-md-6 col-lg-3 item-grid" data-nombre="<?php echo strtolower($c['nombre'] . ' ' . ($c['dni'] ?? '')); ?>">
            <div class="card border-0 shadow-sm rounded-4 h-100 p-3 text-center" style="cursor: pointer; transition: transform 0.2s;" onclick="verResumen(<?php echo $c['id']; ?>)">
                <div class="mx-auto mb-3 position-relative" style="width: 70px; height: 70px;">
                    <div class="bg-primary text-white d-flex align-items-center justify-content-center fw-bold shadow-sm h-100 w-100" style="border-radius:20px; font-size: 1.5rem;">
                        <?php echo strtoupper(substr($c['nombre'], 0, 1) . substr(strrchr($c['nombre'], " "), 1, 1)); ?>
                    </div>
                    <div class="position-absolute bottom-0 end-0 <?php echo $deuda > 0.1 ? 'bg-danger' : 'bg-success'; ?>" style="width: 18px; height: 18px; border: 3px solid #fff; border-radius: 50%;"></div>
                </div>
                <h5 class="fw-bold mb-0 text-dark"><?php echo htmlspecialchars($c['nombre']); ?></h5>
                <p class="text-muted small mb-2">DNI: <?php echo $c['dni'] ?: '---'; ?></p>
                
                <div class="d-flex justify-content-between align-items-center mt-auto border-top pt-3">
                    <div class="text-start">
                        <small class="text-muted d-block" style="font-size: 10px; text-transform: uppercase;">Estado Cuenta</small>
                        <div class="<?php echo $deuda > 0.1 ? 'text-danger' : 'text-success'; ?> fw-bold fs-6">$<?php echo number_format($deuda, 2, ',', '.'); ?></div>
                    </div>
                    <div class="text-end">
                        <small class="text-muted d-block" style="font-size: 10px; text-transform: uppercase;">Puntos Bonus</small>
                        <div class="text-warning-dark fw-bold fs-6"><i class="bi bi-gem"></i> <?php echo number_format($c['puntos_acumulados'], 0); ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div id="vistaListaGenerica" class="d-none">
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-transparent">
            <div class="table-responsive px-0 pb-2">
                <table class="table align-middle mb-0 tabla-movil-ajustada" style="border-collapse: separate; border-spacing: 0 8px;">
                    <thead>
                        <tr class="text-muted" style="font-size: 0.65rem; letter-spacing: 1px;">
                            <th class="ps-4 border-0 text-uppercase" style="width: 30%;">Cliente</th>
                            <th class="border-0 text-uppercase d-none d-md-table-cell" style="width: 15%;">Acceso/DNI</th>
                            <th class="border-0 text-uppercase text-center" style="width: 15%;">Puntos</th>
                            <th class="border-0 text-uppercase" style="width: 15%;">Balance</th>
                            <th class="border-0 text-uppercase d-none d-md-table-cell" style="width: 15%;">Movimiento</th>
                            <th class="text-end pe-4 border-0 text-uppercase d-none d-md-table-cell" style="width: 10%;">Gestión</th>
                        </tr>
                    </thead>
                    <tbody>
                <?php foreach($clientes_query as $c): 
                    $deuda = floatval($c['saldo_calculado']);
                    $limite = floatval($c['limite_credito']);
                ?>
                <tr class="cliente-row bg-white shadow-sm item-lista" data-nombre="<?php echo strtolower($c['nombre'] . ' ' . ($c['dni'] ?? '')); ?>" onclick="verResumen(<?php echo $c['id']; ?>)" style="cursor: pointer;">
                    <td class="ps-2 ps-md-4 py-2 py-md-3" style="border-radius: 15px 0 0 15px;">
                        <div class="d-flex align-items-center">
                            <div class="avatar-wrapper me-3">
                                <div class="bg-primary text-white d-flex align-items-center justify-content-center fw-bold shadow-sm" style="width:48px; height:48px; border-radius:14px; font-size: 1.1rem;">
                                    <?php echo strtoupper(substr($c['nombre'], 0, 1) . substr(strrchr($c['nombre'], " "), 1, 1)); ?>
                                </div>
                                <div class="status-dot <?php echo $deuda > 0.1 ? 'bg-danger' : 'bg-success'; ?>"></div>
                            </div>
                            <div>
                                <div class="fw-bold text-dark mb-0" style="font-size: 0.95rem;"><?php echo htmlspecialchars($c['nombre']); ?></div>
                                <div class="text-muted small"><i class="bi bi-whatsapp me-1"></i><?php echo $c['telefono'] ?: 'Sin teléfono'; ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="d-none d-md-table-cell">
                        <div class="d-flex flex-column">
                            <span class="fw-bold text-secondary" style="font-size: 0.8rem;"><?php echo $c['dni'] ?: '---'; ?></span>
                            <span class="text-primary small fw-medium">@<?php echo $c['usuario'] ?: 'invitado'; ?></span>
                        </div>
                    </td>
                    <td class="text-center">
                        <div class="d-inline-block px-2 px-md-3 py-1 rounded-pill bg-warning bg-opacity-10 text-warning-dark fw-bold" style="font-size: 0.8rem;">
                            <i class="bi bi-gem me-1"></i><?php echo number_format($c['puntos_acumulados'], 0); ?>
                        </div>
                    </td>
                    <td style="border-radius: 0;">
                        <div class="p-1 p-md-2 rounded-3 <?php echo $deuda > 0.1 ? 'bg-danger bg-opacity-10' : 'bg-success bg-opacity-10'; ?>" style="max-width: 140px;">
                            <div class="<?php echo $deuda > 0.1 ? 'text-danger' : 'text-success'; ?> fw-black mb-0" style="font-size: 0.95rem;">
                                $<?php echo number_format($deuda, 2, ',', '.'); ?>
                            </div>
                            <div class="text-muted" style="font-size: 0.55rem; text-transform: uppercase;">Crédito: $<?php echo number_format($limite, 0); ?></div>
                        </div>
                    </td>
                    <td class="d-none d-md-table-cell">
                        <div class="text-dark small fw-bold"><i class="bi bi-calendar3 me-1 text-muted"></i><?php echo $c['ultima_venta_fecha'] ? date('d M, Y', strtotime($c['ultima_venta_fecha'])) : 'Sin compras'; ?></div>
                    </td>
                    <td class="text-end pe-4 d-none d-md-table-cell" style="border-radius: 0 15px 15px 0;">
                        <div class="d-flex justify-content-end gap-2">
                            <?php if($es_admin || in_array('cuenta_cliente', $permisos)): ?>
                            <a href="cuenta_cliente.php?id=<?php echo $c['id']; ?>" onclick="event.stopPropagation();" class="btn-action-custom text-primary" title="Cuenta"><i class="bi bi-wallet2"></i></a>
                            <?php endif; ?>

                            <?php if($es_admin || in_array('editar_cliente', $permisos)): ?>
                            <button class="btn-action-custom text-warning" onclick="event.stopPropagation(); editar(<?php echo $c['id']; ?>)" title="Editar"><i class="bi bi-pencil-square"></i></button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalGestionCliente" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow-lg rounded-4">
    <div class="modal-header bg-dark text-white border-0 py-3"><h5 class="modal-title fw-bold" id="titulo-modal">Nuevo Cliente</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body p-4"><form method="POST" id="formCliente">
        <input type="hidden" name="id_edit" id="id_edit">
        <div class="mb-3"><label class="form-label small fw-bold text-muted">Nombre Completo *</label><input type="text" name="nombre" id="nombre" class="form-control fw-bold" required></div>
        <div class="row g-2 mb-3">
            <div class="col-6"><label class="form-label small fw-bold text-muted">DNI *</label><input type="text" name="dni" id="dni" class="form-control" required></div>
            <div class="col-6"><label class="form-label small fw-bold text-muted">Usuario</label><input type="text" name="usuario_form" id="usuario_form" class="form-control"></div>
        </div>
        <div class="mb-3">
            <label class="form-label small fw-bold d-block text-muted">WhatsApp (Internacional)</label>
            <input type="tel" id="telefono_input" class="form-control fw-bold" style="width:100% !important;" required>
            <input type="hidden" name="telefono" id="telefono_final">
        </div>
        <div class="row g-2 mb-3">
            <div class="col-6"><label class="form-label small fw-bold text-muted">Fecha Nac.</label><input type="date" name="fecha_nacimiento" id="fecha_nacimiento" class="form-control"></div>
            <div class="col-6"><label class="form-label small fw-bold text-muted">Email *</label><input type="email" name="email" id="email" class="form-control" required></div>
        </div>
        <div class="mb-3"><label class="form-label small fw-bold text-muted">Dirección</label><input type="text" name="direccion" id="direccion" class="form-control"></div>
        <div class="mb-4 bg-light p-3 rounded-3 border"><label class="form-label small fw-bold text-danger">Límite de Fiado ($)</label><input type="number" name="limite" id="limite" class="form-control fw-bold text-danger" value="0"></div>
        <div class="d-grid"><button type="submit" class="btn btn-primary py-3 fw-bold rounded-pill shadow">GUARDAR CLIENTE</button></div>
    </form></div>
</div></div></div>

<div class="modal fade" id="modalResumen" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-md"><div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
    <div class="modal-header bg-primary text-white border-0 py-3">
        <h5 class="modal-title fw-bold"><i class="bi bi-person-badge me-2"></i>Perfil del Cliente</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body p-0">
        <div class="text-center py-4 bg-light border-bottom">
            <div class="bg-white shadow-sm d-inline-flex align-items-center justify-content-center mb-2" style="width:90px; height:90px; border-radius:24px; font-size:2.2rem; font-weight:900; color:#102A57; border: 3px solid #fff;" id="modal-avatar-res"></div>
            <h4 class="fw-bold mb-0" id="modal-nombre"></h4>
            <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3" id="modal-user"></span>
        </div>
        
        <div class="p-4">
            <div class="row g-3 mb-4 text-center">
                <div class="col-6">
                    <div class="p-3 rounded-4 bg-danger bg-opacity-10 border border-danger border-opacity-10">
                        <div class="small text-danger fw-bold text-uppercase" style="font-size:0.65rem;">Saldo Deudor</div>
                        <div class="h4 fw-black text-danger mb-0" id="modal-deuda"></div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="p-3 rounded-4 bg-warning bg-opacity-10 border border-warning border-opacity-10">
                        <div class="small text-warning-dark fw-bold text-uppercase" style="font-size:0.65rem;">Puntos Bonus</div>
                        <div class="h4 fw-black text-warning-dark mb-0" id="modal-puntos"></div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-6">
                    <label class="text-muted small fw-bold text-uppercase d-block mb-1" style="font-size:0.6rem;">Identificación</label>
                    <div class="fw-bold text-dark"><i class="bi bi-card-heading me-2 text-primary"></i><span id="modal-dni"></span></div>
                </div>
                <div class="col-6">
                    <label class="text-muted small fw-bold text-uppercase d-block mb-1" style="font-size:0.6rem;">WhatsApp</label>
                    <a href="#" id="modal-tel-link" target="_blank" class="text-decoration-none">
                        <div class="fw-bold text-dark"><i class="bi bi-whatsapp me-2 text-success"></i><span id="modal-tel"></span></div>
                    </a>
                </div>
                <div class="col-12">
                    <label class="text-muted small fw-bold text-uppercase d-block mb-1" style="font-size:0.6rem;">Correo Electrónico</label>
                    <a href="#" id="modal-email-link" class="text-decoration-none">
                        <div class="fw-bold text-dark"><i class="bi bi-envelope-at me-2 text-primary"></i><span id="modal-email"></span></div>
                    </a>
                </div>
                <div class="col-12">
                    <label class="text-muted small fw-bold text-uppercase d-block mb-1" style="font-size:0.6rem;">Dirección Física</label>
                    <a href="#" id="modal-dir-link" target="_blank" class="text-decoration-none">
                        <div class="fw-bold text-dark"><i class="bi bi-geo-alt me-2 text-danger"></i><span id="modal-dir"></span></div>
                    </a>
                </div>
                <div class="col-6">
                    <label class="text-muted small fw-bold text-uppercase d-block mb-1" style="font-size:0.6rem;">Cumpleaños</label>
                    <div class="fw-bold text-dark"><i class="bi bi-cake2 me-2 text-info"></i><span id="modal-nac"></span></div>
                </div>
                <div class="col-6 text-end">
                    <label class="text-muted small fw-bold text-uppercase d-block mb-1" style="font-size:0.6rem;">Última Compra</label>
                    <div class="fw-bold text-dark" id="modal-ultima"></div>
                </div>
            </div>

            <div class="d-grid mt-4 pt-3 border-top gap-2">
                <a href="#" id="btn-ir-cuenta" class="btn btn-primary fw-bold shadow-sm py-3 rounded-pill">
                    <i class="bi bi-wallet2 me-2"></i>GESTIONAR CUENTA CORRIENTE
                </a>
                <div class="d-flex justify-content-center gap-2 mt-2">
                    <?php if($es_admin || in_array('editar_cliente', $permisos)): ?>
                    <button type="button" id="btn-edit-mob" class="btn btn-outline-warning fw-bold px-4 rounded-pill flex-grow-1"><i class="bi bi-pencil-square me-1"></i> EDITAR</button>
                    <?php endif; ?>
                    <?php if($es_admin || in_array('eliminar_cliente', $permisos)): ?>
                    <button type="button" id="btn-del-mob" class="btn btn-outline-danger fw-bold px-4 rounded-pill flex-grow-1"><i class="bi bi-trash3-fill me-1"></i> BORRAR</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div></div></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/js/intlTelInput.min.js"></script>
<script>
    var clientesDB = <?php echo json_encode($clientes_json); ?>;
    const phoneInput = window.intlTelInput(document.querySelector("#telefono_input"), { 
        initialCountry: "ar", preferredCountries: ["ar", "bo", "cl", "py", "uy"], 
        separateDialCode: true, utilsScript: "https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/js/utils.js" 
    });

    function abrirModalCrear() { $('#formCliente')[0].reset(); $('#id_edit').val(''); $('#titulo-modal').text("Nuevo Cliente"); $('#modalGestionCliente').modal('show'); }
    function editar(id) { 
        var d = clientesDB[id]; $('#id_edit').val(d.id); $('#nombre').val(d.nombre); $('#dni').val(d.dni); $('#email').val(d.email); $('#direccion').val(d.direccion); $('#limite').val(d.limite); $('#usuario_form').val(d.usuario); $('#fecha_nacimiento').val(d.fecha_nacimiento);
        phoneInput.setNumber(d.telefono || ''); $('#titulo-modal').text("Editar Cliente"); $('#modalGestionCliente').modal('show');
    }
    function verResumen(id) { 
        var d = clientesDB[id]; 
        $('#modal-nombre').text(d.nombre); 
        $('#modal-dni').text(d.dni || '---'); 
        $('#modal-avatar-res').text(d.nombre.substring(0,2).toUpperCase()); 
        $('#modal-deuda').text('$' + Number(d.deuda).toLocaleString('es-AR', {minimumFractionDigits: 2})); 
        $('#modal-puntos').text(Number(d.puntos).toLocaleString('es-AR')); 
        $('#modal-user').text('@' + (d.usuario || 'invitado'));
        
        let tel = d.telefono || '';
        $('#modal-tel').text(tel || 'No registrado');
        if(tel) {
            let telLimpio = tel.replace(/\D/g, ''); 
            $('#modal-tel-link').attr('href', 'https://wa.me/' + telLimpio).show();
        } else { $('#modal-tel-link').hide(); }

        let email = d.email || '';
        $('#modal-email').text(email || 'Sin correo');
        if(email) {
            $('#modal-email-link').attr('href', 'mailto:' + email).show();
        } else { $('#modal-email-link').hide(); }

        let dir = d.direccion || '';
        $('#modal-dir').text(dir || 'Sin dirección');
        if(dir) {
            $('#modal-dir-link').attr('href', 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(dir)).show();
        } else { $('#modal-dir-link').hide(); }
        
        if(d.fecha_nacimiento && d.fecha_nacimiento !== '0000-00-00') {
            let fecha = new Date(d.fecha_nacimiento + 'T00:00:00');
            $('#modal-nac').text(fecha.toLocaleDateString('es-AR', {day: '2-digit', month: 'long'}));
        } else {
            $('#modal-nac').text('No registrada');
        }

        $('#modal-ultima').text(d.ultima_venta || 'Sin actividad');
        
        $('#btn-ir-cuenta').attr('href', 'cuenta_cliente.php?id=' + d.id); 
        $('#btn-edit-mob').attr('onclick', `$('#modalResumen').modal('hide'); setTimeout(()=>editar(${d.id}), 400);`);
        $('#btn-del-mob').attr('onclick', `$('#modalResumen').modal('hide'); setTimeout(()=>borrarCliente(${d.id}), 400);`);
        $('#modalResumen').modal('show');
    }
    $('#formCliente').submit(function(){ $('#telefono_final').val(phoneInput.getNumber()); });
    
    function filtrarClientesLocal() { 
        var val = $('#buscador').val().toUpperCase();
        $('.item-grid').each(function(){ $(this).toggle($(this).data('nombre').toUpperCase().indexOf(val) > -1); });
        $('.item-lista').each(function(){ $(this).toggle($(this).data('nombre').toUpperCase().indexOf(val) > -1); });
    }
    
    function toggleFiltrosMovil() {
        const wrapper = document.getElementById('wrapperFiltros');
        const btn = document.querySelector('.btn-toggle-filters');
        wrapper.classList.toggle('show');
        if (wrapper.classList.contains('show')) {
            btn.innerHTML = '<i class="bi bi-chevron-up"></i> OCULTAR FILTROS';
        } else {
            btn.innerHTML = '<i class="bi bi-funnel-fill"></i> MOSTRAR FILTROS';
        }
    }
    
    function borrarCliente(id) { Swal.fire({ title: '¿Eliminar?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí' }).then((r) => { if (r.isConfirmed) window.location.href = 'clientes.php?borrar=' + id; }); }

    document.getElementById('nombre').addEventListener('input', function(e) {
        if($('#id_edit').val() === '') {
            var parts = e.target.value.trim().toLowerCase().split(' ');
            if(parts.length >= 2) $('#usuario_form').val((parts[0].charAt(0) + parts[parts.length - 1]).replace(/[^a-z0-9]/g, ""));
        }
    });
</script>
<?php include 'includes/layout_footer.php'; ?>