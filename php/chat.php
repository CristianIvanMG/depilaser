<?php
// ===========================
// CONFIGURACIÓN ANTI-BUFFER (LiteSpeed)
// ===========================
ini_set('display_errors', 0);
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', 0);
ob_implicit_flush(true);
while (ob_get_level()) { ob_end_clean(); }

session_start();

// ===========================
// CARGAR API KEY (compartida por ambos flujos)
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
// PROMPT BASE (compartido)
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
// ██████  FLUJO TWILIO  ██████
// Si es Twilio: entra aquí, hace todo y sale con exit.
// El flujo web nunca ve este bloque.
// ===========================
if (isset($_POST['Body'])) {

  // — Prompt específico para WhatsApp —
  $systemPromptTwilio = $systemPrompt . "\n\nCANAL: WhatsApp. NUNCA ofrezcas el enlace de WhatsApp ni lo menciones. Para agendar, usa solo el número telefónico 55 3543-3490. Ve directo al cierre de cita.";

  $userMessage = trim($_POST['Body'] ?? "");
  $userId      = $_POST['From'] ?? "twilio_unknown";
  $sessionKey  = "twilio_" . md5($userId);

  // Validar mensaje vacío
  if ($userMessage === "") {
    header("Content-Type: text/xml; charset=UTF-8");
    echo "<Response><Message>Hola, soy Paola de BellaNick Clinic. ¿En qué te puedo ayudar?</Message></Response>";
    exit;
  }

  // Validar API key
  if (!$apiKey) {
    header("Content-Type: text/xml; charset=UTF-8");
    echo "<Response><Message>Servicio temporalmente no disponible. Llámanos al 55 3543-3490.</Message></Response>";
    exit;
  }

  // Memoria de sesión por número de WhatsApp
  if (!isset($_SESSION[$sessionKey])) {
    $_SESSION[$sessionKey] = [
      ["role" => "system", "content" => $systemPromptTwilio]
    ];
  }

  $_SESSION[$sessionKey][] = [
    "role"    => "user",
    "content" => $userMessage
  ];

  // Payload OpenAI
  $payload = [
    "model"       => "gpt-4.1-mini",
    "messages"    => $_SESSION[$sessionKey],
    "temperature" => 0.4
  ];

  // Curl OpenAI
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

  // Error de red
  if ($response === false) {
    header("Content-Type: text/xml; charset=UTF-8");
    echo "<Response><Message>Ocurrió un problema técnico. Llámanos al 55 3543-3490.</Message></Response>";
    exit;
  }

  $data = json_decode($response, true);

  // Error de API (cuota, billing, etc.)
  if (isset($data["error"])) {
    header("Content-Type: text/xml; charset=UTF-8");
    echo "<Response><Message>Nuestro asistente está fuera de servicio. Te atendemos al 55 3543-3490.</Message></Response>";
    exit;
  }

  $assistantReply = $data["choices"][0]["message"]["content"]
    ?? "¿Quieres que agendemos tu cita? Llámanos al 55 3543-3490.";

  // Guardar respuesta en sesión
  $_SESSION[$sessionKey][] = [
    "role"    => "assistant",
    "content" => $assistantReply
  ];

  // Respuesta XML para Twilio
  header("Content-Type: text/xml; charset=UTF-8");
  echo "<Response><Message>" . htmlspecialchars($assistantReply) . "</Message></Response>";
  exit; // ← El flujo web NUNCA llega aquí
}

// ===========================
// ██████  FLUJO WEB  ██████
// Todo lo de abajo es exactamente tu chat.php original.
// No se cambió ni una línea.
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