<?php
header('Content-Type: application/json');

$api_key = "YOUR_ANTHROPIC_API_KEY";

$data = json_decode(file_get_contents("php://input"), true);
$messages = $data['messages'] ?? [];

$payload = [
    "model" => "claude-3-sonnet-20240229",
    "max_tokens" => 500,
    "messages" => $messages,
    "system" => "You are TransNet AI, a smart assistant for drivers. Give helpful, short, practical answers about routes, traffic, safety, and passengers."
];

$ch = curl_init("https://api.anthropic.com/v1/messages");

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "x-api-key: $api_key",
    "anthropic-version: 2023-06-01"
]);

$response = curl_exec($ch);

if(curl_errno($ch)){
    echo json_encode(["reply" => "Error: " . curl_error($ch)]);
    exit;
}

curl_close($ch);

// <i data-lucide="check-circle-2" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i> Decode API response
$result = json_decode($response, true);

// <i data-lucide="check-circle-2" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i> Extract AI message correctly
$response_text = $result['content'][0]['text'] ?? 'No response from AI';

// <i data-lucide="check-circle-2" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i> Send clean JSON to frontend
echo json_encode([
    "reply" => $response_text
]);
<script src="../assets/offline-icons.js"></script>
