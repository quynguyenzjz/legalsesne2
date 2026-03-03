<?php
// =======================
// CONFIG
// =======================
include("config.inc.php");

// =======================
// GET QUERY FROM CLI
// =======================
if ($argc < 2) {
    echo "Usage: php lookup.php \"câu hỏi cần tìm\"\n";
    exit;
}

$query = $argv[1];
echo "Query: $query\n";

// =======================
// FUNCTION: COSINE SIMILARITY
// =======================
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

    if ($normA == 0 || $normB == 0) return 0;

    return $dot / (sqrt($normA) * sqrt($normB));
}

// =======================
// GET EMBEDDING FOR QUERY
// =======================
echo "Creating query embedding...\n";

$payload = json_encode([
    "model" => $MODEL,
    "input" => $query
], JSON_UNESCAPED_UNICODE);

$ch = curl_init("https://api.openai.com/v1/embeddings");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "Authorization: Bearer $OPENAI_API_KEY"
    ],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true
]);
$res = curl_exec($ch);
curl_close($ch);

if (!$res) {
    die("❌ API error\n");
}

$json = json_decode($res, true);
$queryVector = $json["data"][0]["embedding"];

// =======================
// FETCH ALL DOCUMENT VECTORS
// =======================
$stmt = $pdo->query("SELECT id, file_name, content, vector_blob FROM embeddings");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$results = [];

foreach ($rows as $row) {
    // vector_blob đang lưu dạng JSON TEXT
    $vec = json_decode($row["vector_blob"], true);

    if (!is_array($vec)) continue;

    $sim = cosine_similarity($queryVector, $vec);

    $results[] = [
        "file" => $row["file_name"],
        "content" => $row["content"],
        "sim" => $sim
    ];
}

// =======================
// SORT BY SIMILARITY DESC
// =======================
usort($results, function ($a, $b) {
    return $b["sim"] <=> $a["sim"];
});

// =======================
// OUTPUT TOP 3 RESULTS
// =======================
echo "\n=========================\n";
echo " TOP KẾT QUẢ GIỐNG NHẤT\n";
echo "=========================\n\n";

$TOP = 3;
for ($i = 0; $i < min($TOP, count($results)); $i++) {
    echo "📄 File: " . $results[$i]["file"] . "\n";
    echo "🔎 Similarity: " . round($results[$i]["sim"], 6) . "\n";
    echo "📌 Content:\n" . $results[$i]["content"] . "\n";
    echo "----------------------------------------\n\n";
}
