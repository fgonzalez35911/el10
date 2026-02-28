<?php
// historial_transferencias.php - AUDITORÍA COMPLETA
session_start();
require_once 'includes/db.php';
if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

$sql = "SELECT * FROM comprobantes_transferencia ORDER BY fecha DESC LIMIT 100";
$comprobantes = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);

include 'includes/layout_header.php';
?>
<div class="container mt-4">
    <div class="card shadow border-0">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-bank2"></i> Auditoría de Transferencias</h5>
            <a href="ventas.php" class="btn btn-sm btn-outline-light">Volver a Ventas</a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Fecha</th>
                            <th>Monto</th>
                            <th>CUIT</th>
                            <th>CVU/CBU</th>
                            <th>Nº Op.</th>
                            <th>Estado</th>
                            <th>Foto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($comprobantes as $c): ?>
                        <tr>
                            <td><small><?php echo date('d/m/Y H:i', strtotime($c['fecha'])); ?></small></td>
                            <td class="fw-bold text-success">$<?php echo number_format($c['monto_esperado'], 2); ?></td>
                            <td class="small"><?php echo $c['cuit_cuil'] ?: '-'; ?></td>
                            <td class="small"><?php echo $c['cvu_cbu'] ?: '-'; ?></td>
                            <td class="small"><?php echo $c['numero_operacion'] ?: '-'; ?></td>
                            <td><span class="badge bg-info text-dark"><?php echo $c['estado']; ?></span></td>
                            <td><button class="btn btn-sm btn-primary" onclick="verFoto('<?php echo $c['imagen_ruta']; ?>')"><i class="bi bi-image"></i></button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script> function verFoto(r){ Swal.fire({ imageUrl: r, imageWidth: '100%', confirmButtonText: 'Cerrar' }); } </script>
<?php include 'includes/layout_footer.php'; ?>
