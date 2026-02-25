<?php
// gestionar_premios.php - VERSIÓN PREMIUM CON FILTROS, EDICIÓN Y REPORTE
session_start();
require_once 'includes/db.php';

// SEGURIDAD
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] > 2) { header("Location: dashboard.php"); exit; }

$mensaje = '';

// 1. CARGAR PRODUCTOS Y COMBOS PARA EL SELECTOR (Original)
$prods_db = $conexion->query("SELECT id, descripcion FROM productos WHERE activo=1 AND tipo != 'combo' ORDER BY descripcion")->fetchAll(PDO::FETCH_ASSOC);
$combos_db = $conexion->query("SELECT id, nombre FROM combos WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// 2. LÓGICA DE AGREGAR PREMIO (Original corregida)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['agregar'])) {
    try {
        $nombre = $_POST['nombre'];
        $puntos = $_POST['puntos'];
        $stock = $_POST['stock'];
        $es_cupon = isset($_POST['es_cupon']) && $_POST['es_cupon'] == "1" ? 1 : 0;
        $monto = $_POST['monto_dinero'] ?? 0;
        $tipo_articulo = $_POST['tipo_articulo'] ?? 'ninguno';
        $id_articulo = null;

        if ($es_cupon == 1) {
            $tipo_articulo = 'ninguno'; $id_articulo = null;
        } else {
            if ($tipo_articulo == 'producto') {
                $id_articulo = !empty($_POST['id_articulo_prod']) ? $_POST['id_articulo_prod'] : null;
            } elseif ($tipo_articulo == 'combo') {
                $id_articulo = !empty($_POST['id_articulo_combo']) ? $_POST['id_articulo_combo'] : null;
            }
        }

        $sql = "INSERT INTO premios (nombre, puntos_necesarios, stock, es_cupon, monto_dinero, activo, id_articulo, tipo_articulo) VALUES (?, ?, ?, ?, ?, 1, ?, ?)";
        $conexion->prepare($sql)->execute([$nombre, $puntos, $stock, $es_cupon, $monto, $id_articulo, $tipo_articulo]);
        header("Location: gestionar_premios.php?msg=creado"); exit;
    } catch (Exception $e) { $mensaje = '<div class="alert alert-danger">Error: '.$e->getMessage().'</div>'; }
}

// 3. LÓGICA DE EDICIÓN (NUEVO)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'edit') {
    try {
        $id = $_POST['id_premio'];
        $nombre = $_POST['nombre'];
        $puntos = $_POST['puntos'];
        $stock = $_POST['stock'];
        $monto = $_POST['monto_dinero'] ?? 0;
        
        $sql = "UPDATE premios SET nombre=?, puntos_necesarios=?, stock=?, monto_dinero=? WHERE id=?";
        $conexion->prepare($sql)->execute([$nombre, $puntos, $stock, $monto, $id]);
        header("Location: gestionar_premios.php?msg=edit"); exit;
    } catch (Exception $e) { $mensaje = '<div class="alert alert-danger">Error al editar.</div>'; }
}

// 4. LÓGICA DE BORRADO (Original)
if (isset($_GET['borrar'])) {
    $conexion->prepare("DELETE FROM premios WHERE id = ?")->execute([$_GET['borrar']]);
    header("Location: gestionar_premios.php?msg=borrado"); exit;
}

// 5. FILTROS Y LISTADO (Estandarizado)
$desde_p = $_GET['desde_p'] ?? '0';
$hasta_p = $_GET['hasta_p'] ?? '999999';

$sqlLista = "SELECT p.*, 
             CASE 
                WHEN p.tipo_articulo = 'producto' THEN (SELECT descripcion FROM productos WHERE id = p.id_articulo)
                WHEN p.tipo_articulo = 'combo' THEN (SELECT nombre FROM combos WHERE id = p.id_articulo)
                ELSE NULL 
             END as nombre_vinculo
             FROM premios p 
             WHERE p.puntos_necesarios BETWEEN ? AND ?
             ORDER BY p.puntos_necesarios ASC";
$stmtL = $conexion->prepare($sqlLista);
$stmtL->execute([$desde_p, $hasta_p]);
$lista = $stmtL->fetchAll(PDO::FETCH_ASSOC);

// CONFIGURACIÓN DE COLOR (Original)
$color_sistema = '#102A57';
try {
    $conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    $color_sistema = $conf['color_barra_nav'] ?? '#102A57';
} catch (Exception $e) { }

include 'includes/layout_header.php'; 
?>

