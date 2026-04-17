<?php
session_start();
header("Content-Type: application/json");

// ===========================
// Cargar la API key desde archivo seguro
// ===========================
$config = require __DIR__ . '/../config/secrets.php';
$apiKey = $config['openai_api_key'] ?? null;

if (!$apiKey) {
  echo json_encode([
    "reply" => "Error de configuración del servidor."
  ]);
  exit;
}

// ===========================
// Obtener mensaje del usuario
// ===========================
$input = json_decode(file_get_contents("php://input"), true);
$userMessage = trim($input["message"] ?? "");

if ($userMessage === "") {
  echo json_encode([
    "reply" => "¿En qué puedo ayudarte?"
  ]);
  exit;
}

// ===========================
// Prompt del sistema (NO MODIFICADO)
// ===========================
$systemPrompt = <<<PROMPT
Eres Paola, recepcionista virtual de BellaNick Clinic, clínica de depilación láser y estética en CDMX.

IDENTIDAD
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
- Depilación láser diodo
- Lipólisis láser
- Cavitación
- Radiofrecuencia tripolar
- Electroestimulación
- Sucursales: Roma Sur e Insurgentes Sur

Nunca des más de una opción si la usuaria ya eligió.
Nunca repitas información innecesaria.
PROMPT;

// ===========================
// Inicializar historial de sesión
// ===========================
if (!isset($_SESSION["messages"])) {
  $_SESSION["messages"] = [
    ["role" => "system", "content" => $systemPrompt]
  ];
}

// ===========================
// Agregar mensaje del usuario
// ===========================
$_SESSION["messages"][] = [
  "role" => "user",
  "content" => $userMessage
];

// ===========================
// Payload para OpenAI
// ===========================
$payload = [
  "model" => "gpt-4.1-mini",
  "messages" => $_SESSION["messages"],
  "temperature" => 0.4
];

// ===========================
// Llamada a OpenAI
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
  echo json_encode([
    "reply" => "Lo siento, ocurrió un problema técnico."
  ]);
  exit;
}

$data = json_decode($response, true);

// ===========================
// Manejo de error de OpenAI
// ===========================
if (isset($data["error"])) {
  echo json_encode([
    "reply" => "Tenemos un inconveniente técnico, ¿prefieres que te atiendan por WhatsApp?"
  ]);
  exit;
}

