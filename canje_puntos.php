<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

$rutas_db = ['db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

$permisos = $_SESSION['permisos'] ?? [];
$es_admin = (($_SESSION['rol'] ?? 3) <= 2);

if (!$es_admin && !in_array('ver_canje_puntos', $permisos)) { 
    header("Location: dashboard.php"); exit; 
}

$stmtCaja = $conexion->query("SELECT id FROM cajas_sesion WHERE estado = 'abierta' ORDER BY id DESC LIMIT 1");
$caja = $stmtCaja->fetch(PDO::FETCH_ASSOC);
$id_caja_sesion = $caja ? $caja['id'] : 0;

$mensaje_sweet = '';
$resultados_busqueda = [];
$cliente_seleccionado = null;

$stmtW1 = $conexion->query("SELECT COUNT(*) FROM premios WHERE activo = 1");
$totalPremios = $stmtW1->fetchColumn();

$stmtW2 = $conexion->query("SELECT COUNT(*) FROM auditoria WHERE accion = 'CANJE' AND DATE(fecha) = CURDATE()");
$canjesHoy = $stmtW2->fetchColumn();

$stmtW3 = $conexion->query("SELECT SUM(puntos_acumulados) FROM clientes");
$puntosTotales = $stmtW3->fetchColumn() ?: 0;

$topClientes = $conexion->query("SELECT * FROM clientes WHERE puntos_acumulados > 0 ORDER BY puntos_acumulados DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

// OBTENER CONFIGURACIÓN ACTUAL
$conf = $conexion->query("SELECT dinero_por_punto FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$ratio_actual = $conf['dinero_por_punto'] ?? 100;

// FILTROS DE VISTA
$stmtMin = $conexion->query("SELECT MIN(puntos_necesarios) FROM premios WHERE activo = 1");
$min_puntos_canje = $stmtMin->fetchColumn() ?: 0;

$f_puntos = $_GET['rango_puntos'] ?? '';
$cond_ranking = " WHERE puntos_acumulados > 0 ";
$params_ranking = [];

if ($f_puntos == 'canjeables') {
    $cond_ranking .= " AND puntos_acumulados >= $min_puntos_canje ";
} elseif ($f_puntos == 'vip') {
    $cond_ranking .= " AND puntos_acumulados >= 2000 "; // Ejemplo: Clientes con más de 2000 pts
}

$topClientes = $conexion->query("SELECT * FROM clientes $cond_ranking ORDER BY puntos_acumulados DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

// 2. LÓGICA DE BÚSQUEDA

if (isset($_GET['q']) && !empty($_GET['q'])) {
    $q = trim($_GET['q']);
    $term = "%$q%";
    $sql = "SELECT * FROM clientes WHERE nombre LIKE ? OR dni LIKE ? OR dni_cuit LIKE ? OR id = ? LIMIT 20";
    $stmt = $conexion->prepare($sql);
    $stmt->execute([$term, $term, $term, $q]);
    $resultados_busqueda = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (isset($_GET['id_cliente'])) {
    $stmt = $conexion->prepare("SELECT * FROM clientes WHERE id = ?");
    $stmt->execute([$_GET['id_cliente']]);
    $cliente_seleccionado = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (isset($_POST['canjear']) && $cliente_seleccionado) {
    if (!$es_admin && !in_array('crear_canje', $permisos)) die("Sin permiso.");
    $id_cliente = $_POST['id_cliente'];
    $id_premio = $_POST['id_premio'];
    
    try {
        $conexion->beginTransaction();
        
        $stmtC = $conexion->prepare("SELECT puntos_acumulados FROM clientes WHERE id = ?");
        $stmtC->execute([$id_cliente]);
        $pts_actuales = $stmtC->fetchColumn();
        
        $stmtP = $conexion->prepare("SELECT * FROM premios WHERE id = ?");
        $stmtP->execute([$id_premio]);
        $premio = $stmtP->fetch(PDO::FETCH_ASSOC);
        
        if ($pts_actuales >= $premio['puntos_necesarios']) {
            $nuevo_saldo = $pts_actuales - $premio['puntos_necesarios'];
            $conexion->prepare("UPDATE clientes SET puntos_acumulados = ? WHERE id = ?")->execute([$nuevo_saldo, $id_cliente]);
            
            $txt_log = "";
            $detalle_receta = ""; 

            if ($premio['es_cupon'] == 1) {
                $monto = $premio['monto_dinero'];
                $conexion->prepare("UPDATE clientes SET saldo_favor = saldo_favor + ? WHERE id = ?")->execute([$monto, $id_cliente]);
                $txt_log = "Canje Cupón $$monto";
            } else {
                $costo_gasto = 0; 
                
                if ($premio['tipo_articulo'] == 'producto' && !empty($premio['id_articulo'])) {
                    $conexion->prepare("UPDATE productos SET stock_actual = stock_actual - 1 WHERE id = ?")->execute([$premio['id_articulo']]);
                    $stmtProd = $conexion->prepare("SELECT precio_costo, descripcion FROM productos WHERE id = ?");
                    $stmtProd->execute([$premio['id_articulo']]);
                    $prodData = $stmtProd->fetch(PDO::FETCH_ASSOC);
                    $costo_gasto = $prodData['precio_costo'];
                    $detalle_receta = " (Producto: " . $prodData['descripcion'] . ")";
                } 
                elseif ($premio['tipo_articulo'] == 'combo' && !empty($premio['id_articulo'])) {
                    $stmtItems = $conexion->prepare("SELECT ci.id_producto, ci.cantidad, p.precio_costo, p.descripcion FROM combo_items ci JOIN productos p ON ci.id_producto = p.id WHERE ci.id_combo = ?");
                    $stmtItems->execute([$premio['id_articulo']]);
                    $items_combo = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
                    $detalle_receta = " (Incluye: ";
                    foreach($items_combo as $item) {
                        $conexion->prepare("UPDATE productos SET stock_actual = stock_actual - ? WHERE id = ?")->execute([$item['cantidad'], $item['id_producto']]);
                        $costo_gasto += ($item['precio_costo'] * $item['cantidad']);
                        $detalle_receta .= $item['descripcion'] . " x" . floatval($item['cantidad']) . ", ";
                    }
                    $detalle_receta = rtrim($detalle_receta, ", ") . ")";
                }

                $txt_log = "Canje Producto: " . $premio['nombre'];

                if ($costo_gasto > 0 && $id_caja_sesion > 0) {
                    $desc_gasto = "Costo Canje Fidelización: " . $premio['nombre'] . $detalle_receta . " | Cliente: " . $cliente_seleccionado['nombre'];
                    $conexion->prepare("INSERT INTO gastos (descripcion, monto, categoria, fecha, id_usuario, id_caja_sesion) VALUES (?, ?, 'Fidelizacion', NOW(), ?, ?)")->execute([$desc_gasto, $costo_gasto, $_SESSION['usuario_id'], $id_caja_sesion]);
                }
                $conexion->prepare("UPDATE premios SET stock = stock - 1 WHERE id = ?")->execute([$id_premio]);
            }
            
            $detalle_audit = $txt_log . $detalle_receta . " (-" . $premio['puntos_necesarios'] . " pts)";
            $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'CANJE', ?, NOW())")->execute([$_SESSION['usuario_id'], $detalle_audit]);
            
            $conexion->commit();
            header("Location: canje_puntos.php?id_cliente=$id_cliente&exito=1");
            exit;
        } else { throw new Exception("Puntos insuficientes."); }
    } catch (Exception $e) {
        if($conexion->inTransaction()) $conexion->rollBack();
        $mensaje_sweet = "Swal.fire('Error', '".$e->getMessage()."', 'error');";
    }
}

$premios = $conexion->query("SELECT * FROM premios WHERE activo = 1 ORDER BY puntos_necesarios ASC")->fetchAll(PDO::FETCH_ASSOC);

$titulo = "Centro de Fidelización";
$subtitulo = "Gestioná los puntos y recompensas de tus clientes.";
$icono_bg = "bi-gift-fill";
$botones = [];
if ($cliente_seleccionado) {
    $botones[] = ['texto' => 'VOLVER', 'link' => "canje_puntos.php", 'icono' => 'bi-arrow-left', 'class' => 'btn btn-outline-light fw-bold rounded-pill px-4'];
}
$widgets = [
    ['label' => 'Premios Activos', 'valor' => $totalPremios, 'icono' => 'bi-award', 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'Canjes Hoy', 'valor' => $canjesHoy, 'icono' => 'bi-check-circle', 'border' => 'border-success', 'icon_bg' => 'bg-success bg-opacity-20'],
    ['label' => 'Valor Punto', 'valor' => '$'.number_format($ratio_actual, 2), 'icono' => 'bi-currency-dollar', 'border' => 'border-info', 'icon_bg' => 'bg-info bg-opacity-20']
];

include 'includes/layout_header.php';
include 'includes/componente_banner.php'; 
?>

<style>
    @media (max-width: 768px) {
        .tabla-movil-ajustada td, .tabla-movil-ajustada th { padding: 0.5rem 0.3rem !important; font-size: 0.75rem !important; }
        .tabla-movil-ajustada .fw-bold { font-size: 0.8rem !important; }
        .prize-card { margin-bottom: 10px; }
    }
    .prize-card { transition: all 0.3s ease; border-radius: 20px; border: 2px solid #eee; }
    .prize-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); border-color: #102A57; }
    .grayscale { filter: grayscale(1); }
    .client-card-header { background: #102A57; color: white; padding: 60px 20px; border-radius: 20px 20px 0 0; }
</style>

<div class="container pb-5 mt-n4" style="position: relative; z-index: 20;">

    <?php if (!$cliente_seleccionado): ?>
        <div class="card border-0 shadow-sm rounded-4 mb-3 bg-warning text-dark overflow-hidden" style="border: none !important; border-left: 5px solid #ff9800 !important;">
            <div class="card-body p-2 p-md-3">
                <form method="GET" class="row g-2 align-items-center mb-0">
                    <input type="hidden" name="rango_puntos" value="<?php echo htmlspecialchars($f_puntos); ?>">
                    <div class="col-md-8 col-12 text-center text-md-start">
                        <h6 class="fw-bold mb-1 text-uppercase"><i class="bi bi-search me-2"></i>Localizar Cliente</h6>
                        <p class="small mb-0 opacity-75 d-none d-md-block">Buscá por nombre o DNI para iniciar un canje de puntos.</p>
                    </div>
                    <div class="col-md-4 col-12 text-end mt-2 mt-md-0">
                        <div class="input-group input-group-sm">
                            <input type="text" name="q" class="form-control border-0 fw-bold shadow-none" placeholder="Nombre o DNI..." value="<?php echo htmlspecialchars($_GET['q'] ?? ''); ?>">
                            <button class="btn btn-dark px-3 shadow-none border-0" type="submit" style="border: none !important;"><i class="bi bi-arrow-right-circle-fill"></i></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-2 p-md-3">
                <form method="GET" class="d-flex flex-wrap gap-2 align-items-end w-100">
                    <input type="hidden" name="q" value="<?php echo htmlspecialchars($_GET['q'] ?? ''); ?>">
                    <div class="flex-grow-1" style="min-width: 180px;">
                        <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Nivel de Puntos</label>
                        <select name="rango_puntos" class="form-select form-select-sm border-light-subtle fw-bold">
                            <option value="">Todos con puntos</option>
                            <option value="canjeables" <?php echo ($f_puntos == 'canjeables') ? 'selected' : ''; ?>>Próximos a canjear (>= <?php echo $min_puntos_canje; ?> pts)</option>
                            <option value="vip" <?php echo ($f_puntos == 'vip') ? 'selected' : ''; ?>>Clientes VIP (>2000 pts)</option>
                        </select>
                    </div>
                    <div class="flex-grow-0 d-flex gap-2 mt-2 mt-md-0">
                        <button type="submit" class="btn btn-primary btn-sm fw-bold rounded-3 shadow-sm px-3" style="height: 31px;">
                            <i class="bi bi-funnel-fill me-1"></i> FILTRAR
                        </button>
                        <a href="canje_puntos.php" class="btn btn-light btn-sm fw-bold rounded-3 border px-3" style="height: 31px; display: flex; align-items: center;">
                            <i class="bi bi-trash3-fill me-1"></i> LIMPIAR
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="alert py-2 small mb-4 text-center fw-bold border-0 shadow-sm rounded-3" style="background-color: #e9f2ff; color: #102A57;">
            <i class="bi bi-info-circle-fill me-1"></i> Seleccioná un cliente del ranking o usá el buscador para ver sus premios
        </div>

        <div class="row g-4 mb-5">
            <div class="col-lg-6">
                <?php if (!empty($resultados_busqueda)): ?>
                    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
                        <div class="card-header bg-white py-3 fw-bold border-0">Resultados encontrados</div>
                        <div class="list-group list-group-flush">
                            <?php foreach ($resultados_busqueda as $cli): ?>
                                <a href="canje_puntos.php?id_cliente=<?php echo $cli['id']; ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3">
                                    <div>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($cli['nombre']); ?></div>
                                        <small class="text-muted">DNI: <?php echo $cli['dni']; ?></small>
                                    </div>
                                    <span class="badge bg-warning text-dark rounded-pill px-3"><?php echo $cli['puntos_acumulados']; ?> pts</span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                    <div class="card-header bg-white py-3 fw-bold border-0"><i class="bi bi-trophy text-warning me-2"></i> Ranking de Fidelidad</div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 tabla-movil-ajustada">
                            <tbody>
                                <?php foreach($topClientes as $tc): ?>
                                    <tr onclick="window.location.href='canje_puntos.php?id_cliente=<?php echo $tc['id']; ?>'" style="cursor:pointer">
                                        <td class="ps-4 py-3"><b><?php echo htmlspecialchars($tc['nombre']); ?></b></td>
                                        <td class="text-end pe-4"><span class="badge bg-warning text-dark rounded-pill"><?php echo $tc['puntos_acumulados']; ?> pts</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-6 d-none d-lg-block">
                <div class="card border-0 shadow-sm rounded-4 bg-light h-100 d-flex align-items-center justify-content-center p-5 text-center">
                    <div>
                        <i class="bi bi-gift text-muted opacity-25" style="font-size: 5rem;"></i>
                        <h5 class="text-muted mt-3">Gestioná recompensas de forma rápida</h5>
                        <p class="small text-muted">Buscá un cliente para ver qué premios puede canjear hoy según sus puntos acumulados.</p>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card border-0 shadow rounded-4 overflow-hidden">
                    <div class="client-card-header text-center pb-5">
                        <i class="bi bi-person-circle display-1"></i>
                        <h4 class="fw-bold mt-2"><?php echo htmlspecialchars($cliente_seleccionado['nombre']); ?></h4>
                        <div class="badge bg-white bg-opacity-25 rounded-pill px-3">Cliente #<?php echo $cliente_seleccionado['id']; ?></div>
                    </div>
                    <div class="card-body text-center" style="margin-top: -40px;">
                        <div class="card border-0 shadow-sm mb-4 rounded-4">
                            <div class="card-body py-4">
                                <small class="fw-bold text-muted text-uppercase" style="letter-spacing: 1px;">Puntos Disponibles</small>
                                <div class="display-4 fw-bold text-warning"><?php echo number_format($cliente_seleccionado['puntos_acumulados']); ?></div>
                            </div>
                        </div>
                        <div class="text-muted small">
                            <i class="bi bi-info-circle me-1"></i> 
                            Los puntos se descuentan automáticamente al confirmar el canje.
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3">
                    <?php foreach($premios as $p): 
                        $alcanza = $cliente_seleccionado['puntos_acumulados'] >= $p['puntos_necesarios'];
                    ?>
                    <div class="col">
                        <div class="card prize-card h-100 <?php echo $alcanza ? 'border-success' : 'opacity-50 grayscale'; ?>">
                            <div class="card-body text-center d-flex flex-column p-4">
                                <div class="mb-3">
                                    <div class="bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 70px; height: 70px;">
                                        <i class="bi <?php echo $p['es_cupon'] ? 'bi-ticket-perforated' : 'bi-gift'; ?> h2 text-primary mb-0"></i>
                                    </div>
                                </div>
                                <h6 class="fw-bold text-dark"><?php echo htmlspecialchars($p['nombre']); ?></h6>
                                <div class="mt-auto">
                                    <h4 class="fw-bold text-success mb-3"><?php echo number_format($p['puntos_necesarios']); ?> <small style="font-size: 0.9rem;">pts</small></h4>
                                    <?php if($alcanza): ?>
                                        <button onclick="canjear(<?php echo $p['id']; ?>, '<?php echo addslashes($p['nombre']); ?>', <?php echo $p['puntos_necesarios']; ?>)" class="btn btn-success w-100 rounded-pill fw-bold py-2 shadow-sm">CANJEAR AHORA</button>
                                    <?php else: ?>
                                        <div class="bg-danger bg-opacity-10 text-danger small fw-bold py-2 rounded-pill">Faltan <?php echo number_format($p['puntos_necesarios'] - $cliente_seleccionado['puntos_acumulados']); ?> pts</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<form id="formCanje" method="POST">
    <input type="hidden" name="canjear" value="1">
    <input type="hidden" name="id_cliente" value="<?php echo $cliente_seleccionado['id'] ?? ''; ?>">
    <input type="hidden" name="id_premio" id="p_id">
</form>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    <?php echo $mensaje_sweet; ?>
    if(new URLSearchParams(window.location.search).get('exito') === '1') {
        Swal.fire({
            title: '¡Canje Exitoso!',
            text: 'Los puntos han sido descontados y el premio asignado correctamente.',
            icon: 'success',
            confirmButtonColor: '#102A57'
        });
    }
    function canjear(id, nom, pts) {
        Swal.fire({
            title: '¿Confirmar Canje?',
            text: `Vas a canjear ${pts} puntos por: ${nom}`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'SÍ, CANJEAR',
            cancelButtonText: 'CANCELAR',
            confirmButtonColor: '#198754',
            cancelButtonColor: '#6c757d',
            reverseButtons: true
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('p_id').value = id;
                document.getElementById('formCanje').submit();
            }
        });
    }
</script>

<?php include 'includes/layout_footer.php'; ?>