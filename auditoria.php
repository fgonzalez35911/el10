<?php
// auditoria.php - VERSIÓN ESTANDARIZADA (FILTROS + PDF + TICKET PREMIUM)
session_start();
date_default_timezone_set('America/Argentina/Buenos_Aires');

$rutas_db = [__DIR__ . '/db.php', __DIR__ . '/includes/db.php', 'db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// CONFIGURACIÓN Y FIRMA
$conf = $conexion->query("SELECT nombre_negocio, direccion_local, cuit, logo_url, color_barra_nav FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$color_sistema = $conf['color_barra_nav'] ?? '#102A57';

$usuario_id = $_SESSION['usuario_id'];
$ruta_firma = "img/firmas/firma_admin.png";
if (!file_exists($ruta_firma) && file_exists("img/firmas/usuario_{$usuario_id}.png")) {
    $ruta_firma = "img/firmas/usuario_{$usuario_id}.png";
}

// FILTROS DESDE / HASTA Y EXTRAS
$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-1 week'));
$hasta = $_GET['hasta'] ?? date('Y-m-d');
$f_user   = $_GET['f_user'] ?? '';
$f_accion = $_GET['f_accion'] ?? '';

$sql_aud = "SELECT a.id, a.fecha, a.id_usuario, a.accion, a.detalles, u.usuario, u.nombre_completo 
            FROM auditoria a 
            JOIN usuarios u ON a.id_usuario = u.id 
            WHERE DATE(a.fecha) >= ? AND DATE(a.fecha) <= ?";
$params_aud = [$desde, $hasta];

if(!empty($f_user)) { $sql_aud .= " AND a.id_usuario = ?"; $params_aud[] = $f_user; }
if(!empty($f_accion)) { $sql_aud .= " AND a.accion LIKE ?"; $params_aud[] = "%$f_accion%"; }

$sql_aud .= " ORDER BY a.fecha DESC";
$st_aud = $conexion->prepare($sql_aud);
$st_aud->execute($params_aud);
$logs_todos = $st_aud->fetchAll(PDO::FETCH_ASSOC);

$total_regs = count($logs_todos);
$pag = isset($_GET['pag']) ? (int)$_GET['pag'] : 1;
$reg_x_pag = 100;
$inicio_limit = ($pag - 1) * $reg_x_pag;
$logs = array_slice($logs_todos, $inicio_limit, $reg_x_pag);

foreach ($logs as &$l) {
    $l['rich_data'] = null;
    if ((strpos(strtoupper($l['accion']), 'VENTA') !== false) && preg_match('/Venta #(\d+)/', $l['detalles'], $m)) {
        $idVenta = $m[1];
        $stmtV = $conexion->prepare("SELECT v.fecha, v.total, v.metodo_pago, v.descuento_manual, v.descuento_monto_cupon, c.nombre as nombre_cliente FROM ventas v LEFT JOIN clientes c ON v.id_cliente = c.id WHERE v.id = ?");
        $stmtV->execute([$idVenta]);
        $ventaInfo = $stmtV->fetch(PDO::FETCH_ASSOC);
        if ($ventaInfo) {
            $stmtD = $conexion->prepare("SELECT d.cantidad, d.subtotal, p.descripcion FROM detalle_ventas d LEFT JOIN productos p ON d.id_producto = p.id WHERE d.id_venta = ?");
            $stmtD->execute([$idVenta]);
            $items = $stmtD->fetchAll(PDO::FETCH_ASSOC);
            $l['rich_data'] = ['tipo' => 'venta', 'cabecera' => $ventaInfo, 'items' => $items, 'id_real' => $idVenta];
        }
    }
}
unset($l);

$hoy = date('Y-m-d');
$movs_hoy = $conexion->query("SELECT COUNT(*) FROM auditoria WHERE DATE(fecha) = '$hoy'")->fetchColumn();
$crit_hoy = $conexion->query("SELECT COUNT(*) FROM auditoria WHERE DATE(fecha) = '$hoy' AND (accion LIKE '%ELIMIN%' OR accion LIKE '%BAJA%' OR accion LIKE '%INFLACION%')")->fetchColumn();
$usuarios_filtro = $conexion->query("SELECT id, usuario FROM usuarios ORDER BY usuario ASC")->fetchAll(PDO::FETCH_ASSOC);

function getIconoReal($accion) {
    $a = strtoupper($accion);
    if(strpos($a, 'VENTA') !== false) return '<i class="bi bi-cart-check-fill text-success"></i>';
    if(strpos($a, 'GASTO') !== false || strpos($a, 'EGRESO') !== false) return '<i class="bi bi-cash-stack text-danger"></i>';
    if(strpos($a, 'PRODUCTO') !== false || strpos($a, 'CANJE') !== false) return '<i class="bi bi-box-seam text-primary"></i>';
    if(strpos($a, 'ELIMINAR') !== false || strpos($a, 'BAJA') !== false) return '<i class="bi bi-trash3-fill text-danger"></i>';
    return '<i class="bi bi-info-circle text-muted"></i>';
}
?>

<?php include 'includes/layout_header.php'; ?>

<div class="header-blue" style="background: <?php echo $color_sistema; ?> !important; border-radius: 0 !important; width: 100vw; margin-left: calc(-50vw + 50%); padding: 40px 0; position: relative; overflow: hidden; z-index: 10;">
    <i class="bi bi-shield-lock bg-icon-large" style="z-index: 0;"></i>
    <div class="container position-relative" style="z-index: 2;">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="font-cancha mb-0 text-white">Auditoría del Sistema</h2>
                <p class="opacity-75 mb-0 text-white small">Caja Negra: Registro integral de movimientos y trazabilidad.</p>
            </div>
            <a href="reporte_auditoria.php?desde=<?php echo $desde; ?>&hasta=<?php echo $hasta; ?>&f_user=<?php echo $f_user; ?>&f_accion=<?php echo $f_accion; ?>" target="_blank" class="btn btn-danger fw-bold rounded-pill px-4 shadow-sm">
                <i class="bi bi-file-earmark-pdf-fill me-2"></i> REPORTE PDF
            </a>
        </div>

        <div class="row g-3">
            <div class="col-12 col-md-4">
                <div class="header-widget">
                    <div><div class="widget-label">Movimientos Hoy</div><div class="widget-value text-white"><?php echo $movs_hoy; ?></div></div>
                    <div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-activity"></i></div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="header-widget">
                    <div><div class="widget-label">Críticos Hoy</div><div class="widget-value text-danger" style="font-weight: 800;"><?php echo $crit_hoy; ?></div></div>
                    <div class="icon-box bg-danger bg-opacity-20 text-white"><i class="bi bi-exclamation-triangle-fill"></i></div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="header-widget border-info">
                    <div><div class="widget-label">Resultados Filtro</div><div class="widget-value text-white"><?php echo number_format($total_regs, 0, '', '.'); ?></div></div>
                    <div class="icon-box bg-info bg-opacity-20 text-white"><i class="bi bi-funnel-fill"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5 mt-4">
    <div class="card card-custom border-0 shadow-sm mb-4">
        <div class="card-body p-3">
            <form method="GET" id="formAudit" class="row g-2 align-items-end">
                <div class="col-md-2"><label class="small fw-bold text-muted">Desde</label><input type="date" name="desde" class="form-control form-control-sm" value="<?php echo $desde; ?>" required></div>
                <div class="col-md-2"><label class="small fw-bold text-muted">Hasta</label><input type="date" name="hasta" class="form-control form-control-sm" value="<?php echo $hasta; ?>" required></div>
                <div class="col-md-2">
                    <label class="small fw-bold text-muted">Usuario</label>
                    <select name="f_user" class="form-select form-select-sm">
                        <option value="">Todos los Usuarios</option>
                        <?php foreach($usuarios_filtro as $uf): ?>
                            <option value="<?php echo $uf['id']; ?>" <?php echo ($f_user == $uf['id'])?'selected':''; ?>><?php echo $uf['usuario']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="small fw-bold text-muted">Acción (Filtro Rápido)</label>
                    <div class="input-group input-group-sm">
                        <input type="text" name="f_accion" id="inputAccion" class="form-control" placeholder="Buscador..." value="<?php echo $f_accion; ?>">
                        <button class="btn btn-dark fw-bold" type="button" data-bs-toggle="modal" data-bs-target="#modalFiltroRapido">RÁPIDO</button>
                    </div>
                </div>
                <div class="col-md-2"><button type="submit" class="btn btn-primary btn-sm w-100 fw-bold rounded-pill py-2">FILTRAR</button></div>
            </form>
        </div>
    </div>

    <div class="card card-custom border-0 shadow-sm overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 text-center" style="font-size: 0.85rem;">
                <thead class="bg-light text-muted small text-uppercase">
                    <tr><th class="ps-4">Fecha/Hora</th><th>Usuario</th><th>Acción</th><th class="text-start">Resumen</th><th class="pe-4 text-end">Ticket</th></tr>
                </thead>
                <tbody>
                    <?php if(empty($logs)): ?>
                        <tr><td colspan="5" class="py-5 text-muted">No se encontraron registros.</td></tr>
                    <?php endif; ?>
                    <?php foreach($logs as $log): ?>
                    <tr style="cursor:pointer" onclick="verTicketAuditoria(<?php echo htmlspecialchars(json_encode($log), ENT_QUOTES, 'UTF-8'); ?>)">
                        <td class="ps-4 fw-bold"><?php echo date('d/m H:i', strtotime($log['fecha'])); ?></td>
                        <td><span class="badge bg-light text-dark border">@<?php echo $log['usuario']; ?></span></td>
                        <td class="fw-bold"><?php echo getIconoReal($log['accion']); ?> <?php echo strtoupper($log['accion']); ?></td>
                        <td class="text-muted small text-start"><?php echo htmlspecialchars(substr($log['detalles'], 0, 85)); ?>...</td>
                        <td class="pe-4 text-end"><button type="button" class="btn btn-sm btn-outline-dark border-0 rounded-pill"><i class="bi bi-receipt fs-5"></i></button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalFiltroRapido" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 py-3 bg-dark text-white">
                <h6 class="modal-title fw-bold"><i class="bi bi-funnel-fill me-2"></i>Centro de Control de Auditoría</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-4">
                    <label class="small fw-bold text-muted text-uppercase mb-2 d-block">Gestión Comercial</label>
                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-outline-success btn-sm fw-bold" onclick="pegarYBuscar('VENTA')">Ventas</button>
                        <button class="btn btn-outline-info btn-sm fw-bold" onclick="pegarYBuscar('DEVOLUCION')">Devoluciones</button>
                        <button class="btn btn-outline-warning btn-sm fw-bold text-dark" onclick="pegarYBuscar('CUPON')">Cupones</button>
                        <button class="btn btn-outline-primary btn-sm fw-bold" onclick="pegarYBuscar('CANJE')">Canje Puntos</button>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="small fw-bold text-muted text-uppercase mb-2 d-block">Control de Inventario</label>
                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-outline-primary btn-sm fw-bold" onclick="pegarYBuscar('REPOSICION')">Reposición</button>
                        <button class="btn btn-outline-danger btn-sm fw-bold" onclick="pegarYBuscar('MERMA')">Mermas/Bajas</button>
                        <button class="btn btn-outline-dark btn-sm fw-bold" onclick="pegarYBuscar('AJUSTE')">Ajustes Manuales</button>
                        <button class="btn btn-outline-secondary btn-sm fw-bold" onclick="pegarYBuscar('INFLACION')">Aumentos Masivos</button>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="small fw-bold text-muted text-uppercase mb-2 d-block">Caja y Finanzas</label>
                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-outline-success btn-sm fw-bold" onclick="pegarYBuscar('APERTURA')">Aperturas</button>
                        <button class="btn btn-outline-danger btn-sm fw-bold" onclick="pegarYBuscar('CIERRE')">Cierres</button>
                        <button class="btn btn-outline-warning btn-sm fw-bold text-dark" onclick="pegarYBuscar('GASTO')">Gastos</button>
                        <button class="btn btn-outline-info btn-sm fw-bold" onclick="pegarYBuscar('PAGO')">Pagos Deuda</button>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="small fw-bold text-muted text-uppercase mb-2 d-block">Seguridad y Sistema</label>
                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-outline-dark btn-sm fw-bold" onclick="pegarYBuscar('LOGIN')">Ingresos</button>
                        <button class="btn btn-outline-secondary btn-sm fw-bold" onclick="pegarYBuscar('LOGOUT')">Salidas</button>
                        <button class="btn btn-outline-danger btn-sm fw-bold" onclick="pegarYBuscar('ELIMIN')">Eliminaciones</button>
                        <button class="btn btn-outline-info btn-sm fw-bold" onclick="pegarYBuscar('ESTADO')">Estados (Act/Des)</button>
                        <button class="btn btn-outline-primary btn-sm fw-bold" onclick="pegarYBuscar('CONFIG')">Configuración</button>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light border-0">
                <button class="btn btn-secondary fw-bold w-100" onclick="pegarYBuscar('')">LIMPIAR TODOS LOS FILTROS</button>
            </div>
        </div>
    </div>
</div>

<script>
    const miLocal = <?php echo json_encode($conf); ?>;
    const miFirma = "<?php echo file_exists($ruta_firma) ? $ruta_firma : ''; ?>";

    function pegarYBuscar(val) {
        document.getElementById('inputAccion').value = val;
        bootstrap.Modal.getInstance(document.getElementById('modalFiltroRapido')).hide();
        document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
        document.body.classList.remove('modal-open');
        setTimeout(() => { document.getElementById('formAudit').submit(); }, 150);
    }

    function verTicketAuditoria(log) {
        let fechaObj = new Date(log.fecha);
        let fechaF = fechaObj.toLocaleString('es-AR', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit', year: 'numeric' });

        let contenidoCentral = '';
        let pieTicket = 'REGISTRO OFICIAL DE SISTEMA';
        let iconHeader = 'bi-shield-check';
        let colorHeader = '#102A57';
        
        let nombreFirma = log.nombre_completo ? log.nombre_completo.toUpperCase() : 'FIRMA AUTORIZADA';
        // AQUÍ ESTÁ EL ANTI-CACHÉ DE LA FIRMA PARA EL TICKET DE AUDITORÍA
        let firmaHtml = miFirma ? `<img src="${miFirma}?v=${Date.now()}" style="max-height: 50px;"><br><div style="border-top:1px solid #000; width:100%; margin-top:5px;"></div><small style="font-size:9px; font-weight:bold;">${nombreFirma}</small>` : '';

        const accion = log.accion.toUpperCase();

        if (log.rich_data && log.rich_data.tipo === 'venta') {
            let v = log.rich_data.cabecera;
            let items = log.rich_data.items;
            let totalF = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(v.total);
            contenidoCentral = `
                <div class="mb-3 border-bottom pb-2">
                    <div class="d-flex justify-content-between small"><b>CLIENTE:</b> <span>${v.nombre_cliente ? v.nombre_cliente : 'CONSUMIDOR FINAL'}</span></div>
                    <div class="d-flex justify-content-between small"><b>PAGO:</b> <span>${v.metodo_pago.toUpperCase()}</span></div>
                </div>
                <div style="font-size: 11px;">
                    ${items.map(i => `<div class="d-flex justify-content-between border-bottom border-light py-1"><span>${parseFloat(i.cantidad)}x ${i.descripcion || 'ITEM ELIMINADO'}</span><span class="fw-bold">${new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(i.subtotal)}</span></div>`).join('')}
                </div>
                <div class="mt-3 p-2 bg-light rounded d-flex justify-content-between align-items-center"><span class="fw-bold">TOTAL VENTA:</span><span class="fs-5 fw-bold text-success">${totalF}</span></div>`;
            pieTicket = `COMPROBANTE DE VENTA #${log.rich_data.id_real}`;
            iconHeader = 'bi-cart-check';
        } else if (accion.includes('INFLACION')) {
            colorHeader = '#dc3545'; iconHeader = 'bi-graph-up-arrow';
            let matchInf = log.detalles.match(/del (.*?)% en (.*?) aplicado a (.*?) productos del grupo (.*?): (.*)/);
            if (matchInf) {
                contenidoCentral = `
                    <div class="alert alert-danger p-2 small mb-3">Se aplicó un aumento masivo por inflación.</div>
                    <table class="table table-sm small mb-0">
                        <tr><td><b>PORCENTAJE:</b></td> <td class="text-end text-danger fw-bold">${matchInf[1]}%</td></tr>
                        <tr><td><b>TIPO AJUSTE:</b></td> <td class="text-end">${matchInf[2]}</td></tr>
                        <tr><td><b>AFECTADOS:</b></td> <td class="text-end">${matchInf[3]} productos</td></tr>
                        <tr><td><b>GRUPO:</b></td> <td class="text-end">${matchInf[4]}</td></tr>
                        <tr><td><b>NOMBRE:</b></td> <td class="text-end fw-bold">${matchInf[5]}</td></tr>
                    </table>`;
            }
        } else if (accion.includes('CONFIG')) {
            iconHeader = 'bi-gear-wide-connected';
            let partes = log.detalles.split('|');
            contenidoCentral = `
                <div class="small fw-bold text-muted mb-3 text-center border-bottom pb-2 text-uppercase">Trazabilidad Total de Ajustes</div>
                <div class="list-group list-group-flush" style="max-height: 300px; overflow-y: auto;">
                    ${partes.map(p => {
                        if(p.includes('->')) {
                            let [titulo, valores] = p.split(':');
                            let [antes, despues] = valores.split('->');
                            return `<div class="list-group-item px-0 py-2 border-0 border-bottom border-light"><div class="text-muted mb-1" style="font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:0.5px;">${titulo.trim()}</div><div class="d-flex align-items-center gap-2"><span class="badge bg-light text-muted text-decoration-line-through fw-normal border">${antes.trim()}</span><i class="bi bi-arrow-right text-primary small"></i><span class="fw-bold text-dark" style="font-size:13px;">${despues.trim()}</span></div></div>`;
                        } else {
                            return `<div class="list-group-item px-0 py-2 small text-muted italic border-0">${p}</div>`;
                        }
                    }).join('')}
                </div>`;
        } else if (accion.includes('REPOSICION')) {
            iconHeader = 'bi-box-seam';
            let matchRep = log.detalles.match(/REPOSICIÓN: \+(.*?) unidades para '(.*?)'\. Proveedor: (.*?)(?: \| (.*))?$/);
            if (matchRep) {
                contenidoCentral = `
                    <div class="text-center mb-3"><div class="display-6 fw-bold text-primary">+${matchRep[1]}</div><div class="small fw-bold">${matchRep[2]}</div></div>
                    <div class="bg-light p-2 rounded small"><b>PROVEEDOR:</b> ${matchRep[3]}<br>${matchRep[4] ? `<b>INFO EXTRA:</b> ${matchRep[4]}` : ''}</div>`;
            }
        } else if (accion.includes('ESTADO')) {
            iconHeader = 'bi-toggle-on'; colorHeader = '#0dcaf0';
            let [prod, estado] = log.detalles.split('->');
            contenidoCentral = `<div class="text-center p-3 bg-light rounded border"><div class="small fw-bold text-muted text-uppercase mb-1">${prod ? prod.trim() : 'Item'}</div><div class="h5 mb-0 fw-bold ${estado && estado.includes('ACTIVADO') ? 'text-success' : 'text-danger'}">${estado ? estado.trim() : ''}</div></div>`;
            pieTicket = 'CONTROL DE VISIBILIDAD';
        } else {
            contenidoCentral = `<div class="p-3 bg-light rounded small" style="border-left: 4px solid ${colorHeader}; line-height: 1.6;">${log.detalles.replace(/\|/g, '<br>')}</div>`;
        }

        Swal.fire({
            html: `
                <div style="text-align: left; font-family: 'Inter', sans-serif;">
                    <div class="d-flex align-items-center mb-4 pb-3 border-bottom" style="gap: 15px;">
                        <div style="background: ${colorHeader}; color: white; width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px;"><i class="bi ${iconHeader}"></i></div>
                        <div><h5 class="mb-0 fw-bold" style="color: ${colorHeader};">${accion}</h5><small class="text-muted">${fechaF}</small></div>
                    </div>
                    ${contenidoCentral}
                    <div class="mt-4 pt-3 border-top text-center" style="display:flex; flex-direction:column; align-items:center;">
                        <div style="margin-bottom: 10px;">${firmaHtml}</div>
                        <span class="badge bg-dark mb-2">OPERADOR: ${log.usuario.toUpperCase()}</span>
                        <div class="text-muted" style="font-size: 10px; letter-spacing: 1px;">ID AUDITORÍA: #${log.id} <br> ${pieTicket}</div>
                    </div>
                </div>
            `,
            width: '450px', showConfirmButton: false, showCloseButton: true, customClass: { popup: 'rounded-4' }
        });
    }
</script>
<?php include 'includes/layout_footer.php'; ?>