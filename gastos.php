<?php
// gastos.php - VERSIÓN FINAL CORREGIDA (TOTAL MODAL + EMAIL AJAX)
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

$rutas_db = ['db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

$permisos = $_SESSION['permisos'] ?? [];
$rol = $_SESSION['rol'] ?? 3;
if (!in_array('gestionar_gastos', $permisos) && $rol > 2) { header("Location: dashboard.php"); exit; }

$usuario_id = $_SESSION['usuario_id'];
$stmt = $conexion->prepare("SELECT id FROM cajas_sesion WHERE id_usuario = ? AND estado = 'abierta'");
$stmt->execute([$usuario_id]);
$caja = $stmt->fetch(PDO::FETCH_ASSOC);
$id_caja_sesion = $caja['id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!$id_caja_sesion) { die("Error: Debes tener una caja abierta para registrar una salida."); }
    $desc = $_POST['descripcion'];
    $monto = $_POST['monto'];
    $cat = $_POST['categoria'];
    
    $stmt = $conexion->prepare("INSERT INTO gastos (descripcion, monto, categoria, fecha, id_usuario, id_caja_sesion) VALUES (?, ?, ?, NOW(), ?, ?)");
    $stmt->execute([$desc, $monto, $cat, $_SESSION['usuario_id'], $id_caja_sesion]);
    $id_gasto_nuevo = $conexion->lastInsertId();

    try {
        $detalles_audit = "Gasto registrado (#" . $id_gasto_nuevo . ") en categoría '" . $cat . "' por $" . number_format($monto, 2) . ". Detalle: " . $desc;
        $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'GASTO', ?, NOW())")->execute([$_SESSION['usuario_id'], $detalles_audit]);
    } catch (Exception $e) { }

    header("Location: gastos.php?msg=ok"); exit;
}

$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-t');

$stmtG = $conexion->prepare("SELECT g.*, u.usuario, u.nombre_completo FROM gastos g JOIN usuarios u ON g.id_usuario = u.id WHERE DATE(g.fecha) >= ? AND DATE(g.fecha) <= ? ORDER BY g.fecha DESC");
$stmtG->execute([$desde, $hasta]);
$gastos = $stmtG->fetchAll(PDO::FETCH_ASSOC);

foreach ($gastos as &$g) {
    $g['info_extra_titulo'] = '';   
    $g['info_extra_nombre'] = '';   
    $g['lista_items_titulo'] = '';  
    $g['lista_items'] = [];         

    if (($g['categoria'] == 'Fidelizacion' || $g['categoria'] == 'Fidelización') && preg_match('/Cliente #(\d+)/', $g['descripcion'], $matches)) {
        $idCliente = $matches[1];
        $stmt = $conexion->prepare("SELECT nombre FROM clientes WHERE id = ?");
        $stmt->execute([$idCliente]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $g['info_extra_titulo'] = 'BENEFICIARIO';
            $g['info_extra_nombre'] = $row['nombre'];
        }
    }
    elseif ($g['categoria'] == 'Devoluciones' && preg_match('/Ticket #(\d+)/', $g['descripcion'], $matches)) {
        $idTicket = $matches[1];
        $stmt = $conexion->prepare("SELECT c.nombre FROM ventas v JOIN clientes c ON v.id_cliente = c.id WHERE v.id = ?");
        $stmt->execute([$idTicket]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $g['info_extra_titulo'] = 'CLIENTE ORIG.';
            $g['info_extra_nombre'] = $row['nombre'];
        }
    }
}

