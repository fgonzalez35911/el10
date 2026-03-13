<?php
// bienes_uso.php - VERSIÓN VANGUARD PRO TOTAL (RESTAURADA Y SIN RECORTES)
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (file_exists('db.php')) { require_once 'db.php'; } 
elseif (file_exists('includes/db.php')) { require_once 'includes/db.php'; } 
else { die("Error crítico: No se encuentra db.php"); }

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// --- CANDADOS DE SEGURIDAD ---
$permisos = $_SESSION['permisos'] ?? [];
$es_admin = (($_SESSION['rol'] ?? 3) <= 2);
if (!$es_admin && !in_array('ver_activos', $permisos)) { header("Location: dashboard.php"); exit; }

// --- LÓGICA DE ELIMINACIÓN ---
if (isset($_GET['borrar'])) {
    if (!$es_admin && !in_array('eliminar_activo', $permisos)) die("Sin permiso para eliminar.");
    try {
        $id = $_GET['borrar'];
        $stmtFoto = $conexion->prepare("SELECT foto FROM bienes_uso WHERE id = ?");
        $stmtFoto->execute([$id]);
        $foto = $stmtFoto->fetchColumn();
        if($foto && file_exists($foto)) { unlink($foto); }
        $conexion->prepare("DELETE FROM bienes_uso WHERE id = ?")->execute([$id]);
        header("Location: bienes_uso.php?msg=eliminado"); exit;
    } catch (Exception $e) { }
}

// --- LÓGICA DE GUARDADO (POST) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['nombre'])) {
    $id_edit = $_POST['id_edit'] ?? '';
    try {
        $nombre = trim($_POST['nombre']);
        $marca = trim($_POST['marca']);
        $modelo = trim($_POST['modelo']);
        $serie = trim($_POST['serie']);
        $estado = $_POST['estado']; // "Nuevo", "Bueno", "Regular", "Reparar", "Malo"
        $ubicacion = trim($_POST['ubicacion']);
        $fecha = !empty($_POST['fecha']) ? $_POST['fecha'] : NULL;
        $costo = !empty($_POST['costo']) ? $_POST['costo'] : 0;
        $notas = trim($_POST['notas']);

        // Procesar Foto si hay
        $ruta_foto = ''; 
        if (!empty($_FILES['foto']['name'])) {
            $dir = 'uploads/activos/';
            if (!is_dir($dir)) mkdir($dir, 0777, true);
            $ext = pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION);
            $nombre_archivo = uniqid('activo_') . '.' . $ext;
            $ruta_dest = $dir . $nombre_archivo;
            if (move_uploaded_file($_FILES['foto']['tmp_name'], $ruta_dest)) { $ruta_foto = $ruta_dest; }
        }

        if (!empty($id_edit)) {
            $sql = "UPDATE bienes_uso SET nombre=?, marca=?, modelo=?, numero_serie=?, estado=?, ubicacion=?, fecha_compra=?, costo_compra=?, notas=?";
            $params = [$nombre, $marca, $modelo, $serie, $estado, $ubicacion, $fecha, $costo, $notas];
            if ($ruta_foto != '') { $sql .= ", foto=?"; $params[] = $ruta_foto; }
            $sql .= " WHERE id=?"; $params[] = $id_edit;
            $conexion->prepare($sql)->execute($params);
            $res = 'actualizado';
        } else {
            $sql = "INSERT INTO bienes_uso (nombre, marca, modelo, numero_serie, estado, ubicacion, fecha_compra, costo_compra, notas, foto) VALUES (?,?,?,?,?,?,?,?,?,?)";
            $conexion->prepare($sql)->execute([$nombre, $marca, $modelo, $serie, $estado, $ubicacion, $fecha, $costo, $notas, $ruta_foto]);
            $res = 'creado';
        }
        header("Location: bienes_uso.php?msg=$res"); exit;
    } catch (Exception $e) { die("Error: " . $e->getMessage()); }
}

// --- CONSULTA Y FILTROS ---
$desde = $_GET['desde'] ?? date('Y-01-01', strtotime('-5 years'));
$hasta = $_GET['hasta'] ?? date('Y-12-31');
$buscar = trim($_GET['buscar'] ?? '');
$cond = ["((DATE(fecha_compra) >= ? AND DATE(fecha_compra) <= ?) OR fecha_compra IS NULL)"];
$params = [$desde, $hasta];
if(!empty($buscar)) {
    $cond[] = "(nombre LIKE ? OR marca LIKE ? OR numero_serie LIKE ? OR ubicacion LIKE ?)";
    array_push($params, "%$buscar%", "%$buscar%", "%$buscar%", "%$buscar%");
}
$stmtActivos = $conexion->prepare("SELECT * FROM bienes_uso WHERE " . implode(" AND ", $cond) . " ORDER BY id DESC");
$stmtActivos->execute($params);
$activos = $stmtActivos->fetchAll(PDO::FETCH_ASSOC);

