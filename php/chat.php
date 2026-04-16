<?php
declare(strict_types=1);

header("Content-Type: application/json");

// 1. Leer el mensaje
$input = json_decode(file_get_contents("php://input"), true);
$userMessage = trim($input["message"] ?? "");

if ($userMessage === "") {
  echo json_encode(["reply" => "¿En qué puedo ayudarte hoy?"]);
  exit;
}

// 2. Prompt del recepcionista
$systemPrompt = <<<PROMPT
Eres el recepcionista virtual de Bella Nick Clinic,
una clínica de depilación láser y tratamientos estéticos.

Funciones:
- Resolver dudas sencillas
- Ayudar a agendar citas
- Explicar tratamientos de forma general

Reglas:
- No diagnosticar
- No prometer resultados
- No dar precios exactos
- Siempre invitar a agendar o contactar por WhatsApp

Mantén un tono profesional, amable y claro.
PROMPT;

// 3. Preparar llamada a OpenAI
$payload = [
  "model" => "gpt-4.1-mini",
  "messages" => [
    ["role" => "system", "content" => $systemPrompt],
    ["role" => "user", "content" => $userMessage]
  ],
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

if ($response === false) {
  echo json_encode(["reply" => "Por favor, inténtalo más tarde."]);
  exit;
}

curl_close($ch);

// 4. Respuesta
$data = json_decode($response, true);
$reply = $data["choices"][0]["message"]["content"] ?? "¿Deseas que agendemos una cita?";

echo json_encode(["reply" => $reply]);