<div class="header-blue" style="background: <?php echo $color_sistema; ?> !important; border-radius: 0 !important; width: 100vw; margin-left: calc(-50vw + 50%); padding: 40px 0; position: relative; overflow: hidden; z-index: 10;">
    <i class="bi bi-gift-fill bg-icon-large"></i>
    <div class="container position-relative">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="font-cancha mb-0 text-white">Catálogo de Premios</h2>
                <p class="opacity-75 mb-0 text-white small">Gestioná los regalos para el canje de puntos.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="canje_puntos.php" class="btn btn-outline-light rounded-pill fw-bold px-4 shadow-sm">VOLVER</a>
                <a href="reporte_premios.php?desde=<?php echo $desde_p; ?>&hasta=<?php echo $hasta_p; ?>" target="_blank" class="btn btn-danger fw-bold rounded-pill px-4 shadow-sm">
                    <i class="bi bi-file-earmark-pdf-fill me-2"></i> REPORTE PDF
                </a>
            </div>
        </div>

        <div class="bg-white bg-opacity-10 p-3 rounded-4 shadow-sm d-inline-block border border-white border-opacity-25 mt-2 mb-4">
            <form method="GET" class="d-flex align-items-center gap-3 mb-0">
                <div class="d-flex align-items-center">
                    <span class="small fw-bold text-white text-uppercase me-2">Puntos Min:</span>
                    <input type="number" name="desde_p" class="form-control border-0 shadow-sm rounded-3 fw-bold" value="<?php echo $desde_p; ?>" style="max-width: 120px;">
                </div>
                <div class="d-flex align-items-center">
                    <span class="small fw-bold text-white text-uppercase me-2">Puntos Max:</span>
                    <input type="number" name="hasta_p" class="form-control border-0 shadow-sm rounded-3 fw-bold" value="<?php echo $hasta_p; ?>" style="max-width: 120px;">
                </div>
                <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow"><i class="bi bi-search me-2"></i> FILTRAR</button>
            </form>
        </div>
    </div>
</div>

