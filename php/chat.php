<?php
// ===========================
// 🔐 VERIFICACIÓN META (DEBE IR PRIMERO)
// ===========================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $verify_token = "bella123"; // <-- mismo que configuras en Meta

    if (
        isset($_GET['hub_mode']) &&
        $_GET['hub_mode'] === 'subscribe' &&
        isset($_GET['hub_verify_token']) &&
        $_GET['hub_verify_token'] === $verify_token
    ) {
        echo $_GET['hub_challenge'];
        exit;
    } else {
        http_response_code(403);
        exit;
    }
}

// ===========================
// CONFIG ANTI-BUFFER
// ===========================
ini_set('display_errors', 0);
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', 0);
ob_implicit_flush(true);
while (ob_get_level()) { ob_end_clean(); }

session_start();

// ===========================
// CARGAR API KEY OPENAI
// ===========================
$secretsPaths = [
  __DIR__ . '/../../../../secrets.php',
  __DIR__ . '/../../../secrets.php',
  __DIR__ . '/../../secrets.php',
  __DIR__ . '/../config/secrets.php',
];

$config = null;
foreach ($secretsPaths as $path) {
  if (is_file($path)) {
    $config = require $path;
    break;
  }
}

$apiKey = is_array($config) ? ($config['openai_api_key'] ?? null) : null;

// ===========================
// PROMPT BASE
// ===========================
$systemPrompt = <<<PROMPT
Eres Paola, recepcionista virtual de BellaNick Clinic, clínica de depilación láser y estética en CDMX.

====================
IDENTIDAD
====================
- Tu nombre es Paola.
- Te comunicas como una recepcionista humana real.
- Eres amable, clara, directa y profesional.
- Hablas siempre en femenino y de tú.
- Nunca dices que eres IA ni bot.
- Nunca usas frases de folleto ni lenguaje comercial exagerado.

====================
REGLAS ABSOLUTAS
====================
- Máximo 2 frases por respuesta, nunca más.
- Haz solo UNA pregunta por mensaje.
- Nunca repitas la bienvenida después del primer mensaje.
- Nunca preguntes “¿en qué te puedo ayudar?” si la usuaria ya expresó su intención.
- Nunca ofrezcas servicios si la usuaria ya eligió uno.
- Nunca repitas información innecesaria.
- Si la intención es clara, avanza al siguiente paso sin retroceder.
- Si una pregunta no aporta a agendar, responde breve y redirige al flujo.

====================
OBJETIVO PRINCIPAL
====================
Guiar a la usuaria para que agende su cita por WhatsApp, llamada o agenda web de forma natural y confiable.

Cada mensaje debe acercar a ese objetivo.

====================
FLUJO DE CITA (OBLIGATORIO)
====================
1. Primer mensaje:
   Saluda con “Bienvenida a BellaNick Clinic” y pregunta en qué le ayudas.

2. Si la usuaria menciona un servicio:
   Confirma brevemente y pregunta para qué zona del cuerpo.

3. Si responde la zona:
   Indica que hay disponibilidad y pregunta si prefiere agendar por WhatsApp o por llamada.

4. Si elige WhatsApp:
   Comparte el enlace https://wa.me/525535433490 y cierra con una frase cálida.

5. Si elige llamada:
   Comparte el número 55 3543 3490 y cierra con una frase cálida.

====================
CONOCIMIENTO DEL NEGOCIO (PARA INFORMAR BIEN)
====================
Conoces TODA la información del negocio y puedes responder con seguridad cuando te pregunten, sin salirte de las reglas.

Sabes:
- Qué hace cada tratamiento y para qué sirve, usando EXACTAMENTE el texto de las páginas de servicio del sitio web.
- Precios oficiales mostrados en:
  - precios-depilacion-laser
  - precios-servicios-esteticos
- Ubicación y rutas de cada sucursal:
  - Roma Sur
  - Insurgentes Sur
  - Querétaro

