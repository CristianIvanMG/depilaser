<?php
session_start();
header("Content-Type: application/json");

// 1. Obtener mensaje del usuario
$input = json_decode(file_get_contents("php://input"), true);
$userMessage = trim($input["message"] ?? "");

if ($userMessage === "") {
  echo json_encode(["reply" => "¿En qué puedo ayudarte?"]);
  exit;
}

// 2. Prompt del sistema (rol fijo)

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


// 3. Inicializar historial si no existe
if (!isset($_SESSION["messages"])) {
  $_SESSION["messages"] = [
    ["role" => "system", "content" => $systemPrompt]
  ];
}

// 4. Agregar mensaje del usuario al historial
$_SESSION["messages"][] = [
  "role" => "user",
  "content" => $userMessage
];

// 5. Llamada a OpenAI
$payload = [
  "model" => "gpt-4.1-mini",
  "messages" => $_SESSION["messages"],
  "temperature" => 0.4
];

$ch = curl_init("https://api.openai.com/v1/chat/completions");
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => [
    "Content-Type: application/json",
    "Authorization: Bearer " . getenv("OPENAI_API_KEY")
  ],
  CURLOPT_POSTFIELDS => json_encode($payload),
  CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);
curl_close($ch);

if ($response === false) {
  echo json_encode(["reply" => "Lo siento, hubo un problema. Intenta más tarde."]);
  exit;
}

$data = json_decode($response, true);
$assistantReply = $data["choices"][0]["message"]["content"] ?? "¿Deseas que agendemos una cita?";

// 6. Guardar respuesta del asistente en historial
$_SESSION["messages"][] = [
  "role" => "assistant",
  "content" => $assistantReply
];

// 7. Limitar tamaño del historial (evita costos altos)
if (count($_SESSION["messages"]) > 12) {
  $_SESSION["messages"] = array_slice($_SESSION["messages"], -12);
}

echo json_encode([
  "reply" => $assistantReply
]);