// Normalización de Estados para el JavaScript
$total_activos = count($activos);
$valor_total = 0; $reparar_cnt = 0;
foreach($activos as &$a) {
    $valor_total += (float)($a['costo_compra'] ?? 0);
    $est_norm = ucfirst(strtolower(trim((string)($a['estado'] ?? 'Bueno'))));
    $a['estado'] = $est_norm; // Sincronizamos
    if($est_norm == 'Reparar') $reparar_cnt++;
}
unset($a);

include 'includes/layout_header.php'; 

// Banner Dinámico
$titulo = "Mis Activos";
$subtitulo = "Inventario y control de hardware.";
$icono_bg = "bi-pc-display-horizontal";
$botones = [
    ['texto' => 'REPORTE PDF', 'link' => "reporte_bienes.php?".$_SERVER['QUERY_STRING'], 'icono' => 'bi-file-earmark-pdf-fill', 'class' => 'btn btn-danger fw-bold rounded-pill px-4 shadow-sm', 'target' => '_blank'],
    ['texto' => 'NUEVO ACTIVO', 'link' => 'javascript:void(0)', 'icono' => 'bi-plus-lg', 'class' => 'btn btn-light text-primary fw-bold rounded-pill px-4 shadow-sm ms-2', 'onClick' => 'abrirModalCrear()']
];
$widgets = [
    ['label' => 'Equipos', 'valor' => $total_activos, 'icono' => 'bi-archive', 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'Inversión', 'valor' => '$'.number_format($valor_total, 0, ',', '.'), 'icono' => 'bi-currency-dollar', 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'A Reparar', 'valor' => $reparar_cnt, 'icono' => 'bi-tools', 'border' => 'border-danger', 'icon_bg' => 'bg-danger bg-opacity-20']
];
include 'includes/componente_banner.php'; 
?>

