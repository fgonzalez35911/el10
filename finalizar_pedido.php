<?php
// acciones/finalizar_pedido.php - CON VALIDACIÓN DE FECHA Y USUARIO
require_once '../includes/db.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) { echo json_encode(['status'=>'error', 'msg'=>'Datos vacíos']); exit; }

$items = $data['carrito'];
$cupon_codigo = $data['cupon'] ?? '';
// En una tienda real, el ID del cliente vendría de la sesión del usuario logueado en la web.
// Como acá es pública, validamos por DNI o dejamos libre si es cupón general.
$cliente_dni = $data['dni'] ?? ''; 

// 1. VALIDAR CUPÓN (NUEVA LÓGICA)
$descuento = 0;
$texto_cupon = "";

if($cupon_codigo) {
    // Buscamos cupón activo
    $stmt = $conexion->prepare("SELECT * FROM cupones WHERE codigo = ? AND activo = 1");
    $stmt->execute([$cupon_codigo]);
    $cupon = $stmt->fetch();

    if($cupon) {
        $es_valido = true;
        
        // A. Validar Fecha
        if($cupon->fecha_limite && $cupon->fecha_limite < date('Y-m-d')) {
            $es_valido = false; // Vencido
        }

        // B. Validar Cliente (Si el cupón tiene dueño)
        if($cupon->id_cliente) {
            // Buscamos si el DNI ingresado coincide con el dueño del cupón
            $stmtC = $conexion->prepare("SELECT dni FROM clientes WHERE id = ?");
            $stmtC->execute([$cupon->id_cliente]);
            $dueno = $stmtC->fetch();
            
            // Si el DNI no coincide, no es válido
            if(!$dueno || $dueno->dni != $cliente_dni) {
                $es_valido = false;
            }
        }

        if($es_valido) {
            $total_bruto = 0;
            foreach($items as $i) $total_bruto += $i['precio'] * $i['cantidad'];
            
            // C. Descuento sobre el TOTAL
            $descuento = ($total_bruto * $cupon->descuento_porcentaje) / 100;
            $texto_cupon = "\n🎉 CUPÓN APLICADO ($cupon_codigo): -$$descuento";
        }
    }
}

// 2. CALCULAR TOTALES
$total = 0;
$texto_pedido = "";
foreach($items as $i) {
    $total += $i['precio'] * $i['cantidad'];
    $texto_pedido .= "▪ {$i['cantidad']}x {$i['nombre']} ($" . ($i['precio']*$i['cantidad']) . ")\n";
}

$total_final = $total - $descuento;

// (El resto del guardado en BD y envío de email sigue igual...)
// ...

// 3. RESPONDER
$ws_msg = "*NUEVO PEDIDO WEB*\n";
$ws_msg .= "Cliente: " . ($data['nombre'] ?? 'Anónimo') . "\n";
$ws_msg .= $texto_pedido;
$ws_msg .= $texto_cupon;
$ws_msg .= "\n\n*TOTAL A PAGAR: $$total_final*";

// Traer telefono del negocio
$conf = $conexion->query("SELECT telefono_whatsapp FROM configuracion WHERE id=1")->fetch();
$ws_link = "https://wa.me/" . $conf->telefono_whatsapp . "?text=" . urlencode($ws_msg);

echo json_encode(['status'=>'success', 'url'=>$ws_link]);
?>