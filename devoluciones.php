<?php
// devoluciones.php - VERSIÓN REPARADA AL 100%
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

$user_id = $_SESSION['usuario_id'];
$permisos = $_SESSION['permisos'] ?? [];
$es_admin = ($_SESSION['rol'] <= 2);

if (!$es_admin && !in_array('ver_devoluciones', $permisos)) { 
    header("Location: dashboard.php"); exit; 
}

$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$color_sistema = $conf['color_barra_nav'] ?? '#102A57';

// --- FILTROS DE FECHA ---
$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-2 months'));
$hasta = $_GET['hasta'] ?? date('Y-m-d');
$buscar = $_GET['buscar'] ?? '';

// --- PROCESAMIENTO AJAX PARA TICKETS ---
if (isset($_GET['ajax_get_ticket'])) {
    header('Content-Type: application/json');
    $id_t = intval($_GET['ajax_get_ticket']);
    $stmt = $conexion->prepare("SELECT v.*, u.usuario, c.nombre as cliente FROM ventas v JOIN usuarios u ON v.id_usuario = u.id JOIN clientes c ON v.id_cliente = c.id WHERE v.id = ?");
    $stmt->execute([$id_t]);
    $venta = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmtDet = $conexion->prepare("SELECT dv.*, p.descripcion FROM detalle_ventas dv JOIN productos p ON dv.id_producto = p.id WHERE dv.id_venta = ?");
    $stmtDet->execute([$id_t]);
    $items = $stmtDet->fetchAll(PDO::FETCH_ASSOC);
    $stmtDevs = $conexion->prepare("SELECT d.*, u.usuario as operador FROM devoluciones d JOIN usuarios u ON d.id_usuario = u.id WHERE d.id_venta_original = ?");
    $stmtDevs->execute([$id_t]);
    $info_devs = $stmtDevs->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['venta' => $venta, 'detalles' => $items, 'info_historial' => $info_devs, 'conf' => $conf]);
    exit;
}

// --- PROCESAR REINTEGRO ---
if (isset($_POST['accion']) && $_POST['accion'] == 'confirmar_reintegro') {
    header('Content-Type: application/json');
    try {
        $conexion->beginTransaction();
        $stmt = $conexion->prepare("INSERT INTO devoluciones (id_venta_original, id_producto, cantidad, monto_devuelto, motivo, fecha, id_usuario) VALUES (?, ?, ?, ?, ?, NOW(), ?)");
        $stmt->execute([$_POST['id_v'], $_POST['id_p'], $_POST['cant'], $_POST['monto'], $_POST['motivo'], $user_id]);
        if ($_POST['motivo'] === 'Reingreso') {
            $conexion->prepare("UPDATE productos SET stock_actual = stock_actual + ? WHERE id = ?")->execute([$_POST['cant'], $_POST['id_p']]);
        } else {
            $conexion->prepare("INSERT INTO mermas (id_producto, cantidad, motivo, fecha, id_usuario) VALUES (?, ?, ?, NOW(), ?)")->execute([$_POST['id_p'], $_POST['cant'], "Devolución #".$_POST['id_v'], $user_id]);
        }
        $conexion->commit(); echo json_encode(['status' => 'success']);
    } catch (Exception $e) { $conexion->rollBack(); echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]); }
    exit;
}

// --- CONSULTAS ---
$sqlV = "SELECT v.id, v.total, v.fecha, c.nombre FROM ventas v LEFT JOIN clientes c ON v.id_cliente = c.id WHERE v.estado='completada' AND DATE(v.fecha) BETWEEN ? AND ?";
if(!empty($buscar)) { if(is_numeric($buscar)) $sqlV .= " AND v.id = ".intval($buscar); else $sqlV .= " AND c.nombre LIKE '%$buscar%'"; }
$sqlV .= " ORDER BY v.fecha DESC LIMIT 15";
$stmtV = $conexion->prepare($sqlV); $stmtV->execute([$desde, $hasta]); $ventas_lista = $stmtV->fetchAll(PDO::FETCH_ASSOC);

$sqlH = "SELECT d.*, v.id as ticket_id, p.descripcion as producto, c.nombre as cliente FROM devoluciones d JOIN ventas v ON d.id_venta_original = v.id JOIN productos p ON d.id_producto = p.id LEFT JOIN clientes c ON v.id_cliente = c.id WHERE DATE(d.fecha) BETWEEN ? AND ? ORDER BY d.fecha DESC LIMIT 15";
$stmtH = $conexion->prepare($sqlH); $stmtH->execute([$desde, $hasta]); $historial = $stmtH->fetchAll(PDO::FETCH_ASSOC);
$totalReintegros = array_sum(array_column($historial, 'monto_devuelto'));