<div class="container-fluid container-md mt-n4 px-3" style="position: relative; z-index: 20;">

    <div class="card border-0 shadow-sm rounded-4 mb-3 bg-warning text-dark overflow-hidden" style="border-left: 5px solid #ff9800 !important;">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-center mb-0">
                <input type="hidden" name="desde" value="<?php echo $desde; ?>">
                <input type="hidden" name="hasta" value="<?php echo $hasta; ?>">
                <div class="col-md-8 fw-bold text-uppercase small"><i class="bi bi-search me-2"></i>Buscador de Equipos</div>
                <div class="col-md-4">
                    <div class="input-group input-group-sm">
                        <input type="text" name="buscar" class="form-control border-0 fw-bold shadow-none" placeholder="Buscar por nombre o serie..." value="<?php echo htmlspecialchars($buscar); ?>">
                        <button class="btn btn-dark px-3" type="submit"><i class="bi bi-arrow-right"></i></button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-3">
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-end w-100">
                <input type="hidden" name="buscar" value="<?php echo htmlspecialchars($buscar); ?>">
                <div class="flex-grow-1">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Compra Desde</label>
                    <input type="date" name="desde" class="form-control form-control-sm border-light-subtle fw-bold" value="<?php echo $desde; ?>">
                </div>
                <div class="flex-grow-1">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Compra Hasta</label>
                    <input type="date" name="hasta" class="form-control form-control-sm border-light-subtle fw-bold" value="<?php echo $hasta; ?>">
                </div>
                <div class="flex-grow-0 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm fw-bold px-3 rounded-3 shadow-sm" style="height:31px;">FILTRAR</button>
                    <a href="bienes_uso.php" class="btn btn-light btn-sm fw-bold border rounded-3 px-3" style="height:31px; display:flex; align-items:center;">LIMPIAR</a>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-4" id="gridActivos">
        <?php foreach ($activos as $a): 
            $estadoClass = 'bg-secondary';
            if ($a['estado'] == 'Nuevo') $estadoClass = 'bg-success';
            if ($a['estado'] == 'Bueno') $estadoClass = 'bg-primary';
            if ($a['estado'] == 'Regular') $estadoClass = 'bg-warning text-dark';
            if ($a['estado'] == 'Reparar') $estadoClass = 'bg-danger';
            if ($a['estado'] == 'Malo') $estadoClass = 'bg-dark';
            
            $img = (!empty($a['foto']) && file_exists($a['foto'])) ? $a['foto'] : 'img/no-image.png';
            $jsonItem = htmlspecialchars(json_encode($a), ENT_QUOTES, 'UTF-8');
        ?>
        <div class="col-12 col-sm-6 col-md-4 col-lg-3 item-activo" data-estado="<?php echo $a['estado']; ?>">
            <div class="card h-100 shadow-sm border-0 rounded-4 overflow-hidden position-relative">
                <div class="img-zone" style="height: 160px; background: #f8f9fa; position:relative; overflow:hidden; cursor:pointer;" onclick='verDetalle(<?php echo $jsonItem; ?>)'>
                    <img src="<?php echo $img; ?>" style="width: 100%; height: 100%; object-fit: cover;">
                    <span class="badge position-absolute top-0 end-0 m-2 <?php echo $estadoClass; ?> shadow-sm"><?php echo $a['estado']; ?></span>
                </div>
                <div class="card-body p-3 bg-white" onclick='verDetalle(<?php echo $jsonItem; ?>)' style="cursor:pointer;">
                    <h6 class="fw-bold mb-1 text-truncate"><?php echo $a['nombre']; ?></h6>
                    <small class="text-muted d-block mb-2"><?php echo $a['marca']; ?> <?php echo $a['modelo']; ?></small>
                    <div class="d-flex justify-content-between align-items-center mt-3">
                         <div class="small text-muted text-truncate w-50"><i class="bi bi-geo-alt-fill text-primary"></i> <?php echo $a['ubicacion'] ?: '-'; ?></div>
                         <div class="fw-bold text-success small">$<?php echo number_format($a['costo_compra'], 0, ',', '.'); ?></div>
                    </div>
                </div>
                <div class="card-footer bg-light border-0 p-2 d-flex justify-content-end gap-2">
                    <button class="btn btn-sm btn-outline-primary rounded-circle" onclick='editar(<?php echo $jsonItem; ?>)'><i class="bi bi-pencil-fill"></i></button>
                    <button class="btn btn-sm btn-outline-danger rounded-circle" onclick="confirmarBorrar(<?php echo $a['id']; ?>, '<?php echo addslashes($a['nombre']); ?>')"><i class="bi bi-trash3-fill"></i></button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="modal fade" id="modalForm" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-primary text-white"><h5 class="modal-title fw-bold" id="modalTitle">Nuevo Activo</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <form action="bienes_uso.php" method="POST" enctype="multipart/form-data">
                <div class="modal-body p-4 row g-3">
                    <input type="hidden" name="id_edit" id="id_edit">
                    <div class="col-md-6"><label class="small fw-bold text-muted">Nombre del Bien</label><input type="text" name="nombre" id="nombre" class="form-control fw-bold" required></div>
                    <div class="col-md-3"><label class="small fw-bold text-muted">Marca</label><input type="text" name="marca" id="marca" class="form-control"></div>
                    <div class="col-md-3"><label class="small fw-bold text-muted">Modelo</label><input type="text" name="modelo" id="modelo" class="form-control"></div>
                    <div class="col-md-4">
                        <label class="small fw-bold text-muted">Estado</label>
                        <select name="estado" id="estado" class="form-select" required>
                            <option value="Nuevo">Nuevo</option>
                            <option value="Bueno">Bueno</option>
                            <option value="Regular">Regular</option>
                            <option value="Reparar">Reparar</option>
                            <option value="Malo">Malo</option>
                        </select>
                    </div>
                    <div class="col-md-4"><label class="small fw-bold text-muted">Ubicación</label><input type="text" name="ubicacion" id="ubicacion" class="form-control"></div>
                    <div class="col-md-4"><label class="small fw-bold text-muted">Nro Serie</label><input type="text" name="serie" id="serie" class="form-control"></div>
                    <div class="col-md-6"><label class="small fw-bold text-muted">Fecha Compra</label><input type="date" name="fecha" id="fecha" class="form-control"></div>
                    <div class="col-md-6"><label class="small fw-bold text-muted">Costo Inversión ($)</label><input type="number" step="0.01" name="costo" id="costo" class="form-control"></div>
                    <div class="col-12"><label class="small fw-bold text-muted">Foto del Activo</label><input type="file" name="foto" class="form-control" accept="image/*"></div>
                    <div class="col-12"><label class="small fw-bold text-muted">Notas Adicionales</label><textarea name="notas" id="notas" class="form-control" rows="2"></textarea></div>
                </div>
                <div class="modal-footer bg-light border-0"><button type="submit" class="btn btn-primary fw-bold px-4 rounded-pill">GUARDAR DATOS</button></div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalDetalle" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow rounded-4 overflow-hidden">
        <div class="modal-body p-0 position-relative">
            <button type="button" class="btn-close position-absolute top-0 end-0 m-3 bg-white p-2 rounded-circle" data-bs-dismiss="modal" style="z-index:10;"></button>
            <div id="view-img-container" style="height:250px; background:#f8f9fa;"></div>
            <div class="p-4">
                <h4 class="fw-bold mb-0 text-primary" id="view-nombre"></h4>
                <span class="text-muted small fw-bold" id="view-marca"></span>
                <hr>
                <div class="row g-3 small">
                    <div class="col-6"><span class="text-muted d-block text-uppercase" style="font-size:7pt;">Estado</span> <strong id="view-estado" class="fs-6"></strong></div>
                    <div class="col-6"><span class="text-muted d-block text-uppercase" style="font-size:7pt;">Inversión</span> <strong id="view-costo" class="text-success fs-6"></strong></div>
                    <div class="col-6"><span class="text-muted d-block text-uppercase" style="font-size:7pt;">Ubicación</span> <strong id="view-ubicacion"></strong></div>
                    <div class="col-6"><span class="text-muted d-block text-uppercase" style="font-size:7pt;">S/N</span> <strong id="view-serie" class="font-monospace"></strong></div>
                    <div class="col-12 p-3 bg-light rounded mt-2"><span class="text-muted fw-bold d-block mb-1">Notas:</span> <span id="view-notas" class="fst-italic text-secondary"></span></div>
                </div>
            </div>
        </div>
    </div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    let modalForm, modalDetalle;
    document.addEventListener('DOMContentLoaded', function() {
        modalForm = new bootstrap.Modal(document.getElementById('modalForm'));
        modalDetalle = new bootstrap.Modal(document.getElementById('modalDetalle'));
        
        const msg = new URLSearchParams(window.location.search).get('msg');
        if(msg === 'actualizado') Swal.fire({ icon: 'success', title: '¡Listo!', text: 'Cambios guardados con éxito.', confirmButtonColor: '#102A57' });
        if(msg === 'creado') Swal.fire({ icon: 'success', title: '¡Excelente!', text: 'El activo se registró correctamente.', confirmButtonColor: '#102A57' });
        if(msg === 'eliminado') Swal.fire({ icon: 'success', title: 'Eliminado', text: 'El bien fue removido del sistema.', confirmButtonColor: '#102A57' });
    });

    function abrirModalCrear() { 
        document.querySelector('#modalForm form').reset(); 
        document.getElementById('id_edit').value = ''; 
        document.getElementById('modalTitle').innerText = 'Nuevo Activo'; 
        modalForm.show(); 
    }

    function editar(item) {
        document.querySelector('#modalForm form').reset();
        document.getElementById('id_edit').value = item.id;
        document.getElementById('nombre').value = item.nombre;
        document.getElementById('marca').value = item.marca || '';
        document.getElementById('modelo').value = item.modelo || '';
        document.getElementById('serie').value = item.numero_serie || '';
        document.getElementById('ubicacion').value = item.ubicacion || '';
        document.getElementById('fecha').value = item.fecha_compra || '';
        document.getElementById('costo').value = item.costo_compra || '';
        document.getElementById('notas').value = item.notas || '';
        
        // SINCRONIZACIÓN FORZADA DEL SELECT (ESTO ARREGLA EL ERROR)
        document.getElementById('estado').value = item.estado;
        
        document.getElementById('modalTitle').innerText = 'Editar: ' + item.nombre;
        modalForm.show();
    }

    function verDetalle(item) {
        document.getElementById('view-nombre').innerText = item.nombre;
        document.getElementById('view-marca').innerText = (item.marca || '') + ' ' + (item.modelo || '');
        document.getElementById('view-estado').innerText = item.estado;
        document.getElementById('view-ubicacion').innerText = item.ubicacion || '-';
        document.getElementById('view-serie').innerText = item.numero_serie || 'S/N';
        document.getElementById('view-costo').innerText = '$' + new Intl.NumberFormat('es-AR').format(item.costo_compra);
        document.getElementById('view-notas').innerText = item.notas || 'Sin notas adicionales.';
        const container = document.getElementById('view-img-container');
        container.innerHTML = item.foto ? `<img src="${item.foto}" style="width:100%; height:100%; object-fit:cover;">` : `<div class="d-flex h-100 align-items-center justify-content-center opacity-25"><i class="bi bi-pc-display-horizontal fs-1"></i></div>`;
        modalDetalle.show();
    }

    function confirmarBorrar(id, nombre) {
        Swal.fire({ title: '¿Borrar ' + nombre + '?', text: "Esta acción no se puede deshacer.", icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33', confirmButtonText: 'Sí, borrar', cancelButtonText: 'Cancelar' })
        .then((result) => { if (result.isConfirmed) { window.location.href = 'bienes_uso.php?borrar=' + id; } })
    }

    document.querySelector('#modalForm form').addEventListener('submit', function() {
        Swal.fire({ title: 'Guardando...', text: 'Actualizando inventario', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
    });
</script>
<?php include 'includes/layout_footer.php'; ?>