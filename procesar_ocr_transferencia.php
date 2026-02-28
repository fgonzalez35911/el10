<?php
error_reporting(0);
ini_set('display_errors', 0);
include_once '../includes/db.php'; 

if (isset($conexion) && !isset($conn)) {
    $host = "localhost"; $user = "u415354546_kiosco"; $pass = "Brg13abr"; $db = "u415354546_kiosco";
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset("utf8mb4");
}

if (isset($_POST['imagen_base64'])) {
    $base64 = $_POST['imagen_base64'];
    $ch = curl_init('https://api.ocr.space/parse/image');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'apikey' => 'helloworld', 
        'language' => 'spa', 
        'base64Image' => $base64, 
        'OCREngine' => '2'
    ]));
    $res = curl_exec($ch); curl_close($ch);
    $json = json_decode($res, true);
    
    if (isset($json['ParsedResults'][0]['ParsedText'])) {
        $texto_crudo = $json['ParsedResults'][0]['ParsedText'];

        // --- 1. DICCIONARIO DE EXCEPCIONES (Para limpiar el texto) ---
        $errores_comunes = [
            'Numera' => 'Número', 'numera' => 'número', 'operecio' => 'operación', 'operacion' => 'operación',
            'Manios' => 'Varios', 'mertudo' => 'mercado', 'fago' => 'pago', 'mercedo' => 'mercado',
            'Comprabante' => 'Comprobante', 'Ccmprobante' => 'Comprobante', 'Transferencía' => 'transferencia',
            'CVL:' => 'CVU:', 'CBL:' => 'CBU:', 'CBUU' => 'CBU', 'CVUU' => 'CVU', 
            'GUIL' => 'CUIL', 'CUIU' => 'CUIL', 'CUITÍCUIL' => 'CUIT/CUIL', 'CUlT' => 'CUIT',
            'nro' => 'Número', 'Nro' => 'Número', 'rnonto' => 'monto', 'Manta' => 'Monto',
            'Lunes' => 'lunes', 'Maries' => 'martes', 'Miercoles' => 'miércoles', 'Jueves' => 'jueves', 
            'Viemes' => 'viernes', 'Sabado' => 'sábado', 'Dominga' => 'domingo',
            'Enero' => 'enero', 'Febrera' => 'febrero', 'Marza' => 'marzo', 'Abríl' => 'abril',
            '|' => 'I', '[' => 'C', ']' => 'J', '{' => 'C', '}' => 'J',
            'Banca' => 'Banco', 'Bancc' => 'Banco', 'Ganco' => 'Banco',
            'Galicía' => 'Galicia', 'Gelicia' => 'Galicia', 'Calicia' => 'Galicia',
            'Santan' => 'Santander', 'Sentander' => 'Santander', 'Nacion' => 'Nación', 
            'Macroc' => 'Macro', 'Brubank' => 'Brubank', 'Bruank' => 'Brubank',
            'Paga' => 'Pago', 'Exítosa' => 'Exitosa', 'Exilosa' => 'Exitosa', 'Exilasa' => 'Exitosa',
            'reallzada' => 'realizada', 'reaIizada' => 'realizada', 
            'Hacia' => 'hacia', 'Dastino' => 'Destino', 'Destina' => 'Destino',
            'Cunta' => 'Cuenta', 'Cuonta' => 'Cuenta', 'Gcuenta' => 'Cuenta',
            'Ahrro' => 'Ahorro', 'Ahorra' => 'Ahorro', 'Gaja' => 'Caja', 'Cej' => 'Caja',
            'Pescs' => 'Pesos', 'Peses' => 'Pesos', '$ ' => '$', 
            '0peracion' => 'Operación', 'Qperacion' => 'Operación', 
            'trensferencia' => 'transferencia', 'Tronsferencia' => 'transferencia',
            'Ivt' => 'IVA', 'Impuosto' => 'Impuesto', 'Cemprobante' => 'Comprobante'
        ];
        $t = str_ireplace(array_keys($errores_comunes), array_values($errores_comunes), $texto_crudo);
        $t = str_replace(["\r", "\n"], "  ", $t); 

        // --- 2. EXTRACCIÓN DE DATOS NUMÉRICOS (Monto, CBU, Doc, Op) ---
        preg_match('/\$\s?([0-9]+(?:\.[0-9]{3})*(?:,[0-9]{1,2})?)/', $t, $m);
        $monto = $m[1] ?? "0,00";

        preg_match_all('/\b\d{22}\b/', $t, $cbus);
        $cbu_e = $cbus[0][0] ?? '---';
        $cbu_r = $cbus[0][1] ?? '---';

        preg_match_all('/\b\d{7,11}\b/', $t, $docs);
        $doc_e = $docs[0][0] ?? '---';
        $doc_r = $docs[0][1] ?? '---';

        preg_match_all('/\b\d{10,18}\b/', $t, $ops);
        $nro_op = 'S/N';
        foreach($ops[0] as $posible_op) {
            if ($posible_op != $doc_e && $posible_op != $doc_r) {
                $nro_op = $posible_op;
                break;
            }
        }

        // --- 3. LÓGICA DINÁMICA DE NOMBRES ---
        $nom_e = 'No detectado';
        $nom_r = 'No detectado';

        // Buscamos todos los bloques de 2 o más palabras capitalizadas o en mayúsculas
        preg_match_all('/\b[A-ZÁÉÍÓÚÑa-z]{3,}\s[A-ZÁÉÍÓÚÑa-z]{3,}(?:\s[A-ZÁÉÍÓÚÑa-z]{3,})?\b/', $t, $posibles);
        
        $candidatos = [];
        $blacklist = '/MERCADO|PAGO|TRANSFERENCIA|EXITOSA|COMPROBANTE|DETALLE|FECHA|HORA|MONTO|PESOS|BANCO|LUNES|MARTES|MIERCOLES|JUEVES|VIERNES|SABADO|DOMINGO|ORIGEN|DESTINO|CUENTA|TITULAR|CBU|CVU|CUIT|CUIL|DOCUMENTO|ESTADO|OPERACION/i';

        foreach ($posibles[0] as $p) {
            if (!preg_match($blacklist, $p)) {
                $candidatos[] = trim($p);
            }
        }

        // Asignación por orden de aparición
        $nom_e = $candidatos[0] ?? 'No detectado';
        $nom_r = $candidatos[1] ?? 'No detectado';

        // Si el emisor es igual al receptor (error común de lectura), buscamos el siguiente
        if ($nom_e !== 'No detectado' && $nom_e === $nom_r) {
            $nom_r = $candidatos[2] ?? 'No detectado';
        }

        // --- 4. GUARDADO FINAL ---
        $datos_excel = json_encode([
            'op' => $nro_op, 'nom_e' => $nom_e, 'doc_e' => $doc_e, 'cbu_e' => $cbu_e,
            'nom_r' => $nom_r, 'doc_r' => $doc_r, 'cbu_r' => $cbu_r
        ], JSON_UNESCAPED_UNICODE);

        $sql = "INSERT INTO transferencias (monto, datos_json, texto_completo, imagen_base64) 
                VALUES ('$monto', '$datos_excel', '".$conn->real_escape_string($t)."', '".$conn->real_escape_string($base64)."')";
        
        echo ($conn->query($sql)) ? "OK" : "Error SQL";
    } else { echo "Error IA"; }
}
?>