<?php
// Ni un solo espacio o salto de línea antes del <?php
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id'])) { 
    header("Location: index.php"); 
    exit; 
}
$es_admin = (($_SESSION['rol'] ?? 3) <= 2);

// ==========================================
// 1. MOTOR DE BORRADO (AJAX POST) - INTACTO
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['solicitud_borrar'])) {
    if (ob_get_length()) ob_clean(); 
    
    $ids = json_decode($_POST['ids_a_borrar'], true);
    if (!empty($ids) && is_array($ids)) {
        try {
            $interrogantes = implode(',', array_fill(0, count($ids), '?'));
            $sql = "DELETE FROM transferencias WHERE id IN ($interrogantes)";
            $stmt = $conexion->prepare($sql);
            $stmt->execute($ids);
            echo "EXITO";
        } catch (Exception $e) {
            echo "ERROR: " . $e->getMessage();
        }
    } else {
        echo "ERROR: Lista de IDs vacía.";
    }
    exit; 
}

// ==========================================
// 2. CARGA DE REGISTROS
// ==========================================
$buscar = $_GET['buscar'] ?? '';
$query = "SELECT * FROM transferencias";
if ($buscar) {
    $buscar_e = addslashes($buscar);
    $query .= " WHERE datos_json LIKE '%$buscar_e%' OR monto LIKE '%$buscar_e%'";
}
$query .= " ORDER BY id DESC";
$transferencias = $conexion->query($query)->fetchAll(PDO::FETCH_ASSOC);

