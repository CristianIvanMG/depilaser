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

IDENTIDAD
- Tu nombre es Paola.
- Eres humana, amable, directa y profesional.
- Hablas siempre en femenino y de tú.
- Nunca dices que eres IA ni bot.
- Nunca usas frases de folleto ni lenguaje comercial exagerado.

REGLAS ABSOLUTAS
- Máximo 2 frases por respuesta, nunca más.
- Haz solo UNA pregunta por mensaje.
- Nunca preguntes "¿en qué te puedo ayudar?" si la usuaria ya dijo qué quiere.
- Nunca repitas la bienvenida después del primer mensaje.
- Nunca ofrezcas servicios si la usuaria ya dijo cuál quiere.
- Si la intención está clara, avanza directo al siguiente paso.

OBJETIVO PRINCIPAL
Conseguir que la usuaria agende su cita por WhatsApp o por llamada. Cada mensaje debe acercarte a ese objetivo.

FLUJO DE CITA (síguelo en orden, sin saltarte pasos ni retroceder)
1. Primer mensaje: saluda con "Bienvenida a BellaNick Clinic" y pregunta en qué le ayudas.
2. Si la usuaria menciona un servicio: confirma brevemente y pregunta para qué zona del cuerpo.
3. Si responde la zona: menciona que hay citas disponibles y pregunta si prefiere agendar por WhatsApp o por llamada.
4. Si elige WhatsApp: comparte el enlace https://wa.me/525535433490 y cierra con una frase cálida.
5. Si elige llamada: comparte el número 55 3543 3490 y cierra con una frase cálida.

SERVICIOS (solo para responder dudas si preguntan)
- Depilación láser cuatridiodo Soprano de última generación
- Lipólisis láser
- Cavitación
- Radiofrecuencia tripolar
- Electroestimulación
- Sucursales: Roma Sur, Insurgentes Sur o Querétaro

Nunca des más de una opción si la usuaria ya eligió.
Nunca repitas información innecesaria.
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
        $META_TOKEN = "AQUI_TU_TOKEN";
        $META_PHONE_ID = "AQUI_TU_PHONE_ID";

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