<?php
include("config.inc.php");
$insert = $pdo->prepare("
    INSERT INTO embeddings (file_name, content, vector_blob)
    VALUES (:file_name, :content, :vector_blob)
");

$insert = $pdo->prepare("
    INSERT INTO embeddings (file_name, content, vector_blob)
    VALUES (:file_name, :content, :vector_blob)
");

/* =============================
   FIND TXT FILES
==============================*/
$folder = __DIR__ . "/data/txt";
$files = glob($folder . "/*.txt");

if (!$files) {
    echo "❌ No .txt files found.\n";
    exit;
}

echo "Found " . count($files) . " files.\n\n";

/* =============================
   CLEAN TEXT FUNCTION
==============================*/
function clean_text($text)
{
    // Convert to UTF-8
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'auto');
    }

    // Remove NULL bytes + control chars
    $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text);

    // Normalize weird Unicode spaces
    $text = preg_replace('/[\xC2\xA0]/', ' ', $text);

    // Remove non-printable Unicode
    $text = preg_replace('/[^\P{C}\n]+/u', ' ', $text);

    // Remove invisible zero-width chars
    $text = preg_replace('/[\x{200B}-\x{200F}\x{2028}\x{2029}\x{202A}-\x{202E}]/u', '', $text);

    // Remove special problematic symbols
    $text = str_replace(["\t", "\r"], " ", $text);

    // Replace quotes " with safe text
    $text = str_replace('"', '()', $text);

    // Replace multiple spaces with one
    $text = preg_replace('/\s+/', ' ', $text);

    return trim($text);
}

/* =============================
   PROCESS FILES
==============================*/
foreach ($files as $file) {

    $fileName = basename($file);
    echo "Processing $fileName\n";

    $raw = file_get_contents($file);
    if (!$raw || strlen(trim($raw)) < 3) {
        echo "  ⛔ Empty file, skip.\n";
        continue;
    }

    /* CLEAN TEXT */
    $clean = clean_text($raw);

    echo "  Cleaned length: " . strlen($clean) . " chars\n";
    echo "  Creating embedding...\n";

    /* =============================
       CREATE EMBEDDING
    ==============================*/
    $payload = json_encode([
        "model" => $MODEL,
        "input" => $clean
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
        echo "  ⛔ API failed.\n";
        continue;
    }

    $json = json_decode($res, true);
    if (!isset($json["data"][0]["embedding"])) {
        echo "  ⛔ Invalid API response.\n";
        continue;
    }

    $vector = $json["data"][0]["embedding"];
    // $blob = pack("f*", ...$vector);
    $textVector = json_encode($vector, JSON_UNESCAPED_UNICODE);

    /* =============================
       SAVE TO DB
    ==============================*/
    $insert->execute([
        ":file_name" => $fileName,
        ":content" => $clean,
        ":vector_blob" => $textVector   // <- giờ là TEXT
    ]);
    echo "  ✔ Saved.\n\n";
}

echo "DONE!\n";