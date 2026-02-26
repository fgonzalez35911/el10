<?php
// auditoria.php - VERSIÓN ESTANDARIZADA CON CANDADOS
session_start();
date_default_timezone_set('America/Argentina/Buenos_Aires');

$rutas_db = [__DIR__ . '/db.php', __DIR__ . '/includes/db.php', 'db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// --- CANDADOS DE SEGURIDAD ---
$permisos = $_SESSION['permisos'] ?? [];
$es_admin = (($_SESSION['rol'] ?? 3) <= 2);

// Candado: Acceso a la página
if (!$es_admin && !in_array('ver_auditoria', $permisos)) { 
    header("Location: dashboard.php"); exit; 
}

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
// PAGINACIÓN DE 10 EN 10
$pag = isset($_GET['pag']) ? (int)$_GET['pag'] : 1;
if ($pag < 1) $pag = 1;
$reg_x_pag = 10;
$total_paginas = ceil($total_regs / $reg_x_pag);
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
    if(strpos($a, 'SORTEO') !== false) return '<i class="bi bi-ticket-perforated-fill text-danger"></i>';
    if(strpos($a, 'PREMIO') !== false) return '<i class="bi bi-trophy-fill text-warning"></i>';
    if(strpos($a, 'CLIENTE') !== false) return '<i class="bi bi-person-heart text-info"></i>';
    if(strpos($a, 'PROVEEDOR') !== false) return '<i class="bi bi-truck text-success"></i>';
    if(strpos($a, 'USUARIO') !== false || strpos($a, 'ROL') !== false) return '<i class="bi bi-shield-lock-fill text-dark"></i>';
    if(strpos($a, 'BIEN') !== false || strpos($a, 'ACTIVO') !== false) return '<i class="bi bi-pc-display text-secondary"></i>';
    if(strpos($a, 'REVISTA') !== false) return '<i class="bi bi-book text-primary"></i>';
    if(strpos($a, 'CONFIG') !== false) return '<i class="bi bi-gear-fill text-dark"></i>';
    if(strpos($a, 'ESPERA') !== false || strpos($a, 'RECUPERADA') !== false) return '<i class="bi bi-pause-circle-fill text-secondary"></i>';
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
            <?php if($es_admin || in_array('reporte_auditoria', $permisos)): ?>
            <a href="reporte_auditoria.php?desde=<?php echo $desde; ?>&hasta=<?php echo $hasta; ?>&usuario=<?php echo $user_filter; ?>&accion=<?php echo $accion_filter; ?>" target="_blank" class="btn btn-danger fw-bold rounded-pill px-4 shadow-sm">
                <i class="bi bi-file-earmark-pdf-fill me-2"></i> REPORTE PDF
            </a>
            <?php endif; ?>
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
        
        <?php if ($total_paginas > 1): ?>
        <div class="card-footer bg-white border-top py-3">
            <nav aria-label="Navegación de registros">
                <ul class="pagination justify-content-center mb-0 pagination-sm">
                    <?php 
                    // Query string base para mantener los filtros al cambiar de página
                    $query_string = "&desde=$desde&hasta=$hasta&f_user=$f_user&f_accion=$f_accion";
                    
                    if ($pag > 1) echo '<li class="page-item"><a class="page-link" href="?pag='.($pag-1).$query_string.'">&laquo;</a></li>';
                    else echo '<li class="page-item disabled"><a class="page-link" href="#">&laquo;</a></li>';
                    
                    $inicio_rango = max(1, $pag - 2);
                    $fin_rango = min($total_paginas, $pag + 2);
                    
                    for ($i = $inicio_rango; $i <= $fin_rango; $i++) {
                        $activo = ($i == $pag) ? 'active' : '';
                        echo '<li class="page-item '.$activo.'"><a class="page-link" href="?pag='.$i.$query_string.'">'.$i.'</a></li>';
                    }
                    
                    if ($pag < $total_paginas) echo '<li class="page-item"><a class="page-link" href="?pag='.($pag+1).$query_string.'">&raquo;</a></li>';
                    else echo '<li class="page-item disabled"><a class="page-link" href="#">&raquo;</a></li>';
                    ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="modalFiltroRapido" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 py-3 bg-dark text-white">
                <h6 class="modal-title fw-bold"><i class="bi bi-funnel-fill me-2"></i>Centro de Control de Auditoría</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="mb-4">
                            <label class="small fw-bold text-muted text-uppercase mb-2 d-block">Gestión Comercial</label>
                            <div class="d-flex flex-wrap gap-2">
                                <button class="btn btn-outline-success btn-sm fw-bold" onclick="pegarYBuscar('VENTA')">Ventas</button>
                                <button class="btn btn-outline-secondary btn-sm fw-bold" onclick="pegarYBuscar('ESPERA')">En Espera</button>
<button class="btn btn-outline-success btn-sm fw-bold" onclick="pegarYBuscar('RECUPERADA')">Recuperadas</button>
                                <button class="btn btn-outline-info btn-sm fw-bold" onclick="pegarYBuscar('DEVOLUCION')">Devoluciones</button>
                                <button class="btn btn-outline-warning btn-sm fw-bold text-dark" onclick="pegarYBuscar('CUPON')">Cupones</button>
                                <button class="btn btn-outline-primary btn-sm fw-bold" onclick="pegarYBuscar('CANJE')">Canje Puntos</button>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="small fw-bold text-muted text-uppercase mb-2 d-block">Caja y Finanzas</label>
                            <div class="d-flex flex-wrap gap-2">
                                <button class="btn btn-outline-success btn-sm fw-bold" onclick="pegarYBuscar('APERTURA')">Aperturas</button>
                                <button class="btn btn-outline-danger btn-sm fw-bold" onclick="pegarYBuscar('CIERRE')">Cierres</button>
                                <button class="btn btn-outline-warning btn-sm fw-bold text-dark" onclick="pegarYBuscar('GASTO')">Gastos</button>
                                <button class="btn btn-outline-info btn-sm fw-bold" onclick="pegarYBuscar('PAGO')">Pagos / Deuda</button>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="small fw-bold text-muted text-uppercase mb-2 d-block">Marketing y Entidades</label>
                            <div class="d-flex flex-wrap gap-2">
                                <button class="btn btn-outline-danger btn-sm fw-bold" onclick="pegarYBuscar('SORTEO')">Sorteos</button>
                                <button class="btn btn-outline-warning btn-sm fw-bold text-dark" onclick="pegarYBuscar('PREMIO')">Premios</button>
                                <button class="btn btn-outline-primary btn-sm fw-bold" onclick="pegarYBuscar('REVISTA')">Revista</button>
                                <button class="btn btn-outline-info btn-sm fw-bold" onclick="pegarYBuscar('CLIENTE')">Clientes</button>
                                <button class="btn btn-outline-success btn-sm fw-bold" onclick="pegarYBuscar('PROVEEDOR')">Proveedores</button>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-4">
                            <label class="small fw-bold text-muted text-uppercase mb-2 d-block">Control de Inventario</label>
                            <div class="d-flex flex-wrap gap-2">
                                <button class="btn btn-outline-primary btn-sm fw-bold" onclick="pegarYBuscar('REPOSICION')">Reposición</button>
                                <button class="btn btn-outline-danger btn-sm fw-bold" onclick="pegarYBuscar('MERMA')">Mermas/Bajas</button>
                                <button class="btn btn-outline-dark btn-sm fw-bold" onclick="pegarYBuscar('AJUSTE')">Ajustes Manuales</button>
                                <button class="btn btn-outline-secondary btn-sm fw-bold" onclick="pegarYBuscar('INFLACION')">Aumentos Masivos</button>
                                <button class="btn btn-outline-info btn-sm fw-bold" onclick="pegarYBuscar('BIEN')">Bienes de Uso</button>
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
                                <button class="btn btn-outline-success btn-sm fw-bold" onclick="pegarYBuscar('USUARIO')">Usuarios</button>
                            </div>
                        </div>
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

        const accion = log.accion.toUpperCase();
        
        let colorHeader = '#102A57';
        if(accion.includes('ELIMIN') || accion.includes('BAJA')) colorHeader = '#dc3545';
        if(accion.includes('VENTA') || accion.includes('PAGO')) colorHeader = '#198754';
        if(accion.includes('INFLACION') || accion.includes('GASTO')) colorHeader = '#fd7e14';

        let nombreFirma = log.nombre_completo ? log.nombre_completo.toUpperCase() : 'FIRMA AUTORIZADA';
        
        // FIRMA DINÁMICA CON DOBLE ANTI-CACHÉ ABSOLUTO
        let ts = Date.now();
        let urlFirmaDinamica = `img/firmas/usuario_${log.id_usuario}.png`;
        let urlFirmaAdmin = `img/firmas/firma_admin.png`;
        
        let firmaHtml = `<div style="display:flex; flex-direction:column; align-items:center;">
                            <img src="${urlFirmaDinamica}?v=${ts}" onerror="this.onerror=null; this.src='${urlFirmaAdmin}?v=${ts}'" style="max-height: 55px; margin-bottom: -8px; position: relative; z-index: 10;">
                            <div style="border-top:1px solid #000; width:100%; position: relative; z-index: 1;"></div>
                            <small style="font-size:9px; font-weight:bold; margin-top: 3px;">${nombreFirma}</small>
                         </div>`;
        
        let logoHtml = miLocal.logo_url ? `<img src="${miLocal.logo_url}?v=${ts}" style="max-height: 50px; margin-bottom: 10px;">` : '';
        
        // LINK PÚBLICO Y CÓDIGO QR
        let linkPdfPublico = window.location.origin + window.location.pathname.replace('auditoria.php', '') + "ticket_auditoria_pdf.php?id=" + log.id;
        let qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=110x110&margin=2&data=` + encodeURIComponent(linkPdfPublico);

        let contenidoCentral = `<div style="font-size: 12px; line-height: 1.5;">${log.detalles.replace(/\|/g, '<br>')}</div>`;

        let ticketHTML = `
            <div id="printTicketAudit" style="font-family: 'Inter', sans-serif; text-align: left; color: #000; padding: 10px;">
                <div style="text-align: center; border-bottom: 2px dashed #ccc; padding-bottom: 15px; margin-bottom: 15px;">
                    ${logoHtml}
                    <h4 style="font-weight: 900; margin: 0; text-transform: uppercase;">${miLocal.nombre_negocio}</h4>
                    <small style="color: #666;">CUIT: ${miLocal.cuit || 'S/N'}<br>${miLocal.direccion_local}</small>
                </div>
                <div style="text-align: center; margin-bottom: 15px;">
                    <h5 style="font-weight: 900; color: ${colorHeader}; letter-spacing: 1px; margin:0; text-transform: uppercase;">REGISTRO DE SISTEMA</h5>
                    <span style="font-size: 10px; background: #eee; padding: 2px 6px; border-radius: 4px;">AUDIT #${log.id}</span>
                </div>
                <div style="background: #f8f9fa; border: 1px solid #eee; padding: 10px; border-radius: 8px; margin-bottom: 15px; font-size: 12px;">
                    <div style="margin-bottom: 4px;"><strong>FECHA:</strong> ${fechaF}</div>
                    <div style="margin-bottom: 4px;"><strong>ACCIÓN:</strong> <span style="color: ${colorHeader}; font-weight: bold;">${accion}</span></div>
                    <div><strong>OPERADOR:</strong> ${(log.usuario || 'ADMIN').toUpperCase()}</div>
                </div>
                <div style="margin-bottom: 15px;">
                    <strong style="border-bottom: 1px solid #ccc; display:block; margin-bottom:8px; font-size: 12px;">DETALLE DEL MOVIMIENTO:</strong>
                    ${contenidoCentral}
                </div>
                <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 20px; padding-top: 15px; border-top: 2px dashed #eee;">
                    <div style="width: 45%; text-align: center;">${firmaHtml}</div>
                    <div style="width: 45%; text-align: center;">
                        <a href="${linkPdfPublico}" target="_blank">
                            <img src="${qrUrl}" style="width: 75px; height: 75px; border: 1px solid #ddd; padding: 3px; border-radius: 5px;">
                        </a>
                        <div style="font-size: 8px; color: #999; margin-top: 3px; font-weight: bold;">ESCANEAR / CLICK</div>
                    </div>
                </div>
            </div>
            
            <div class="row g-2 mt-4 border-top pt-3 no-print">
                <div class="col-6">
                    <a href="${linkPdfPublico}" target="_blank" class="btn btn-light border text-primary fw-bold w-100 shadow-sm rounded-pill" style="font-size: 0.85rem; padding: 10px 0;">
                        <i class="bi bi-qr-code-scan me-1"></i> VER ONLINE
                    </a>
                </div>
                <div class="col-6">
                    <button class="btn btn-primary fw-bold w-100 shadow-sm rounded-pill" onclick="mandarMailAuditoria(${log.id})" style="font-size: 0.85rem; padding: 10px 0; background-color: #102A57; border: none;">
                        <i class="bi bi-envelope-paper me-1"></i> ENVIAR MAIL
                    </button>
                </div>
            </div>
        `;

        Swal.fire({ 
            html: ticketHTML, 
            width: 400, 
            showConfirmButton: false, 
            showCloseButton: true, 
            background: '#fff' 
        });
    }

    function mandarMailAuditoria(id_audit) {
        Swal.fire({
            title: 'Enviar Ticket', 
            text: 'Email de destino:', 
            input: 'email', 
            showCancelButton: true, 
            confirmButtonText: 'Enviar',
            confirmButtonColor: '#102A57'
        }).then((r) => {
            if(r.isConfirmed && r.value) {
                Swal.fire({ title: 'Enviando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); }});
                let fData = new FormData(); 
                fData.append('id', id_audit); 
                fData.append('email', r.value);
                
                // Ajusta la ruta a tu carpeta de acciones si es necesario
                fetch('acciones/enviar_email_auditoria.php', { method: 'POST', body: fData })
                .then(res => res.json()).then(d => {
                    if(d.status === 'success') Swal.fire('¡Éxito!', 'Correo enviado correctamente.', 'success');
                    else Swal.fire('Error', d.msg, 'error');
                }).catch(() => Swal.fire('Error', 'Hubo un problema de red.', 'error'));
            }
        });
    }

    function imprimirTicket() {
        let contenido = document.getElementById('printTicketAudit').innerHTML;
        let ventana = window.open('', '_blank', 'width=400,height=600');
        ventana.document.write(`
            <html>
                <head>
                    <title>Imprimir Ticket Auditoría</title>
                    <style>
                        body { font-family: 'Inter', sans-serif; margin: 0; padding: 10px; color: #000; background: #fff; }
                        * { box-sizing: border-box; }
                    </style>
                </head>
                <body onload="window.print(); window.close();">
                    ${contenido}
                </body>
            </html>
        `);
        ventana.document.close();
    }
</script>
<?php include 'includes/layout_footer.php'; ?>