Nunca inventes información.
Nunca mezcles servicios.

====================
SERVICIOS (SOLO SI PREGUNTAN)
====================
Explicas brevemente cada tratamiento usando el mismo texto del sitio web:
- Depilación láser cuatridiodo Soprano
- Lipólisis láser
- Cavitación
- Radiofrecuencia tripolar
- Electroestimulación

Nunca des explicaciones técnicas largas.
Nunca menciones más de un servicio si ya eligieron uno.

====================
PRECIOS
====================
Si preguntan por precios:
- Indica el precio o rango correcto según la página correspondiente.
- Sugiere agendar para asegurar el precio.
- No des listas largas ni comparaciones.

====================
SUCURSALES Y UBICACIÓN
====================
Si preguntan por sucursal o ubicación:
- Menciona que contamos con Roma Sur, Insurgentes Sur y Querétaro.
- Pregunta cuál le queda mejor.

Si elige una sucursal:
- Comparte el enlace de Google Maps correspondiente, el mismo de la página web.
- No repitas direcciones escritas largas.

====================
PROMOCIONES
====================
Si preguntan por promociones:
- Indica que hay descuentos al agendar y pagar directamente en el sitio web.
- Aclara que aplican solo en horarios de baja afluencia.
- Comparte el enlace de la agenda: https://depilasermexico.com/agenda
- No menciones porcentajes ni condiciones específicas.

====================
GARANTÍA
====================
Si preguntan por garantía:
- Indica que sí contamos con garantía.
- No expliques políticas por el chat.
- Redirígela a WhatsApp con el mensaje precargado:
  “Hola, quisiera información sobre la garantía y sus políticas para mantenerla activa.”

====================
CASOS ESPECIALES
====================
Si la usuaria:
- duda
- compara
- pregunta varias cosas a la vez

Responde solo a lo principal y vuelve al flujo de agendar.

====================
TONO Y CIERRE
====================
- Siempre cierra con una frase cálida y breve.
- Nunca uses signos excesivos ni emojis en exceso.
- Mantén una comunicación humana, confiable y profesional.

====================
REGLA ANTI-BLOQUEO
====================
Si la conversación se estanca en información, dudas o comparación:
- Responde breve.
- No expliques de más.
- Regresa inmediatamente al flujo de agendar.
PROMPT;

// ===========================
// 🔥 FLUJO WHATSAPP META
// ===========================
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (isset($data['entry'][0]['changes'][0]['value']['messages'][0])) {

    $msg = $data['entry'][0]['changes'][0]['value']['messages'][0];

    $numero = $msg['from'] ?? '';
    $userMessage = $msg['text']['body'] ?? '';

    if ($userMessage && $apiKey) {

        $sessionKey = "wa_" . md5($numero);

        if (!isset($_SESSION[$sessionKey])) {
            $_SESSION[$sessionKey] = [
                ["role" => "system", "content" => $systemPrompt . "\nCANAL: WhatsApp. No menciones enlaces."]
            ];
        }

        $_SESSION[$sessionKey][] = [
            "role" => "user",
            "content" => $userMessage
        ];

        // OpenAI
        $payload = [
            "model" => "gpt-4.1-mini",
            "messages" => $_SESSION[$sessionKey],
            "temperature" => 0.4
        ];

        $ch = curl_init("https://api.openai.com/v1/chat/completions");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Authorization: Bearer " . $apiKey
            ],
            CURLOPT_POSTFIELDS => json_encode($payload)
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $dataOpenAI = json_decode($response, true);

        $reply = $dataOpenAI["choices"][0]["message"]["content"] ?? "¿Quieres agendar cita?";

        $_SESSION[$sessionKey][] = [
            "role" => "assistant",
            "content" => $reply
        ];

        // ===========================
        // 📤 RESPUESTA A META
        // ===========================
        $META_TOKEN = "EAAfQpULmWiUBRaO6W6q6F7QnZBfDnIYdoWm1BmITZCUZAj8Ke2vhDnSRdoNFo27tg8WjKCYg6hzEXtnP0LmkuCmh77Pt0MMcXwulBimP4kizZAOtxAvpN9tp125UUiXarENJZCZCZBeZCZAtcdfvcXEGQRt9KbkiGsxIL8Qux6A1HlFe0PWCvPx0wC81l4lLW2gd00tZCWoc9nMa9KhuPNb9eBaGZCoHDQkLA02H6rJhll0M3guSJZCnRGETtoRHRDZA1ZCMyneYazgvWw11PhOv1tymmhndWqdD3gjZAa2VZAYZD";
        $META_PHONE_ID = "1116317188227216";

        $url = "https://graph.facebook.com/v18.0/$META_PHONE_ID/messages";

        $payloadMeta = [
            "messaging_product" => "whatsapp",
            "to" => $numero,
            "text" => ["body" => $reply]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $META_TOKEN",
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payloadMeta));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        curl_exec($ch);
        curl_close($ch);
    }

    exit; // 🔥 evita que entre al flujo web
}

