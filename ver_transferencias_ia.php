<?php 
require_once 'includes/db.php'; 
if (isset($_POST['borrar_id'])) {
    $conexion->prepare("DELETE FROM transferencias WHERE id = ?")->execute([$_POST['borrar_id']]);
}
include 'layout_header.php'; 
?>
<style>
    .excel-table { width: 100%; border-collapse: collapse; font-family: sans-serif; background: white; border: 1px solid #ccc; }
    .excel-table th { background: #0056b3; color: white; padding: 10px; border: 1px solid #ccc; font-size: 11px; text-align: center; white-space: nowrap; }
    .excel-table td { padding: 10px; border: 1px solid #ccc; font-size: 12px; white-space: nowrap; vertical-align: middle; }
    .bg-e { background-color: #f1f8ff; } /* Color Emisor */
    .bg-r { background-color: #fff1f1; } /* Color Receptor */
    .monto-v { font-weight: bold; color: green; text-align: right; }
    .op-v { font-weight: bold; color: #0d6efd; text-align: center; }
</style>

<div class="container-fluid py-3">
    <h4 class="fw-bold mb-3"><i class="bi bi-grid-3x3 me-2"></i>Base de Datos - Transferencias (Vista Excel)</h4>
    <div class="table-responsive">
        <table class="excel-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>FECHA REG.</th>
                    <th>MONTO</th>
                    <th>OPERACIÓN</th>
                    <th class="bg-e">NOMBRE EMISOR</th>
                    <th class="bg-e">DNI/CUIT E.</th>
                    <th class="bg-e">CBU/CVU E.</th>
                    <th class="bg-r">NOMBRE RECEPTOR</th>
                    <th class="bg-r">DNI/CUIT R.</th>
                    <th class="bg-r">CBU/CVU R.</th>
                    <th>COMPROBANTE</th>
                    <th>ACCIONES</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $res = $conexion->query("SELECT * FROM transferencias ORDER BY id DESC");
                while ($row = $res->fetch(PDO::FETCH_ASSOC)): 
                    $d = json_decode($row['datos_json'], true);
                ?>
                <tr>
                    <td class="text-center"><?php echo $row['id']; ?></td>
                    <td class="text-center"><?php echo date('d/m/y H:i', strtotime($row['fecha_registro'])); ?></td>
                    <td class="monto-v">$<?php echo $row['monto']; ?></td>
                    <td class="op-v"><?php echo $d['op'] ?? '---'; ?></td>
                    
                    <td class="bg-e"><?php echo $d['nom_e'] ?? 'No detectado'; ?></td>
                    <td class="bg-e text-center"><?php echo $d['doc_e'] ?? '---'; ?></td>
                    <td class="bg-e text-muted"><small><?php echo $d['cbu_e'] ?? '---'; ?></small></td>
                    
                    <td class="bg-r"><?php echo $d['nom_r'] ?? 'No detectado'; ?></td>
                    <td class="bg-r text-center"><?php echo $d['doc_r'] ?? '---'; ?></td>
                    <td class="bg-r text-muted"><small><?php echo $d['cbu_r'] ?? '---'; ?></small></td>
                    
                    <td class="text-center">
                        <img src="<?php echo $row['imagen_base64']; ?>" style="height:35px; cursor:pointer;" onclick="Swal.fire({imageUrl: this.src, imageWidth: 400})">
                    </td>
                    <td class="text-center">
                        <form method="POST" onsubmit="return confirm('¿Eliminar registro?');">
                            <input type="hidden" name="borrar_id" value="<?php echo $row['id']; ?>">
                            <button type="submit" class="btn btn-danger btn-sm p-0 px-2">X</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'layout_footer.php'; ?>