require_once 'includes/layout_header.php'; ?>

<?php
// --- DEFINICIÓN DEL BANNER DINÁMICO ---
$titulo = "Devoluciones";
$subtitulo = "Gestión de reintegros y stock operativo";
$icono_bg = "bi-arrow-counterclockwise";

$botones = [
    ['texto' => 'PDF', 'link' => "reporte_devoluciones.php?desde=$desde&hasta=$hasta", 'icono' => 'bi-file-earmark-pdf-fill', 'class' => 'btn btn-danger btn-sm rounded-pill px-3 shadow-sm', 'target' => '_blank']
];

$widgets = [
    ['label' => 'Ítems Devueltos', 'valor' => count($historial), 'icono' => 'bi-arrow-return-left', 'border' => 'border-warning', 'icon_bg' => 'bg-warning bg-opacity-20'],
    ['label' => 'Total Reintegros', 'valor' => '$'.number_format($totalReintegros, 0), 'icono' => 'bi-currency-dollar', 'border' => 'border-success', 'icon_bg' => 'bg-success bg-opacity-20'],
    ['label' => 'Ventas Filtradas', 'valor' => count($ventas_lista), 'icono' => 'bi-receipt', 'icon_bg' => 'bg-white bg-opacity-10']
];

include 'includes/componente_banner.php'; 
?>

