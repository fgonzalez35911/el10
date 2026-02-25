<?php
// clientes.php - VERSIÓN RESTAURADA (DISEÑO TOTAL + FIX BANDERITAS)
session_start();
error_reporting(E_ALL); 
ini_set('display_errors', 1);

// 1. CONEXIÓN
$rutas_db = ['db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

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

    if (!empty($nombre) && !empty($dni) && !empty($email)) {
        try {
            $tel_val = !empty($telefono) ? $telefono : null;
            $dir_val = !empty($direccion) ? $direccion : null;
            $nac_val = !empty($fecha_nac) ? $fecha_nac : null;

            if ($id_edit) {
                $sql = "UPDATE clientes SET nombre=?, telefono=?, email=?, direccion=?, dni=?, dni_cuit=?, limite_credito=?, fecha_nacimiento=?, usuario=? WHERE id=?";
                $conexion->prepare($sql)->execute([$nombre, $tel_val, $email, $dir_val, $dni, $dni, $limite, $nac_val, $user_form, $id_edit]);
            } else {
                $sql = "INSERT INTO clientes (nombre, dni, dni_cuit, telefono, email, fecha_nacimiento, limite_credito, usuario, direccion, fecha_registro, saldo_deudor, puntos_acumulados) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 0, 0)";
                $conexion->prepare($sql)->execute([$nombre, $dni, $dni, $tel_val, $email, $nac_val, $limite, $user_form, $dir_val]);
            }
            header("Location: clientes.php?msg=ok"); exit;
        } catch (Exception $e) { die("Error Crítico DB: " . $e->getMessage()); }
    }
}

// 3. BORRADO
if (isset($_GET['borrar'])) {
    $id_b = intval($_GET['borrar']);
    try {
        $conexion->prepare("UPDATE ventas SET id_cliente = 1 WHERE id_cliente = ?")->execute([$id_b]);
        $conexion->prepare("DELETE FROM movimientos_cc WHERE id_cliente = ?")->execute([$id_b]);
        $conexion->prepare("DELETE FROM clientes WHERE id = ?")->execute([$id_b]);
        header("Location: clientes.php?msg=eliminado"); exit;
    } catch (Exception $e) { header("Location: clientes.php?error=db"); exit; }
}

// 4. CONSULTA DE DATOS (CON FILTRO DE CUMPLEAÑOS)
$where_clause = "";
if (isset($_GET['filtro']) && $_GET['filtro'] == 'cumple') {
    $where_clause = " WHERE MONTH(c.fecha_nacimiento) = MONTH(CURDATE()) AND DAY(c.fecha_nacimiento) = DAY(CURDATE()) ";
}

$sql = "SELECT c.*, 
        (SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = c.id AND tipo = 'debe') - 
        (SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = c.id AND tipo = 'haber') as saldo_calculado,
        (SELECT MAX(fecha) FROM ventas WHERE id_cliente = c.id) as ultima_venta_fecha
        FROM clientes c $where_clause ORDER BY c.nombre ASC";

// FIX: Forzamos FETCH_ASSOC para evitar el error de stdClass
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