// ===========================
// 🌐 FLUJO WEB (NO SE TOCA)
// ===========================
header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
if (!$apiKey) {
  echo json_encode(["reply" => "Error de configuración del servidor."]);
  flush();
    exit;
}

// Leer mensaje
$input       = json_decode(file_get_contents("php://input"), true);
$userMessage = trim($input["message"] ?? "");

if ($userMessage === "") {
    echo json_encode(["reply" => "¿En qué puedo ayudarte?"]);
  flush();
    exit;
}

// Sesión web (igual que antes, clave "messages")
if (!isset($_SESSION["messages"])) {
  $_SESSION["messages"] = [
        ["role" => "system", "content" => $systemPrompt]
    ];
}

$_SESSION["messages"][] = [
  "role"    => "user",
    "content" => $userMessage
];

// ===========================
// PAYLOAD OPENAI
// ===========================
$payload = [
  "model"       => "gpt-4.1-mini",
  "messages"    => $_SESSION["messages"],
    "temperature" => 0.4
];

// ===========================
// CURL OPENAI
// ===========================
$ch = curl_init("https://api.openai.com/v1/chat/completions");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST           => true,
  CURLOPT_HTTPHEADER     => [
        "Content-Type: application/json",
        "Authorization: Bearer " . $apiKey
    ],
  CURLOPT_POSTFIELDS => json_encode($payload),
  CURLOPT_TIMEOUT    => 30
]);

$response = curl_exec($ch);
curl_close($ch);

if ($response === false) {
  echo json_encode(["reply" => "Lo siento, ocurrió un problema técnico."]);
  flush();
  exit;
}

$data = json_decode($response, true);

if (isset($data["error"])) {

  // Detectar límite de uso / cuota
  if (
    isset($data["error"]["code"]) &&
    in_array($data["error"]["code"], ["insufficient_quota", "billing_hard_limit_reached"])
  ) {
    echo json_encode([
      "ia_disabled" => true,
      "reply"       => "Nuestro asistente está temporalmente fuera de servicio. Te atendemos de inmediato por WhatsApp."
    ]);
    flush();
    exit;
  }

  echo json_encode([
    "reply" => "Tenemos un inconveniente técnico momentáneo. ¿Prefieres que te atiendan por WhatsApp?"
  ]);
  flush();
  exit;
}

$assistantReply = $data["choices"][0]["message"]["content"] ?? "¿Deseas que agendemos una cita?";

// ===========================
// GUARDAR RESPUESTA
// ===========================
$_SESSION["messages"][] = [
  "role"    => "assistant",
  "content" => $assistantReply
];

// ===========================
// RESPUESTA FINAL (FORZADA)
// ===========================
echo json_encode(
  ["reply" => $assistantReply],
  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
flush();
exit;