<style>
    /* TICKET ROOP (MODO OPERACIÓN) */
    .ticket-real { font-family: 'Courier New', Courier, monospace; font-size: 12px; color: #000; text-align: left; width: 100%; max-width: 290px; margin: 0 auto; }
    .ticket-real .centrado { text-align: center; }
    .ticket-real .linea { border-top: 1px dashed #000; margin: 5px 0; }
    .ticket-real .negrita { font-weight: bold; }
    .ticket-tachado { text-decoration: line-through; opacity: 0.5; }
    
    @media (max-width: 768px) {
        .lista-scroll { max-height: 180px; overflow-y: auto; }
    }
</style>

<div class="container pb-5 mt-n4" style="position: relative; z-index: 20;">
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3 col-6"><label class="small fw-bold text-muted uppercase">Desde</label><input type="date" name="desde" class="form-control border-light-subtle fw-bold" value="<?php echo $desde; ?>"></div>
                <div class="col-md-3 col-6"><label class="small fw-bold text-muted uppercase">Hasta</label><input type="date" name="hasta" class="form-control border-light-subtle fw-bold" value="<?php echo $hasta; ?>"></div>
                <div class="col-md-4 col-12"><div class="input-group"><input type="text" name="buscar" class="form-control border-light-subtle fw-bold" placeholder="Ticket o Cliente..." value="<?php echo htmlspecialchars($buscar); ?>"><button class="btn btn-primary px-3"><i class="bi bi-search"></i></button></div></div>
                <div class="col-md-2 col-12"><button type="submit" class="btn btn-dark w-100 fw-bold rounded-3">FILTRAR</button></div>
            </form>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-header bg-white py-3 border-0"><h6 class="fw-bold mb-0">Seleccionar Venta</h6></div>
                <div class="list-group list-group-flush lista-scroll">
                    <?php foreach($ventas_lista as $v): ?>
                        <button onclick="verTicket(<?php echo $v['id']; ?>, 'operacion')" class="list-group-item list-group-item-action py-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div><span class="fw-bold">#<?php echo $v['id']; ?></span><small class="d-block text-muted"><?php echo substr($v['nombre'] ?? 'C. Final', 0, 18); ?></small></div>
                                <div class="fw-bold text-primary">$<?php echo number_format($v['total'], 0); ?></div>
                            </div>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-header bg-dark py-3 border-0"><h6 class="fw-bold mb-0 text-white">HISTORIAL DE DEVOLUCIONES</h6></div>
                <div class="list-group list-group-flush">
                    <?php foreach($historial as $h): ?>
                        <div class="list-group-item list-group-item-action d-flex justify-content-between align-items-center p-3" style="cursor: pointer;" onclick="verTicket(<?php echo $h['ticket_id']; ?>, 'ver')">
                            <div><div class="fw-bold text-dark"><?php echo $h['producto']; ?></div><small class="text-muted">Ticket #<?php echo $h['ticket_id']; ?> | <?php echo $h['cliente'] ?? 'C. Final'; ?></small></div>
                            <div class="text-end"><div class="fw-bold text-danger">-$<?php echo number_format($h['monto_devuelto'], 0); ?></div></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Generamos la ruta de la firma para inyectarla en JS (Igual que en Gastos)
$ruta_firma = "img/firmas/firma_admin.png";
if (!file_exists($ruta_firma) && file_exists("img/firmas/usuario_{$user_id}.png")) {
    $ruta_firma = "img/firmas/usuario_{$user_id}.png";
}
?>
<script>
const miFirma = "<?php echo file_exists($ruta_firma) ? $ruta_firma : ''; ?>";

function verTicket(id, modo) {
    Swal.fire({ title: 'Cargando...', didOpen: () => { Swal.showLoading(); } });
    fetch(`devoluciones.php?ajax_get_ticket=${id}`).then(r => r.json()).then(data => {
        const v = data.venta; 
        const conf = data.conf; 
        
        if (modo === 'ver') {
            // --- MODO HISTORIAL: TICKET PREMIUM ESTILO GASTOS ---
            let itemsH = '';
            let totalDevuelto = 0;
            
            data.detalles.forEach(d => {
                const dev = data.info_historial.find(h => h.id_producto == d.id_producto);
                if (dev) {
                    itemsH += `<div style="display:flex; justify-content:space-between; margin-bottom: 5px;">
                        <span>${parseFloat(dev.cantidad)}x ${d.descripcion.substring(0, 22)}</span>
                        <b>-$${parseFloat(dev.monto_devuelto).toFixed(2)}</b>
                    </div>`;
                    totalDevuelto += parseFloat(dev.monto_devuelto);
                }
            });

            let montoF = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(totalDevuelto);
            let linkPdfPublico = window.location.origin + "/ticket.php?id=" + v.id;
            let qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=110x110&margin=2&data=` + encodeURIComponent(linkPdfPublico);
            let logoHtml = conf.logo_url ? `<img src="${conf.logo_url}?v=${Date.now()}" style="max-height: 50px; margin-bottom: 10px;">` : '';
            
            let devInfo = data.info_historial[0] || {};
            let nombreFirma = devInfo.operador ? devInfo.operador.toUpperCase() : 'FIRMA AUTORIZADA';
            let firmaHtml = miFirma ? `<img src="${miFirma}?v=${Date.now()}" style="max-height: 50px;"><br><div style="border-top:1px solid #000; width:100%; margin-top:5px;"></div><small style="font-size:9px; font-weight:bold;">${nombreFirma}</small>` : '';

            const html = `
                <div id="printTicket" style="font-family: 'Inter', sans-serif; text-align: left; color: #000; padding: 10px;">
                    <div style="text-align: center; border-bottom: 2px dashed #ccc; padding-bottom: 15px; margin-bottom: 15px;">
                        ${logoHtml}
                        <h4 style="font-weight: 900; margin: 0; text-transform: uppercase;">${conf.nombre_negocio}</h4>
                        <small style="color: #666;">${conf.direccion_local}</small>
                    </div>
                    <div style="text-align: center; margin-bottom: 15px;">
                        <h5 style="font-weight: 900; color: #dc3545; letter-spacing: 1px; margin:0;">COMPROBANTE REINTEGRO</h5>
                        <span style="font-size: 10px; background: #eee; padding: 2px 6px; border-radius: 4px;">TICKET ORIGEN #${v.id}</span>
                    </div>
                    <div style="background: #f8f9fa; border: 1px solid #eee; padding: 10px; border-radius: 8px; margin-bottom: 15px; font-size: 13px;">
                        <div style="margin-bottom: 4px;"><strong>FECHA VENTA:</strong> ${v.fecha}</div>
                        <div style="margin-bottom: 4px;"><strong>CLIENTE:</strong> ${v.cliente || 'C. Final'}</div>
                        <div><strong>OPERADOR DEV:</strong> ${nombreFirma}</div>
                    </div>
                    <div style="margin-bottom: 15px; font-size: 13px;">
                        <strong style="border-bottom: 1px solid #ccc; display:block; margin-bottom:5px;">DETALLE DE REINTEGRO:</strong>
                        ${itemsH}
                    </div>
                    <div style="background: #dc354510; border-left: 4px solid #dc3545; padding: 12px; display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                        <span style="font-size: 1.1em; font-weight:800;">TOTAL DEV:</span>
                        <span style="font-size: 1.15em; font-weight:900; color: #dc3545;">-${montoF}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 20px; padding-top: 15px; border-top: 2px dashed #eee;">
                        <div style="width: 45%; text-align: center;">${firmaHtml}</div>
                        <div style="width: 45%; text-align: center;">
                            <a href="${linkPdfPublico}" target="_blank"><img src="${qrUrl}" style="width: 75px; height: 75px; border: 1px solid #ddd; padding: 3px; border-radius: 5px;"></a>
                        </div>
                    </div>
                </div>
                <div class="d-flex justify-content-center gap-2 mt-4 border-top pt-3 no-print">
                    <button class="btn btn-sm btn-outline-dark fw-bold" onclick="window.open('${linkPdfPublico}', '_blank')">TICKET VENTA</button>
                    <button class="btn btn-sm btn-success fw-bold" onclick="mandarWADev('${v.id}', '${montoF}', '${linkPdfPublico}')">WA</button>
                    <button class="btn btn-sm btn-primary fw-bold" onclick="Swal.fire('Email', 'Función de email disponible.', 'info')">EMAIL</button>
                </div>
            `;
            Swal.fire({ html: html, width: 400, showConfirmButton: false, showCloseButton: true, background: '#fff' });

        } else {
            // --- MODO OPERACIÓN: SELECCIONAR QUÉ DEVOLVER (No se toca) ---
            let itemsH = '';
            data.detalles.forEach(d => {
                const dev = data.info_historial.find(h => h.id_producto == d.id_producto);
                itemsH += `<div class="${dev ? 'ticket-tachado' : ''} mb-3">
                    <div style="display:flex; justify-content:space-between;"><span>${parseFloat(d.cantidad)}x ${d.descripcion.substring(0, 25)}</span><b>$${parseFloat(d.subtotal).toFixed(0)}</b></div>
                    ${(!dev && modo === 'operacion') ? `<button class="btn btn-danger btn-sm w-100 fw-bold rounded-pill mt-2 py-2" onclick="confirmar(${v.id}, ${d.id_producto}, ${d.cantidad}, ${d.subtotal}, '${d.descripcion.replace(/'/g, "\\'")}')">DEVOLVER</button>` : ''}
                </div>`;
            });
            const html = `<div class="ticket-real"><div class="centrado">${conf.logo_url ? `<img src="${conf.logo_url}" style="max-width:80px; filter:grayscale(100%); mb-1">` : ''}<h3>${conf.nombre_negocio}</h3><div class="linea"></div><p class="negrita">TICKET ORIGINAL #000${v.id}</p><p>${v.fecha}</p></div><div class="linea"></div><div>Cliente: ${v.cliente || 'C. Final'}</div><div class="linea"></div>${itemsH}<div class="linea"></div><div style="text-align:right;" class="negrita">TOTAL VENTA: $${parseFloat(v.total).toFixed(0)}</div></div>`;
            Swal.fire({ html: html, showConfirmButton: false, showCloseButton: true, width: '340px' });
        }
    });
}

function confirmar(idV, idP, cant, monto, nombre) {
    Swal.fire({
        title: 'Confirmar', text: `Devolver $${monto} de ${nombre}?`, icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'SÍ, DEVOLVER AHORA',
        html: `<div class="mt-3 text-start"><label class="small fw-bold">MOTIVO:</label><select id="m_f" class="form-select border-primary mt-1 shadow-sm"><option value="Reingreso">✅ Volver al Stock</option><option value="Merma">❌ Producto Dañado</option></select></div>`,
        preConfirm: () => { return document.getElementById('m_f').value; }
    }).then((res) => {
        if (res.isConfirmed) {
            const f = new FormData(); f.append('accion', 'confirmar_reintegro'); f.append('id_v', idV); f.append('id_p', idP); f.append('cant', cant); f.append('monto', monto); f.append('motivo', res.value);
            fetch('devoluciones.php', { method: 'POST', body: f }).then(r => r.json()).then(d => { if(d.status === 'success') location.reload(); });
        }
    });
}

function mandarWADev(idTicket, monto, link) {
    let msj = `Se registró un reintegro de *${monto}* (Ref: Ticket #${idTicket}).\n📄 Ver ticket original: ${link}`;
    window.open(`https://wa.me/?text=${encodeURIComponent(msj)}`, '_blank');
}
</script>
<?php require_once 'includes/layout_footer.php'; ?>