$conf = $conexion->query("SELECT nombre_negocio, direccion_local, cuit, logo_url, color_barra_nav, telefono_whatsapp FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$color_sistema = $conf['color_barra_nav'] ?? '#102A57';

$ruta_firma = "img/firmas/firma_admin.png";
if (!file_exists($ruta_firma) && file_exists("img/firmas/usuario_{$usuario_id}.png")) {
    $ruta_firma = "img/firmas/usuario_{$usuario_id}.png";
}

$totalFiltrado = 0;
foreach($gastos as $gas) { $totalFiltrado += $gas['monto']; }

include 'includes/layout_header.php'; ?>

<div class="header-blue" style="background: <?php echo $color_sistema; ?> !important; border-radius: 0 !important; width: 100vw; margin-left: calc(-50vw + 50%); padding: 40px 0; position: relative; overflow: hidden; z-index: 10;">
    <i class="bi bi-wallet2 bg-icon-large" style="z-index: 0;"></i>
    <div class="container position-relative" style="z-index: 2;">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="font-cancha mb-0 text-white">Gastos y Retiros</h2>
                <p class="opacity-75 mb-0 text-white small">Control de movimientos operativos.</p>
            </div>
            <a href="reporte_gastos.php?desde=<?php echo $desde; ?>&hasta=<?php echo $hasta; ?>" target="_blank" class="btn btn-danger fw-bold rounded-pill px-4 shadow-sm">
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
                    <div><div class="widget-label">Salidas Filtradas</div><div class="widget-value text-white">$<?php echo number_format($totalFiltrado, 0, ',', '.'); ?></div></div>
                    <div class="icon-box bg-danger bg-opacity-20 text-white"><i class="bi bi-cash-stack"></i></div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="header-widget border-info">
                    <div><div class="widget-label">Estado Caja</div><div class="widget-value text-white" style="font-size: 1.1rem;"><?php echo $id_caja_sesion ? 'OPERATIVA' : 'LECTURA'; ?></div></div>
                    <div class="icon-box bg-info bg-opacity-20 text-white"><i class="bi bi-shield-check"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5 mt-4">
    <div class="row g-4">
        <div class="col-md-4">
            <div class="card card-custom border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-bold py-3 border-bottom-0 text-danger">
                    <i class="bi bi-dash-circle-fill me-2"></i> Nuevo Retiro
                </div>
                <div class="card-body bg-light rounded-bottom">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="small fw-bold text-muted text-uppercase">Monto ($)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-danger fw-bold">$</span>
                                <input type="number" step="0.01" name="monto" class="form-control form-control-lg fw-bold border-start-0 text-danger" required placeholder="0.00">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="small fw-bold text-muted text-uppercase">Descripción</label>
                            <input type="text" name="descripcion" class="form-control" required placeholder="Ej: Pago Proveedor">
                        </div>
                        <div class="mb-4">
                            <label class="small fw-bold text-muted text-uppercase">Categoría</label>
                            <select name="categoria" class="form-select">
                                <option value="Proveedores">🚚 Proveedores</option>
                                <option value="Servicios">💡 Servicios</option>
                                <option value="Alquiler">🏠 Alquiler</option>
                                <option value="Sueldos">👥 Sueldos</option>
                                <option value="Retiro">💸 Retiro Ganancias</option>
                                <option value="Insumos">🧻 Insumos</option>
                                <option value="Otros">📦 Otros</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-danger w-100 fw-bold py-2 shadow-sm rounded-pill">
                            <i class="bi bi-check-lg me-2"></i> REGISTRAR SALIDA
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card card-custom border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-bold py-3 border-bottom d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-clock-history me-2 text-secondary"></i> Movimientos</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 text-center">
                        <thead class="bg-light small text-uppercase text-muted">
                            <tr>
                                <th class="ps-4 py-3 text-start">Fecha</th>
                                <th class="text-start">Detalle</th>
                                <th>Categoría</th>
                                <th class="text-end pe-4">Monto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($gastos as $g): 
                                $icono = 'bi-box-seam';
                                if($g['categoria'] == 'Proveedores') $icono = 'bi-truck';
                                if($g['categoria'] == 'Servicios') $icono = 'bi-lightning-charge';
                                if($g['categoria'] == 'Sueldos') $icono = 'bi-people';
                                if($g['categoria'] == 'Retiro') $icono = 'bi-cash-stack';
                                $jsonData = htmlspecialchars(json_encode($g), ENT_QUOTES, 'UTF-8');
                            ?>
                            <tr style="cursor:pointer;" onclick="verTicket(<?php echo $jsonData; ?>)">
                                <td class="ps-4 text-start">
                                    <div class="fw-bold"><?php echo date('d/m/Y', strtotime($g['fecha'])); ?></div>
                                    <small class="text-muted"><?php echo date('H:i', strtotime($g['fecha'])); ?> hs</small>
                                </td>
                                <td class="text-start">
                                    <div class="fw-bold text-dark"><?php echo htmlspecialchars($g['descripcion']); ?></div>
                                    <small class="text-muted"><i class="bi bi-person-circle me-1"></i> <?php echo htmlspecialchars($g['usuario']); ?></small>
                                </td>
                                <td><span class="badge bg-light text-dark border"><i class="bi <?php echo $icono; ?> me-1"></i> <?php echo $g['categoria']; ?></span></td>
                                <td class="text-end text-danger fw-bold pe-4">-$<?php echo number_format($g['monto'], 2, ',', '.'); ?></td>
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
const miLocal = <?php echo json_encode($conf); ?>;
const miFirma = "<?php echo file_exists($ruta_firma) ? $ruta_firma : ''; ?>";

function verTicket(gasto) {
    let montoF = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(gasto.monto);
    let fechaF = new Date(gasto.fecha).toLocaleString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    let linkPdfPublico = window.location.origin + "/ticket_gasto_pdf.php?id=" + gasto.id + "&v=" + Date.now();
    let qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=110x110&margin=2&data=` + encodeURIComponent(linkPdfPublico);
    let logoHtml = miLocal.logo_url ? `<img src="${miLocal.logo_url}?v=${Date.now()}" style="max-height: 50px; margin-bottom: 10px;">` : '';
    let nombreFirma = gasto.nombre_completo ? gasto.nombre_completo.toUpperCase() : 'FIRMA AUTORIZADA';
    let firmaHtml = miFirma ? `<img src="${miFirma}?v=${Date.now()}" style="max-height: 50px;"><br><div style="border-top:1px solid #000; width:100%; margin-top:5px;"></div><small style="font-size:9px; font-weight:bold;">${nombreFirma}</small>` : '';

    let ticketHTML = `
        <div id="printTicket" style="font-family: 'Inter', sans-serif; text-align: left; color: #000; padding: 10px;">
            <div style="text-align: center; border-bottom: 2px dashed #ccc; padding-bottom: 15px; margin-bottom: 15px;">
                ${logoHtml}
                <h4 style="font-weight: 900; margin: 0; text-transform: uppercase;">${miLocal.nombre_negocio}</h4>
                <small style="color: #666;">CUIT: ${miLocal.cuit || 'S/N'}<br>${miLocal.direccion_local}</small>
            </div>
            <div style="text-align: center; margin-bottom: 15px;">
                <h5 style="font-weight: 900; color: #dc3545; letter-spacing: 1px; margin:0;">COMPROBANTE GASTO</h5>
                <span style="font-size: 10px; background: #eee; padding: 2px 6px; border-radius: 4px;">OP #${gasto.id}</span>
            </div>
            <div style="background: #f8f9fa; border: 1px solid #eee; padding: 10px; border-radius: 8px; margin-bottom: 15px; font-size: 13px;">
                <div style="margin-bottom: 4px;"><strong>FECHA:</strong> ${fechaF}</div>
                <div style="margin-bottom: 4px;"><strong>CATEGORÍA:</strong> ${gasto.categoria}</div>
                <div><strong>OPERADOR:</strong> ${(gasto.usuario || 'ADMIN').toUpperCase()}</div>
            </div>
            <div style="margin-bottom: 15px; font-size: 13px;">
                <strong style="border-bottom: 1px solid #ccc; display:block; margin-bottom:5px;">DETALLE:</strong>
                ${gasto.descripcion}
            </div>
            <div style="background: #dc354510; border-left: 4px solid #dc3545; padding: 12px; display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <span style="font-size: 1.1em; font-weight:800;">TOTAL:</span>
                <span style="font-size: 1.15em; font-weight:900; color: #dc3545;">-${montoF}</span>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 20px; padding-top: 15px; border-top: 2px dashed #eee;">
                <div style="width: 45%; text-align: center;">${firmaHtml}</div>
                <div style="width: 45%; text-align: center;">
                    <a href="${linkPdfPublico}" target="_blank"><img src="${qrUrl}" style="width: 75px; height: 75px; border: 1px solid #ddd; padding: 3px; border-radius: 5px;"></a>
                    <div style="font-size: 8px; color: #999; margin-top: 3px;">ESCANEAR PDF</div>
                </div>
            </div>
        </div>
        <div class="d-flex justify-content-center gap-2 mt-4 border-top pt-3 no-print">
            <button class="btn btn-sm btn-outline-dark fw-bold" onclick="window.open('${linkPdfPublico}', '_blank')"><i class="bi bi-file-earmark-pdf-fill"></i> PDF</button>
            <button class="btn btn-sm btn-success fw-bold" onclick="mandarWAGasto('${gasto.categoria}', '${montoF}', '${linkPdfPublico}')"><i class="bi bi-whatsapp"></i> WA</button>
            <button class="btn btn-sm btn-primary fw-bold" onclick="mandarMailGasto(${gasto.id})"><i class="bi bi-envelope"></i> EMAIL</button>
        </div>
    `;
    Swal.fire({ html: ticketHTML, width: 400, showConfirmButton: false, showCloseButton: true, background: '#fff' });
}

function mandarWAGasto(cat, monto, link) {
    let msj = `Se registró Gasto de *${cat}* por *${monto}*.\n📄 Ticket: ${link}`;
    window.open(`https://wa.me/?text=${encodeURIComponent(msj)}`, '_blank');
}

function mandarMailGasto(id) {
    Swal.fire({
        title: 'Enviar Ticket', text: 'Email de destino:', input: 'email', showCancelButton: true, confirmButtonText: 'Enviar'
    }).then((r) => {
        if(r.isConfirmed && r.value) {
            Swal.fire({ title: 'Enviando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); }});
            let fData = new FormData(); fData.append('id', id); fData.append('email', r.value);
            fetch('acciones/enviar_email_gasto.php', { method: 'POST', body: fData })
            .then(res => res.json()).then(d => {
                if(d.status === 'success') Swal.fire('¡Éxito!', 'Correo enviado.', 'success');
                else Swal.fire('Error', d.msg, 'error');
            });
        }
    });
}
</script>
<?php include 'includes/layout_footer.php'; ?>