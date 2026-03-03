<?php

include("config.inc.php");
$question = $_POST['question'] ?? '';

$payload = [
    "model" => "gpt-4o-mini",
    "messages" => [
        ["role" => "user", "content" => $question]
    ]
];

$ch = curl_init("https://api.openai.com/v1/chat/completions");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer ".$OPENAI_API_KEY
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);

echo json_encode([
    "answer" => $data["choices"][0]["message"]["content"]
]);