<div class="container pb-5 mt-4">
    <div class="row g-4">
        <div class="col-md-4">
            <div class="card card-custom border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-bold py-3 border-bottom-0 text-primary"><i class="bi bi-plus-circle-fill me-2"></i> Nuevo Premio</div>
                <div class="card-body bg-light rounded-bottom">
                    <?php echo $mensaje; ?>
                    <form method="POST">
                        <input type="hidden" name="agregar" value="1">
                        <div class="mb-3">
                            <label class="small fw-bold text-muted text-uppercase">Nombre</label>
                            <input type="text" name="nombre" class="form-control form-control-lg fw-bold shadow-sm" placeholder="Ej: Coca Cola" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6"><label class="small fw-bold text-muted text-uppercase">Puntos</label><input type="number" name="puntos" class="form-control" required></div>
                            <div class="col-6"><label class="small fw-bold text-muted text-uppercase">Stock</label><input type="number" name="stock" class="form-control" value="10"></div>
                        </div>
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-body p-3">
                                <label class="small fw-bold text-muted mb-2">TIPO DE PREMIO</label>
                                <div class="form-check mb-2"><input class="form-check-input" type="radio" name="tipo_premio_radio" id="radioStock" checked onchange="toggleTipoPremio()"><label class="form-check-label" for="radioStock">Mercadería (Descuenta Stock)</label></div>
                                <div id="divArticulos" class="ms-3 mb-3 border-start ps-3 border-3 border-primary">
                                    <select name="tipo_articulo" class="form-select form-select-sm mb-2" id="selectTipoArt" onchange="cargarListaArticulos()">
                                        <option value="ninguno">-- Sin vinculación --</option>
                                        <option value="producto">Producto Individual</option>
                                        <option value="combo">Combo / Pack</option>
                                    </select>
                                    <select name="id_articulo_prod" id="selProd" class="form-select form-select-sm" style="display:none;"><option value="">Seleccionar Producto...</option><?php foreach($prods_db as $p): ?><option value="<?php echo $p['id']; ?>"><?php echo $p['descripcion']; ?></option><?php endforeach; ?></select>
                                    <select name="id_articulo_combo" id="selCombo" class="form-select form-select-sm" style="display:none;"><option value="">Seleccionar Combo...</option><?php foreach($combos_db as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo $c['nombre']; ?></option><?php endforeach; ?></select>
                                </div>
                                <div class="form-check"><input class="form-check-input" type="radio" name="tipo_premio_radio" id="checkCupon" onchange="toggleTipoPremio()"><label class="form-check-label fw-bold text-success" for="checkCupon">Dinero en Cuenta ($)</label></div>
                                <div id="divMonto" style="display:none;" class="mt-2 ms-3"><div class="input-group input-group-sm"><span class="input-group-text bg-success text-white fw-bold">$</span><input type="number" step="0.01" name="monto_dinero" class="form-control text-success fw-bold" placeholder="500"></div></div>
                                <input type="hidden" name="es_cupon" id="hiddenEsCupon" value="0">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 fw-bold py-2 shadow-sm">GUARDAR</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card card-custom border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-bold py-3 border-bottom d-flex justify-content-between">
                    <span><i class="bi bi-list-check me-2 text-primary"></i> Premios Disponibles</span>
                    <span class="badge bg-light text-muted border"><?php echo count($lista); ?> items</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light small text-uppercase text-muted">
                            <tr><th class="ps-4">Premio</th><th>Vinculación</th><th>Costo Puntos</th><th class="text-end pe-4">Acciones</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach($lista as $p): $jsonData = htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8'); ?>
                            <tr style="cursor:pointer;" onclick='verDetallePremio(<?php echo $jsonData; ?>)'>
                                <td class="ps-4">
                                    <div class="fw-bold text-dark"><?php echo htmlspecialchars($p['nombre']); ?></div>
                                    <?php if($p['es_cupon']): ?><span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 small">$<?php echo number_format($p['monto_dinero'], 0); ?></span><?php endif; ?>
                                </td>
                                <td><?php if(!$p['es_cupon']): ?>
                                    <span class="badge bg-info bg-opacity-10 text-primary border border-info border-opacity-25"><?php echo $p['nombre_vinculo'] ?: 'Sin vincular'; ?></span>
                                    <div class="small text-muted mt-1">Stock: <?php echo $p['stock']; ?></div>
                                <?php else: ?>-<?php endif; ?></td>
                                <td><span class="badge bg-warning bg-opacity-20 text-dark rounded-pill px-3 py-2"><i class="bi bi-star-fill me-1"></i> <?php echo number_format($p['puntos_necesarios'], 0, ',', '.'); ?></span></td>
                                <td class="text-end pe-4">
                                    <button onclick='event.stopPropagation(); editarPremio(<?php echo $jsonData; ?>)' class="btn btn-sm btn-outline-primary border-0 rounded-circle me-1"><i class="bi bi-pencil-square"></i></button>
                                    <button onclick="event.stopPropagation(); confirmarBorrado(<?php echo $p['id']; ?>)" class="btn btn-sm btn-outline-danger border-0 rounded-circle"><i class="bi bi-trash3-fill"></i></button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleTipoPremio() {
    const esDinero = document.getElementById('checkCupon').checked;
    document.getElementById('divMonto').style.display = esDinero ? 'block' : 'none';
    document.getElementById('divArticulos').style.display = esDinero ? 'none' : 'block';
    document.getElementById('hiddenEsCupon').value = esDinero ? 1 : 0;
}
function cargarListaArticulos() {
    const tipo = document.getElementById('selectTipoArt').value;
    document.getElementById('selProd').style.display = (tipo === 'producto') ? 'block' : 'none';
    document.getElementById('selCombo').style.display = (tipo === 'combo') ? 'block' : 'none';
}

function verDetallePremio(p) {
    Swal.fire({
        title: p.nombre,
        html: `<div class="text-start">
            <p><strong>Puntos:</strong> ${p.puntos_necesarios}</p>
            <p><strong>Stock:</strong> ${p.stock}</p>
            <p><strong>Tipo:</strong> ${p.es_cupon ? 'Cupón Dinero' : 'Mercadería'}</p>
            ${p.es_cupon ? `<p><strong>Monto:</strong> $${p.monto_dinero}</p>` : `<p><strong>Vínculo:</strong> ${p.nombre_vinculo || 'Ninguno'}</p>`}
        </div>`,
        confirmButtonText: 'Cerrar', confirmButtonColor: '<?php echo $color_sistema; ?>'
    });
}

function editarPremio(p) {
    Swal.fire({
        title: 'Editar Premio',
        showCancelButton: true, confirmButtonText: 'Guardar',
        html: `
            <div class="text-start">
                <label class="small fw-bold">Nombre</label><input id="edit-nombre" class="form-control mb-3" value="${p.nombre}">
                <label class="small fw-bold">Puntos</label><input type="number" id="edit-puntos" class="form-control mb-3" value="${p.puntos_necesarios}">
                <label class="small fw-bold">Stock</label><input type="number" id="edit-stock" class="form-control mb-3" value="${p.stock}">
                ${p.es_cupon ? `<label class="small fw-bold">Monto ($)</label><input type="number" id="edit-monto" class="form-control" value="${p.monto_dinero}">` : ''}
            </div>`,
        preConfirm: () => {
            return {
                action: 'edit', id_premio: p.id,
                nombre: document.getElementById('edit-nombre').value,
                puntos: document.getElementById('edit-puntos').value,
                stock: document.getElementById('edit-stock').value,
                monto_dinero: p.es_cupon ? document.getElementById('edit-monto').value : 0
            }
        }
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form'); form.method = 'POST';
            for (const key in result.value) { const input = document.createElement('input'); input.type = 'hidden'; input.name = key; input.value = result.value[key]; form.appendChild(input); }
            document.body.appendChild(form); form.submit();
        }
    });
}

function confirmarBorrado(id) {
    Swal.fire({ title: '¿Eliminar?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Eliminar' }).then((r) => { if(r.isConfirmed) window.location.href = "gestionar_premios.php?borrar=" + id; });
}

const urlParams = new URLSearchParams(window.location.search);
if(urlParams.get('msg') === 'creado') Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Creado', timer: 2500, showConfirmButton: false });
if(urlParams.get('msg') === 'edit') Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Actualizado', timer: 2500, showConfirmButton: false });
</script>
<?php include 'includes/layout_footer.php'; ?>