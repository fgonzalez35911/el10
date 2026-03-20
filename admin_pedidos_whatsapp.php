<?php
require_once 'includes/layout_header.php';
require_once 'includes/db.php';

// 1. BLINDAJES Y PREPARACIÓN DE TABLAS
try { $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); } catch(Exception $e){}
try { $conexion->exec("ALTER TABLE pedidos_whatsapp MODIFY COLUMN estado VARCHAR(30) DEFAULT 'pendiente'"); } catch(Exception $e){}
try { $conexion->exec("ALTER TABLE pedidos_whatsapp ADD COLUMN IF NOT EXISTS motivo_cancelacion VARCHAR(255) NULL"); } catch(Exception $e){}
try { $conexion->exec("UPDATE productos SET stock_reservado = 0 WHERE stock_reservado IS NULL"); } catch(Exception $e){}

// 2. LÓGICA DE PROCESAMIENTO (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    $id = intval($_POST['id_pedido']);
    $accion = $_POST['accion'];
    $motivo = $_POST['motivo'] ?? '';
    $fecha_retiro = $_POST['fecha_retiro'] ?? '';

    $stmt = $conexion->prepare("SELECT p.*, c.email as cliente_email FROM pedidos_whatsapp p LEFT JOIN clientes c ON p.id_cliente = c.id WHERE p.id = ?");
    $stmt->execute([$id]);
    $pedido = $stmt->fetch(PDO::FETCH_OBJ);

    if ($pedido) {
        $id_us = $_SESSION['usuario_id'] ?? 1;
        $c_rubro = $conexion->query("SELECT tipo_negocio FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
        $rubro = $c_rubro['tipo_negocio'] ?? 'kiosco';
        $fecha_php = date('Y-m-d H:i:s'); 

        if ($pedido->estado === 'pendiente') {
            if ($accion === 'aprobar') {
                $conexion->beginTransaction();
                try {
                    $detalles = $conexion->prepare("SELECT * FROM pedidos_whatsapp_detalle WHERE id_pedido = ?");
                    $detalles->execute([$id]);
                    while ($item = $detalles->fetch(PDO::FETCH_OBJ)) {
                        $upd = $conexion->prepare("UPDATE productos SET stock_actual = COALESCE(stock_actual,0) - ?, stock_reservado = COALESCE(stock_reservado,0) - ? WHERE id = ?");
                        $upd->execute([$item->cantidad, $item->cantidad, $item->id_producto]);
                    }
                    $conexion->prepare("UPDATE pedidos_whatsapp SET estado = 'aprobado', fecha_retiro = ? WHERE id = ?")->execute([$fecha_retiro, $id]);
                    $conexion->commit();
                    echo "<script>location.href='acciones/enviar_email_pedido.php?id=$id&status=aprobado';</script>";
                    exit;
                } catch (Exception $e) { 
                    $conexion->rollBack(); 
                    die("Error al Aprobar: " . $e->getMessage());
                }
            } else if ($accion === 'rechazar') { // CULPA DEL LOCAL
                $conexion->beginTransaction();
                try {
                    $detalles = $conexion->prepare("SELECT * FROM pedidos_whatsapp_detalle WHERE id_pedido = ?");
                    $detalles->execute([$id]);
                    while ($item = $detalles->fetch(PDO::FETCH_OBJ)) {
                        $conexion->prepare("UPDATE productos SET stock_reservado = COALESCE(stock_reservado,0) - ? WHERE id = ?")->execute([$item->cantidad, $item->id_producto]);
                    }
                    $conexion->prepare("UPDATE pedidos_whatsapp SET estado = 'rechazado', motivo_cancelacion = ? WHERE id = ?")->execute([$motivo, $id]);
                    $conexion->commit();
                    echo "<script>location.href='acciones/enviar_email_pedido.php?id=$id&status=rechazado';</script>";
                    exit;
                } catch (Exception $e) { $conexion->rollBack(); }
            }
        } else if ($pedido->estado === 'aprobado') {
            if ($accion === 'entregado') {
                $conexion->beginTransaction();
                try {
                    $conexion->prepare("UPDATE pedidos_whatsapp SET estado = 'entregado' WHERE id = ?")->execute([$id]);
                    
                    $stmtC = $conexion->prepare("SELECT id FROM cajas_sesion WHERE id_usuario = ? AND estado = 'abierta' ORDER BY id DESC LIMIT 1");
                    $stmtC->execute([$id_us]);
                    $caja_abierta = $stmtC->fetchColumn();
                    if(!$caja_abierta) {
                        $caja_abierta = $conexion->query("SELECT id FROM cajas_sesion WHERE estado = 'abierta' ORDER BY id DESC LIMIT 1")->fetchColumn();
                    }
                    $caja_val = $caja_abierta ? $caja_abierta : null;
                    $id_cli = !empty($pedido->id_cliente) ? $pedido->id_cliente : 1;
                    
                    $stmtV = $conexion->prepare("INSERT INTO ventas (id_caja_sesion, id_usuario, id_cliente, fecha, total, metodo_pago, estado, tipo_negocio) VALUES (?, ?, ?, ?, ?, 'Efectivo', 'completada', ?)");
                    $stmtV->execute([$caja_val, $id_us, $id_cli, $fecha_php, $pedido->total, $rubro]);
                    $id_venta = $conexion->lastInsertId();
                    
                    $detalles = $conexion->prepare("SELECT d.*, p.precio_costo FROM pedidos_whatsapp_detalle d JOIN productos p ON d.id_producto = p.id WHERE d.id_pedido = ?");
                    $detalles->execute([$id]);
                    $insDet = $conexion->prepare("INSERT INTO detalle_ventas (id_venta, id_producto, cantidad, precio_historico, costo_historico, subtotal, tipo_negocio) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    
                    $ganancia_tot = 0;
                    while ($d = $detalles->fetch(PDO::FETCH_OBJ)) {
                        $costo = floatval($d->precio_costo ?? 0);
                        $ganancia_item = ($d->precio_unitario - $costo) * $d->cantidad;
                        $ganancia_tot += $ganancia_item;
                        $insDet->execute([$id_venta, $d->id_producto, $d->cantidad, $d->precio_unitario, $costo, $d->subtotal, $rubro]);
                    }
                    
                    try { $conexion->exec("UPDATE ventas SET ganancia = $ganancia_tot WHERE id = $id_venta"); } catch(Exception $e){}
                    
                    $conexion->commit();
                    echo "<script>location.href='admin_pedidos_whatsapp.php?msg=EstadoActualizado';</script>";
                    exit;
                } catch (Exception $e) { 
                    $conexion->rollBack(); 
                    die("Error al Entregar: " . $e->getMessage());
                }
            } else if ($accion === 'extender') {
                $conexion->prepare("UPDATE pedidos_whatsapp SET fecha_retiro = ? WHERE id = ?")->execute([$fecha_retiro, $id]);
                echo "<script>location.href='admin_pedidos_whatsapp.php?msg=EstadoActualizado';</script>";
                exit;
            } else if ($accion === 'liberar') { // CULPA DEL CLIENTE
                $conexion->beginTransaction();
                try {
                    $detalles = $conexion->prepare("SELECT * FROM pedidos_whatsapp_detalle WHERE id_pedido = ?");
                    $detalles->execute([$id]);
                    while ($item = $detalles->fetch(PDO::FETCH_OBJ)) {
                        $conexion->prepare("UPDATE productos SET stock_actual = COALESCE(stock_actual,0) + ? WHERE id = ?")->execute([$item->cantidad, $item->id_producto]);
                    }
                    $conexion->prepare("UPDATE pedidos_whatsapp SET estado = 'no_retirado', motivo_cancelacion = ? WHERE id = ?")->execute([$motivo, $id]);
                    $conexion->commit();
                    echo "<script>location.href='acciones/enviar_email_pedido.php?id=$id&status=no_retirado';</script>";
                    exit;
                } catch (Exception $e) { $conexion->rollBack(); }
            }
        }
    }
}

$pedidos = $conexion->query("SELECT * FROM pedidos_whatsapp ORDER BY fecha_pedido DESC")->fetchAll(PDO::FETCH_OBJ);
?>

<style>
    /* AJUSTES RESPONSIVOS Y DISEÑO PREMIUM */
    .table-pedidos th { background-color: #f4f6f9; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #dee2e6; }
    .table-pedidos td { vertical-align: middle; padding: 12px 15px; font-size: 0.95rem; border-bottom: 1px solid #f0f0f0; }
    .table-pedidos tbody tr:hover { background-color: #f8f9fa; }
    .badge { font-weight: 600; padding: 0.4em 0.6em; border-radius: 6px; }
    .btn-acciones { padding: 0.25rem 0.5rem; font-size: 0.875rem; border-radius: 6px; }
    
    /* Arreglo de textos gigantes en modales */
    .swal2-html-container table { font-size: 0.9rem !important; }
    .swal2-html-container td, .swal2-html-container th { padding: 8px !important; white-space: nowrap; }
    
    /* Mostrar pista visual solo en celulares */
    .scroll-hint { display: none; }
    @media (max-width: 768px) {
        .scroll-hint { display: block; font-size: 0.8rem; color: #6c757d; font-weight: 600; text-align: right; margin-bottom: 8px; animation: pulse 2s infinite; }
        .table-pedidos td, .table-pedidos th { padding: 10px; }
    }
    @keyframes pulse { 0% { opacity: 0.6; } 50% { opacity: 1; transform: translateX(3px); } 100% { opacity: 0.6; } }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-end mb-3">
        <h2 class="font-cancha m-0"><i class="bi bi-envelope-paper text-primary"></i> Gestión de Pedidos Web</h2>
    </div>
    
    <div class="scroll-hint"><i class="bi bi-hand-index-thumb"></i> Deslizá la tabla a la derecha 👉</div>
    
    <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
        <div class="card-body p-0 table-responsive">
            <table class="table table-pedidos align-middle mb-0 text-nowrap">
                <thead>
                    <tr>
                        <th>ID</th><th>Fecha</th><th>Cliente</th><th>Total</th><th>Estado</th><th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($pedidos as $p): ?>
                    <tr>
                        <td class="text-muted fw-bold">#<?php echo $p->id; ?></td>
                        <td>
                            <div class="fw-bold"><?php echo date('d/m/Y', strtotime($p->fecha_pedido)); ?></div>
                            <small class="text-muted"><?php echo date('H:i', strtotime($p->fecha_pedido)); ?> hs</small>
                        </td>
                        <td>
                            <div class="d-flex align-items-center">
                                <div>
                                    <div class="fw-bold text-dark"><?php echo htmlspecialchars($p->nombre_cliente); ?></div>
                                    <small class="text-muted"><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($p->email_cliente ?? ''); ?></small>
                                </div>
                                <button class="btn btn-link btn-sm p-0 ms-2 text-primary" onclick="verHistorial('<?php echo htmlspecialchars($p->email_cliente ?? ''); ?>')"><i class="bi bi-clock-history fs-5"></i></button>
                            </div>
                        </td>
                        <td class="fw-bold text-success fs-5">$<?php echo number_format($p->total, 2); ?></td>
                        <td>
                            <?php
                                $badge_class = 'bg-secondary';
                                if($p->estado == 'pendiente') $badge_class = 'bg-warning text-dark';
                                if($p->estado == 'aprobado') $badge_class = 'bg-primary';
                                if($p->estado == 'entregado') $badge_class = 'bg-success';
                                if($p->estado == 'rechazado') $badge_class = 'bg-danger';
                                if($p->estado == 'no_retirado') $badge_class = 'bg-dark';
                            ?>
                            <span class="badge <?php echo $badge_class; ?>"><?php echo strtoupper($p->estado); ?></span>
                        </td>
                        <td class="text-center">
                            <button class="btn btn-acciones btn-outline-dark me-1" onclick="verDetalle(<?php echo $p->id; ?>)"><i class="bi bi-eye"></i></button>
                            
                            <?php if($p->estado == 'pendiente'): ?>
                                <button class="btn btn-acciones btn-success me-1" onclick="procesar(<?php echo $p->id; ?>, 'aprobar')" title="Aprobar"><i class="bi bi-check-lg"></i></button>
                                <button class="btn btn-acciones btn-danger" onclick="procesar(<?php echo $p->id; ?>, 'rechazar')" title="Rechazar"><i class="bi bi-x-lg"></i></button>
                            <?php endif; ?>

                            <?php if($p->estado == 'aprobado'): ?>
                                <button class="btn btn-acciones btn-success me-1" onclick="procesarAprobado(<?php echo $p->id; ?>, 'entregado')" title="Entregado"><i class="bi bi-bag-check-fill"></i></button>
                                <button class="btn btn-acciones btn-warning text-dark me-1" onclick="procesarAprobado(<?php echo $p->id; ?>, 'extender')" title="Extender Plazo"><i class="bi bi-clock-history"></i></button>
                                <button class="btn btn-acciones btn-danger" onclick="procesarAprobado(<?php echo $p->id; ?>, 'liberar')" title="Cancelar"><i class="bi bi-arrow-counterclockwise"></i></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function procesar(id, accion) {
    let titulo = accion === 'aprobar' ? '¿Aprobar Pedido?' : '❌ Rechazar Pedido (Problema del Local)';
    let html = '';
    
    if(accion === 'aprobar') {
        html = '<div class="text-start"><label class="mb-1 text-muted small fw-bold">Fecha/Hora de Retiro:</label><input type="datetime-local" id="f_retiro" class="form-control"></div>';
    } else {
        html = `<div class="text-start">
                    <label class="fw-bold mb-1 text-muted small">Motivo de rechazo:</label>
                    <select id="motivo_canc" class="form-select mb-2">
                        <option value="Falta de stock físico">Falta de stock físico</option>
                        <option value="Precios desactualizados">Precios desactualizados</option>
                        <option value="No podemos prepararlo a tiempo">No podemos prepararlo a tiempo</option>
                    </select>
                    <small class="text-muted d-block mt-2"><i class="bi bi-info-circle"></i> Se le informará al cliente por correo.</small>
                </div>`;
    }

    Swal.fire({
        title: titulo,
        html: html,
        icon: accion === 'aprobar' ? 'info' : 'warning',
        showCancelButton: true,
        confirmButtonText: 'Confirmar',
        confirmButtonColor: '#102A57',
        preConfirm: () => {
            if(accion === 'aprobar') {
                const f = document.getElementById('f_retiro').value;
                if(!f) return Swal.showValidationMessage('Elegí una fecha de retiro');
                return { fecha: f, motivo: '' };
            } else {
                return { fecha: '', motivo: document.getElementById('motivo_canc').value };
            }
        }
    }).then((result) => {
        if (result.isConfirmed) {
            enviarFormulario(id, accion, result.value.fecha, result.value.motivo);
        }
    });
}

function procesarAprobado(id, accion) {
    let titulo = '';
    let texto = '';
    let html = '';
    
    if (accion === 'entregado') {
        titulo = '¿Marcar como Entregado?';
        texto = 'El pedido pasará al historial de ventas. El dinero ingresará a caja.';
    } else if (accion === 'liberar') {
        titulo = '🚫 Cancelar Reserva (Culpa del Cliente)';
        html = `<div class="text-start">
                    <label class="fw-bold mb-1 text-muted small">Motivo:</label>
                    <select id="motivo_canc_lib" class="form-select mb-2">
                        <option value="El cliente no pasó a retirar el pedido">El cliente no pasó a retirar el pedido</option>
                        <option value="El cliente avisó que ya no lo quiere">El cliente avisó que ya no lo quiere</option>
                    </select>
                </div>`;
    } else if (accion === 'extender') {
        titulo = 'Alargar Plazo';
        html = '<div class="text-start"><label class="fw-bold mb-1 text-muted small">Nueva fecha límite:</label><input type="datetime-local" id="f_retiro_ext" class="form-control"></div>';
    }

    Swal.fire({
        title: titulo,
        text: texto,
        html: html,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Confirmar',
        confirmButtonColor: '#102A57',
        preConfirm: () => {
            if(accion === 'extender') {
                const f = document.getElementById('f_retiro_ext').value;
                if(!f) return Swal.showValidationMessage('Elegí una nueva fecha');
                return { fecha: f, motivo: '' };
            } else if (accion === 'liberar') {
                return { fecha: '', motivo: document.getElementById('motivo_canc_lib').value };
            }
            return { fecha: '', motivo: '' };
        }
    }).then((result) => {
        if (result.isConfirmed) {
            enviarFormulario(id, accion, result.value.fecha, result.value.motivo);
        }
    });
}

function enviarFormulario(id, accion, fecha, motivo) {
    let f = document.createElement('form');
    f.method = 'POST';
    f.innerHTML = `<input type="hidden" name="id_pedido" value="${id}">
                   <input type="hidden" name="accion" value="${accion}">
                   <input type="hidden" name="fecha_retiro" value="${fecha}">
                   <input type="hidden" name="motivo" value="${motivo}">`;
    document.body.appendChild(f);
    f.submit();
}

function verDetalle(id) {
    Swal.fire({
        title: 'Detalle del Pedido #' + id,
        html: '<div id="detalle-contenido" class="text-center p-2"><div class="spinner-border text-primary"></div></div>',
        width: '600px',
        showCloseButton: true,
        confirmButtonText: 'Cerrar',
        confirmButtonColor: '#6c757d',
        didOpen: () => {
            fetch('ajax_pedido_detalle.php?id=' + id)
                .then(r => r.text())
                .then(h => document.getElementById('detalle-contenido').innerHTML = h);
        }
    });
}

function verHistorial(email) {
    if(!email) return Swal.fire('Error', 'No hay correo registrado.', 'error');
    Swal.fire({
        title: 'Historial del Cliente',
        html: '<div id="historial-contenido" class="text-center"><div class="spinner-border text-primary"></div></div>',
        width: '500px',
        showCloseButton: true,
        confirmButtonText: 'Cerrar',
        confirmButtonColor: '#6c757d',
        didOpen: () => {
            fetch('ajax_historial_cliente_wa.php?email=' + encodeURIComponent(email))
                .then(r => r.text())
                .then(h => document.getElementById('historial-contenido').innerHTML = h);
        }
    });
}
</script>

<?php if (isset($_GET['msg'])): ?>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        let msg = "<?php echo $_GET['msg']; ?>";
        if(msg === 'EmailEnviado') {
            Swal.fire({icon: 'success', title: 'Notificación Enviada', text: 'Se notificó al cliente: <?php echo $_GET['correo']; ?>'});
        } else if(msg === 'EstadoActualizado') {
            Swal.fire({icon: 'success', title: 'Operación Exitosa', text: 'El sistema fue actualizado correctamente.'});
        }
        window.history.replaceState({}, document.title, window.location.pathname);
    });
</script>
<?php endif; ?>
<?php require_once 'includes/layout_footer.php'; ?>
