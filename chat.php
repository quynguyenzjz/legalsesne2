<?php

include("config.inc.php");



function cosine_similarity($a, $b)
{
    $dot = 0.0;
    $normA = 0.0;
    $normB = 0.0;

    $len = min(count($a), count($b));

    for ($i = 0; $i < $len; $i++) {
        $dot += $a[$i] * $b[$i];
        $normA += $a[$i] ** 2;
        $normB += $b[$i] ** 2;
    }

    if ($normA == 0 || $normB == 0)
        return 0;

    return $dot / (sqrt($normA) * sqrt($normB));
}
function create_lookup_info($query)
{
    $payload = json_encode([
        "model" => $GLOBALS["MODEL"],
        "input" => $query
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init("https://api.openai.com/v1/embeddings");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer " . $GLOBALS["OPENAI_API_KEY"]
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true
    ]);
    $res = curl_exec($ch);
    curl_close($ch);

    if (!$res) {
        return [];
    }

    $json = json_decode($res, true);
    $queryVector = $json["data"][0]["embedding"];

    $stmt = $GLOBALS["pdo"]->query("SELECT file_name, content, vector_blob FROM embeddings");

    $results = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

        $vec = json_decode($row["vector_blob"], true);
        if (!is_array($vec))
            continue;

        $sim = cosine_similarity($queryVector, $vec);

        $results[] = [
            "file" => $row["file_name"],
            "content" => $row["content"],
            "sim" => $sim
        ];
    }

    usort($results, fn($a, $b) => $b["sim"] <=> $a["sim"]);

    return array_slice($results, 0, 3);
}


// echo create_lookup_info("Hợp đồng mua bán hàng hóa giữa công ty A và công ty B có điều khoản về giao hàng và thanh toán như thế nào?");
// exit(0); //debug
$question = $_POST['question'] ?? '';
// question = "xin chào, kết hôn trước 18 tuổi vi phạm luật nào ko?";

// ====================
// 1) Lấy top luật liên quan
// ====================
$contexts = create_lookup_info($question);

$law_text = "";
foreach ($contexts as $ctx) {
    $law_text .= "From file: {$ctx["file"]}\n{$ctx["content"]}\n\n";
}

// ====================
// 2) Tạo prompt RAG
// ====================
$final_prompt = "
Bạn là trợ lý pháp lý. Dưới đây là các điều luật liên quan:

======== TRÍCH TỪ BỘ LUẬT ========
$law_text
==================================

Dựa vào nội dung luật trên, hãy trả lời câu hỏi của người dùng và CHỈ dùng thông tin trong luật:
Câu hỏi: $question
";

// ====================
// 3) Gửi sang GPT
// ====================
$payload = [
    "model" => "gpt-4o-mini",
    "messages" => [
        ["role" => "user", "content" => $final_prompt]
    ]
];

$ch = curl_init("https://api.openai.com/v1/chat/completions");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "Authorization: Bearer " . $OPENAI_API_KEY
    ],
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
]);

$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);

echo json_encode([
    "answer" => $data["choices"][0]["message"]["content"],
    "law_used" => $contexts   // Optional: trả về luật đã dùng
], JSON_UNESCAPED_UNICODE);