<div class="header-blue" style="background: <?php echo $color_sistema; ?> !important; border-radius: 0 !important; width: 100vw; margin-left: calc(-50vw + 50%); padding: 40px 0; position: relative; overflow: hidden; z-index: 10;">
    <i class="bi bi-people-fill bg-icon-large" style="z-index: 0;"></i>
    <div class="container position-relative" style="z-index: 2;">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="font-cancha mb-0 text-white">Cartera de Clientes</h2>
                <p class="opacity-75 mb-0 text-white small">Gestión de cuentas y fidelización</p>
            </div>
            <button type="button" class="btn btn-light text-primary fw-bold rounded-pill px-4 shadow-sm" onclick="abrirModalCrear()">
                <i class="bi bi-person-plus-fill me-2"></i> NUEVO CLIENTE
            </button>
        </div>
        <div class="row g-3">
            <div class="col-12 col-md-4">
                <div class="header-widget">
                    <div><div class="widget-label">Total Clientes</div><div class="widget-value text-white"><?php echo $totalClientes; ?></div></div>
                    <div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-people"></i></div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="header-widget">
                    <div><div class="widget-label">Deuda en Calle</div><div class="widget-value text-white">$<?php echo number_format($totalDeudaCalle, 0, ',', '.'); ?></div></div>
                    <div class="icon-box bg-danger bg-opacity-20 text-white"><i class="bi bi-graph-down-arrow"></i></div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="header-widget">
                    <div><div class="widget-label">Clientes al Día</div><div class="widget-value text-white"><?php echo $cntAlDia; ?></div></div>
                    <div class="icon-box bg-success bg-opacity-20 text-white"><i class="bi bi-shield-check"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5 mt-4">
    <div class="card card-custom shadow-sm border-0 rounded-4 overflow-hidden">
        <div class="card-header bg-white py-3 border-0">
            <input type="text" id="buscador" class="form-control bg-light rounded-pill px-4" placeholder="Buscar por nombre o DNI..." onkeyup="filtrarClientes()">
        </div>
        <div class="table-responsive px-2">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light small text-uppercase text-muted">
                    <tr>
                        <th class="ps-4">Cliente</th>
                        <th>DNI / Usuario</th>
                        <th>Puntos</th>
                        <th>Estado de Cuenta</th>
                        <th>Última Compra</th>
                        <th class="text-end pe-4">Acciones</th>
                    </tr>
                </thead>
                <tbody class="bg-white">
            <?php foreach($clientes_query as $c): 
                $deuda = floatval($c['saldo_calculado']);
                $limite = floatval($c['limite_credito']);
            ?>
            <tr class="cliente-row">
                <td class="ps-4">
                    <div class="d-flex align-items-center">
                        <div class="avatar-circle me-3 bg-primary bg-opacity-10 text-primary" style="width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold;"><?php echo strtoupper(substr($c['nombre'], 0, 2)); ?></div>
                        <div><div class="fw-bold text-dark"><?php echo htmlspecialchars($c['nombre']); ?></div><small class="text-muted"><?php echo $c['telefono']; ?></small></div>
                    </div>
                </td>
                <td>
                    <div class="small fw-bold text-dark"><?php echo $c['dni'] ?: '--'; ?></div>
                    <span class="badge bg-light text-primary border">@<?php echo $c['usuario'] ?: 'sin_usuario'; ?></span>
                </td>
                <td><span class="badge bg-warning bg-opacity-10 text-dark fw-bold"><i class="bi bi-star-fill text-warning me-1"></i> <?php echo number_format($c['puntos_acumulados'], 0); ?></span></td>
                <td>
                    <div class="small">
                        <div class="<?php echo $deuda > 0 ? 'text-danger fw-bold' : 'text-success'; ?>">Debe: $<?php echo number_format($deuda, 0, ',', '.'); ?></div>
                        <div class="text-muted" style="font-size: 0.7rem;">Límite Fiado: $<?php echo number_format($limite, 0, ',', '.'); ?></div>
                    </div>
                </td>
                <td class="small text-muted"><?php echo $c['ultima_venta_fecha'] ? date('d/m/y', strtotime($c['ultima_venta_fecha'])) : 'Nunca'; ?></td>
                <td class="text-end pe-4">
                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-sm btn-light text-info rounded-circle" onclick="verResumen(<?php echo $c['id']; ?>)"><i class="bi bi-eye"></i></button>
                        <a href="cuenta_cliente.php?id=<?php echo $c['id']; ?>" class="btn btn-sm btn-light text-primary rounded-circle"><i class="bi bi-wallet2"></i></a>
                        <button type="button" class="btn btn-sm btn-light text-warning rounded-circle" onclick="editar(<?php echo $c['id']; ?>)"><i class="bi bi-pencil"></i></button>
                        <button type="button" class="btn btn-sm btn-light text-danger rounded-circle" onclick="borrarCliente(<?php echo $c['id']; ?>)"><i class="bi bi-trash"></i></button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalGestionCliente" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow-lg rounded-4">
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

<div class="modal fade" id="modalResumen" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow-lg rounded-4">
    <div class="modal-header bg-primary text-white border-0 py-3"><h5 class="modal-title fw-bold">Resumen de Cuenta</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body text-center p-4">
        <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width:80px;height:80px;font-size:2rem;font-weight:bold;color:#102A57;" id="modal-avatar-res"></div>
        <h4 class="fw-bold mb-0" id="modal-nombre"></h4><p class="text-muted" id="modal-dni"></p><hr>
        <div class="row g-3">
            <div class="col-6"><div class="p-3 bg-light rounded"><div class="small text-muted">Deuda</div><div class="h5 fw-bold text-danger mb-0" id="modal-deuda"></div></div></div>
            <div class="col-6"><div class="p-3 bg-light rounded"><div class="small text-muted">Puntos</div><div class="h5 fw-bold text-warning mb-0" id="modal-puntos"></div></div></div>
        </div>
        <div class="d-grid mt-4"><a href="#" id="btn-ir-cuenta" class="btn btn-primary fw-bold shadow py-2 rounded-pill">VER CUENTA CORRIENTE</a></div>
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
        var d = clientesDB[id]; $('#modal-nombre').text(d.nombre); $('#modal-dni').text('DNI: ' + d.dni); $('#modal-avatar-res').text(d.nombre.substring(0,2).toUpperCase()); $('#modal-deuda').text('$' + Number(d.deuda).toLocaleString('es-AR')); $('#modal-puntos').text(d.puntos); $('#btn-ir-cuenta').attr('href', 'cuenta_cliente.php?id=' + d.id); $('#modalResumen').modal('show');
    }
    $('#formCliente').submit(function(){ $('#telefono_final').val(phoneInput.getNumber()); });
    function filtrarClientes() { var val = $('#buscador').val().toUpperCase(); $('.cliente-row').each(function(){ $(this).toggle($(this).text().toUpperCase().indexOf(val) > -1); }); }
    function borrarCliente(id) { Swal.fire({ title: '¿Eliminar?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí' }).then((r) => { if (r.isConfirmed) window.location.href = 'clientes.php?borrar=' + id; }); }

    document.getElementById('nombre').addEventListener('input', function(e) {
        if($('#id_edit').val() === '') {
            var parts = e.target.value.trim().toLowerCase().split(' ');
            if(parts.length >= 2) $('#usuario_form').val((parts[0].charAt(0) + parts[parts.length - 1]).replace(/[^a-z0-9]/g, ""));
        }
    });
</script>
<?php include 'includes/layout_footer.php'; ?>