include 'includes/layout_header.php'; 
?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    .tabla-simple { width: 100%; border-collapse: collapse; background: #fff; }
    .tabla-simple th, .tabla-simple td { border: 1px solid #ccc; padding: 10px; font-size: 13px; }
    .btn-rojo { background: #d9534f; color: white; border: none; padding: 5px 10px; cursor: pointer; border-radius: 3px; font-weight: bold; }
    .btn-rojo:hover { background: #c9302c; }
    .btn-azul { background: #0275d8; color: white; border: none; padding: 5px 10px; cursor: pointer; border-radius: 3px; font-weight: bold; }
    .btn-azul:hover { background: #025aa5; }
    .oculto { display: none !important; }

    /* ESTILOS DEL MODAL QUIRÚRGICO PARA LA FOTO */
    #modalImagen { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.85); }
    #modalImagen .cerrar { position: absolute; top: 15px; right: 35px; color: #f1f1f1; font-size: 40px; font-weight: bold; cursor: pointer; z-index: 10000; }
    #modalImagen .cerrar:hover { color: #d9534f; }
    #modalImagen .controles { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); display: flex; gap: 15px; z-index: 10000; background: rgba(0,0,0,0.6); padding: 10px 20px; border-radius: 10px; }
    .btn-zoom { background: #333; color: white; border: 1px solid #777; padding: 10px 20px; cursor: pointer; border-radius: 5px; font-size: 18px; font-weight: bold; }
    .btn-zoom:hover { background: #555; }
    .contenedor-img { width: 100%; min-height: 100%; display: flex; align-items: center; justify-content: center; overflow: visible; padding: 50px; }
    .imagen-contenido { max-width: 90%; max-height: 80vh; transition: transform 0.2s ease; box-shadow: 0 0 20px rgba(0,0,0,0.5); }
    .btn-nav-img { background: rgba(0,0,0,0.7); color: white; border: none; padding: 15px; cursor: pointer; font-size: 24px; font-weight: bold; position: absolute; top: 50%; transform: translateY(-50%); z-index: 10001; }
    .btn-nav-img:hover { background: rgba(0,0,0,0.9); }
    #btnPrevImg { left: 20px; }
    #btnNextImg { right: 20px; }
</style>

<div class="container-fluid py-4">
    
    <div style="background: #f4f4f4; padding: 15px; margin-bottom: 20px; border: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center;">
        <form method="GET" style="display: flex; gap: 10px; margin: 0;">
            <input type="text" name="buscar" placeholder="Buscar..." value="<?= htmlspecialchars($buscar) ?>" style="padding: 5px;">
            <button type="submit" style="cursor: pointer; padding: 5px 10px;">BUSCAR</button>
        </form>
        
        <button type="button" id="botonMasivo" class="btn-rojo oculto" onclick="borrarSeleccionados()">
            ELIMINAR MARCADOS (<span id="cuenta">0</span>)
        </button>
    </div>

    <table class="tabla-simple">
        <thead style="background: #333; color: #fff;">
            <tr>
                <th style="text-align: center;">
                    <input type="checkbox" id="checkPrincipal" onclick="alternarTodos(this)" style="transform: scale(1.5); cursor: pointer;">
                </th>
                <th>FECHA</th>
                <th>Nº OP</th>
                <th>MONTO</th>
                <th>EMISOR</th>
                <th>DNI/CUIT EMISOR</th>
                <th>CBU EMISOR</th>
                <th>BANCO ORIGEN</th>
                <th>RECEPTOR</th>
                <th>DNI/CUIT RECEPTOR</th>
                <th>CBU RECEPTOR</th>
                <th>COMPROBANTE</th> <th>ACCIONES</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($transferencias as $t): 
                $d = json_decode($t['datos_json'], true);
                
                // Extraemos variables normales por defecto
                $op = $d['op'] ?? $d['operacion'] ?? $d['nro_op'] ?? '-';
                
                $nom_emisor = $d['nom_e'] ?? '-';
                $doc_emisor = $d['doc_e'] ?? '-';
                $cbu_emisor = $d['cbu_e'] ?? '-';
                $banco      = $d['banco_e'] ?? '-';
                
                $nom_receptor = $d['nom_r'] ?? '-';
                $doc_receptor = $d['doc_r'] ?? '-';
                $cbu_receptor = $d['cbu_r'] ?? '-';

                $texto = $t['texto_completo'] ?? '';
                $ruta_foto_cruda = $t['imagen_base64'] ?? ''; 

                // PARCHE FOTOS: Extracción súper agresiva para múltiples imágenes
                $array_fotos_final = [];
                if (!empty($ruta_foto_cruda)) {
                    // Intentamos ver si es un JSON nativo
                    $es_json = json_decode($ruta_foto_cruda, true);
                    if (is_array($es_json)) {
                        foreach($es_json as $img) {
                            if(!empty(trim($img))) $array_fotos_final[] = trim($img);
                        }
                    } else {
                        // Si es texto, partimos por coma, punto y coma, o barra vertical
                        $array_temp = preg_split('/[,;|]/', $ruta_foto_cruda);
                        foreach($array_temp as $img) {
                            // Limpiamos comillas basura, corchetes o espacios que puedan romper el array
                            $img_limpia = trim(str_replace(['"', "'", '[', ']'], '', $img)); 
                            if(!empty($img_limpia)) $array_fotos_final[] = $img_limpia;
                        }
                    }
                }
                $json_fotos_btn = htmlspecialchars(json_encode($array_fotos_final), ENT_QUOTES, 'UTF-8');

                // ==========================================
                // PARCHE QUIRÚRGICO EXCLUSIVO PARA MODO
                // ==========================================
                if (stripos($texto, 'MODO') !== false || stripos($t['datos_json'] ?? '', 'MODO') !== false) {
                    
                    $doc_emisor = '-';
                    $doc_receptor = '-';
                    $cbu_emisor = '-'; 

                    if (preg_match('/Ref\.\s*([a-zA-Z0-9\-]+)/i', $texto, $m)) {
                        $op = trim($m[1]);
                    }
                    if (preg_match('/Transferencia de\s+(.*?)\s+Desde la cuenta/i', $texto, $m)) {
                        $nom_emisor = trim($m[1]);
                    }
                    if (preg_match('/Desde la cuenta\s+(.*?)\s*(?:•|\.|CA|CC|\d)/i', $texto, $m)) {
                        $banco = 'MODO / ' . trim($m[1]);
                    } else {
                        $banco = 'MODO';
                    }
                    if (preg_match('/Para\s+(.*?)\s+A su cuenta/i', $texto, $m)) {
                        $nom_receptor = trim($m[1]);
                    }
                    if (preg_match('/CBU\/CVU\s*(\d{22})/i', $texto, $m)) {
                        $cbu_receptor = trim($m[1]);
                    }
                } 
                // ==========================================
                // PARCHE ESPECÍFICO PARA BANCO NACIÓN
                // ==========================================
                elseif (stripos($banco, 'Nación') !== false || stripos($banco, 'BNA') !== false) {
                    if ($doc_emisor !== '-' && $doc_emisor !== '---') {
                        $doc_receptor = $doc_emisor; 
                        $doc_emisor = '-';           
                    }
                }
            ?>
            <tr id="fila-<?= $t['id'] ?>">
                <td style="text-align: center;">
                    <input type="checkbox" class="checkFila" value="<?= $t['id'] ?>" onclick="revisarChecks()" style="transform: scale(1.3); cursor: pointer;">
                </td>
                <td><?= date('d/m/y H:i', strtotime($t['fecha_registro'])) ?></td>
                
                <td style="font-family: monospace; font-weight: bold; font-size: 14px; text-align: center;"><?= htmlspecialchars($op) ?></td>
                <td style="color: green; font-weight: bold;">$<?= number_format((float)$t['monto'], 2, ',', '.') ?></td>
                
                <td><?= htmlspecialchars($nom_emisor) ?></td>
                <td style="font-family: monospace; font-size: 14px;"><?= htmlspecialchars($doc_emisor) ?></td>
                <td style="font-family: monospace; font-size: 14px;"><?= htmlspecialchars($cbu_emisor) ?></td>
                <td><?= htmlspecialchars($banco) ?></td>
                
                <td><?= htmlspecialchars($nom_receptor) ?></td>
                <td style="font-family: monospace; font-size: 14px;"><?= htmlspecialchars($doc_receptor) ?></td>
                <td style="font-family: monospace; font-size: 14px;"><?= htmlspecialchars($cbu_receptor) ?></td>
                
                <td style="text-align: center;">
                    <?php if (!empty($array_fotos_final)): ?>
                        <button type="button" class="btn-azul" onclick='abrirVisorMulti(<?= $json_fotos_btn ?>)'>VER</button>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>

                <td style="text-align: center;">
                    <?php if($es_admin): ?>
                        <button type="button" class="btn-rojo" onclick="borrarId(<?= $t['id'] ?>)">BORRAR</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            
            <?php if(empty($transferencias)): ?>
            <tr>
                <td colspan="13" style="text-align: center; padding: 20px;">No se encontraron registros.</td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div id="modalImagen">
    <span class="cerrar" onclick="cerrarVisor()">&times;</span>
    <button type="button" class="btn-nav-img" id="btnPrevImg" onclick="cambiarImagen(-1)" style="display:none;">&#10094;</button>
    <button type="button" class="btn-nav-img" id="btnNextImg" onclick="cambiarImagen(1)" style="display:none;">&#10095;</button>
    <div class="contenedor-img">
        <img class="imagen-contenido" id="imgAmpliacion">
    </div>
    <div class="controles">
        <span id="contadorImg" style="color: white; font-weight: bold; align-self: center; margin-right: 15px;"></span>
        <button type="button" class="btn-zoom" onclick="hacerZoom(0.2)">+</button>
        <button type="button" class="btn-zoom" onclick="hacerZoom(-0.2)">-</button>
        <button type="button" class="btn-zoom" onclick="resetZoom()">Restablecer</button>
    </div>
</div>

<script>
// ==========================================
// VISOR DE IMÁGENES CON ZOOM Y MÚLTIPLES FOTOS
// ==========================================
var nivelZoom = 1;
var imgAmpliacion = document.getElementById("imgAmpliacion");
var listaImagenesActual = [];
var indiceImagenActual = 0;

function abrirVisorMulti(listaImagenes) {
    if (!listaImagenes || listaImagenes.length === 0) return;
    
    listaImagenesActual = listaImagenes;
    indiceImagenActual = 0;
    
    document.getElementById("modalImagen").style.display = "block";
    mostrarImagenActual();
}

function mostrarImagenActual() {
    imgAmpliacion.src = listaImagenesActual[indiceImagenActual];
    resetZoom();
    
    // Actualizar contador
    var contadorText = "";
    if (listaImagenesActual.length > 1) {
        contadorText = (indiceImagenActual + 1) + " / " + listaImagenesActual.length;
        document.getElementById("btnPrevImg").style.display = "block";
        document.getElementById("btnNextImg").style.display = "block";
    } else {
        document.getElementById("btnPrevImg").style.display = "none";
        document.getElementById("btnNextImg").style.display = "none";
    }
    document.getElementById("contadorImg").innerText = contadorText;
}

function cambiarImagen(direccion) {
    indiceImagenActual += direccion;
    
    // Ciclar si llegamos al final o principio
    if (indiceImagenActual >= listaImagenesActual.length) {
        indiceImagenActual = 0;
    } else if (indiceImagenActual < 0) {
        indiceImagenActual = listaImagenesActual.length - 1;
    }
    
    mostrarImagenActual();
}

function cerrarVisor() {
    document.getElementById("modalImagen").style.display = "none";
    imgAmpliacion.src = "";
    listaImagenesActual = [];
}

function hacerZoom(cambio) {
    nivelZoom += cambio;
    if(nivelZoom < 0.2) nivelZoom = 0.2;
    if(nivelZoom > 3) nivelZoom = 3;
    aplicarZoom();
}

function resetZoom() {
    nivelZoom = 1;
    aplicarZoom();
}

function aplicarZoom() {
    imgAmpliacion.style.transform = "scale(" + nivelZoom + ")";
}

// Cerrar el modal tocando fuera de la imagen o apretando Escape
window.onclick = function(event) {
    var modal = document.getElementById('modalImagen');
    if (event.target == modal) {
        cerrarVisor();
    }
}
document.addEventListener('keydown', function(event){
    if(event.key === "Escape"){ cerrarVisor(); }
    // Flechas para cambiar imagen
    if(document.getElementById("modalImagen").style.display === "block" && listaImagenesActual.length > 1) {
        if(event.key === "ArrowRight") { cambiarImagen(1); }
        if(event.key === "ArrowLeft") { cambiarImagen(-1); }
    }
});
// ==========================================
// 3. FUNCIONES DE JAVASCRIPT REPARADAS (INTACTAS)
// ==========================================
function alternarTodos(maestro) {
    var cuadros = document.querySelectorAll('.checkFila');
    cuadros.forEach(function(c) {
        c.checked = maestro.checked;
    });
    revisarChecks();
}

function revisarChecks() {
    var marcados = document.querySelectorAll('.checkFila:checked').length;
    var btn = document.getElementById('botonMasivo');
    var span = document.getElementById('cuenta');
    
    if(span) span.innerHTML = marcados;
    
    if(btn) {
        if(marcados > 0) {
            btn.classList.remove('oculto');
        } else {
            btn.classList.add('oculto');
        }
    }
}

function borrarId(id) {
    procesarEnvio([id]);
}

function borrarSeleccionados() {
    var seleccionados = [];
    document.querySelectorAll('.checkFila:checked').forEach(function(c) {
        seleccionados.push(c.value);
    });
    if(seleccionados.length > 0) {
        procesarEnvio(seleccionados);
    } else {
        Swal.fire('Aviso', 'No marcaste ningún registro', 'info');
    }
}

function procesarEnvio(lista) {
    Swal.fire({
        title: '¿Confirmar borrado?',
        text: "Vas a eliminar " + lista.length + " registro(s).",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'SÍ, ELIMINAR',
        cancelButtonText: 'CANCELAR'
    }).then((result) => {
        if (result.isConfirmed) {
            
            var formData = new FormData();
            formData.append('solicitud_borrar', '1');
            formData.append('ids_a_borrar', JSON.stringify(lista));

            fetch(window.location.pathname, {
                method: 'POST',
                body: formData
            })
            .then(function(respuesta) {
                return respuesta.text();
            })
            .then(function(texto) {
                if(texto.includes("EXITO")) {
                    Swal.fire(
                        '¡Listo!',
                        'Eliminado correctamente.',
                        'success'
                    ).then(() => {
                        window.location.reload();
                    });
                } else {
                    console.log("Respuesta oculta del server:", texto);
                    Swal.fire('Error del servidor', 'La base de datos devolvió un error. Revisá la consola.', 'error');
                }
            })
            .catch(function(error) {
                Swal.fire('Fallo de conexión', 'No se pudo procesar: ' + error.message, 'error');
            });
        }
    });
}
</script>

<?php include 'includes/layout_footer.php'; ?>