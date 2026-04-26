<?php
// ===========================
// CONFIGURACIÓN ANTI-BUFFER (LiteSpeed)
// ===========================
ini_set('display_errors', 0);
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', 0);
ob_implicit_flush(true);
while (ob_get_level()) { ob_end_clean(); }

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

session_start();

// ===========================
// CARGAR API KEY (HOSTINGER)
// Busca secrets.php en varias rutas, en orden de seguridad:
//   1. /home/USER/secrets.php                                (MAS SEGURO - fuera de domains/)
//   2. /home/USER/domains/secrets.php
//   3. /home/USER/domains/DOMAIN/secrets.php                 (fuera de public_html)
//   4. /home/USER/domains/DOMAIN/public_html/config/         (legacy, menos seguro)
// ===========================
$secretsPaths = [
  __DIR__ . '/../../../../secrets.php', // /home/USER/secrets.php          (recomendado)
  __DIR__ . '/../../../secrets.php',    // /home/USER/domains/secrets.php
  __DIR__ . '/../../secrets.php',       // fuera de public_html
  __DIR__ . '/../config/secrets.php',   // ubicacion legacy (fallback)
];

$config = null;
foreach ($secretsPaths as $path) {
  if (is_file($path)) {
    $config = require $path;
    break;
  }
}

$apiKey = is_array($config) ? ($config['openai_api_key'] ?? null) : null;

if (!$apiKey) {
  echo json_encode(["reply" => "Error de configuración del servidor."]);
  flush();
  exit;
}

// ===========================
// LEER MENSAJE
// ===========================
$input = json_decode(file_get_contents("php://input"), true);
$userMessage = trim($input["message"] ?? "");

if ($userMessage === "") {
  echo json_encode(["reply" => "¿En qué puedo ayudarte?"]);
  flush();
  exit;
}

// ===========================
// PROMPT (NO TOCADO)
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
- Depilación láser cuatridiodo soprano
- Lipólisis láser
- Cavitación
- Radiofrecuencia tripolar
- Electroestimulación
- Sucursales: Roma Sur, Insurgentes Sur o Queretaro

Nunca des más de una opción si la usuaria ya eligió.
Nunca repitas información innecesaria.
PROMPT;

// ===========================
// SESIÓN (HISTORIAL)
// ===========================
if (!isset($_SESSION["messages"])) {
  $_SESSION["messages"] = [
    ["role" => "system", "content" => $systemPrompt]
  ];
}

$_SESSION["messages"][] = [
  "role" => "user",
  "content" => $userMessage
];

// ===========================
// PAYLOAD OPENAI
// ===========================
$payload = [
  "model" => "gpt-4.1-mini",
  "messages" => $_SESSION["messages"],
  "temperature" => 0.4
];

// ===========================
// CURL OPENAI
// ===========================
$ch = curl_init("https://api.openai.com/v1/chat/completions");
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => [
    "Content-Type: application/json",
    "Authorization: Bearer " . $apiKey
  ],
  CURLOPT_POSTFIELDS => json_encode($payload),
  CURLOPT_TIMEOUT => 30
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
      "reply" => "Nuestro asistente está temporalmente fuera de servicio. Te atendemos de inmediato por WhatsApp."
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
  "role" => "assistant",
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
