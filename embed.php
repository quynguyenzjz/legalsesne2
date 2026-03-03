<?php
include("config.inc.php");

$MODEL = "text-embedding-3-large";

/* ----------------------------------------
   SQL INSERT
---------------------------------------- */
$insert = $pdo->prepare("
    INSERT INTO embeddings (file_name, content, vector_blob)
    VALUES (:file_name, :content, :vector_blob)
");

/* ----------------------------------------
   FIND TXT FILES
---------------------------------------- */
$folder = __DIR__ . "/data/txt";
$files = glob($folder . "/*.txt");

if (!$files) {
    echo "❌ No .txt files found.\n";
    exit;
}

echo "Found " . count($files) . " files.\n\n";

/* ----------------------------------------
   CLEAN TEXT
---------------------------------------- */
function clean_text($text)
{
    // return $text;
    // Convert to UTF-8
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'auto');
    }


    // Remove control chars but KEEP \n (0x0A)
    $text = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', ' ', $text);

    // Normalize spaces
    // $text = preg_replace('/[\xC2\xA0]/', ' ', $text);

    // Remove invisible Unicode chars (but keep \n)
    $text = str_replace(
        [
            "\xE2\x80\x8B", // 200B zero-width space
            "\xE2\x80\x8C", // 200C zero-width non-joiner
            "\xE2\x80\x8D", // 200D zero-width joiner
            "\xE2\x80\x8E", // 200E LTR mark
            "\xE2\x80\x8F"
        ],// 200F RTL mark
        "",
        $text
    );

    // Remove tabs & \r
    $text = str_replace(["\t", "\r"], " ", $text);

    // Replace quotes
    $text = str_replace('"', "()", $text);

    // Replace multiple spaces but KEEP newline
    $text = preg_replace('/[ ]+/', ' ', $text);

    // Trim but do NOT remove \n structure
    return trim($text);
}

/* ----------------------------------------
   SPLIT INTO ARTICLES (THEO ĐIỀU)
---------------------------------------- */
function split_into_articles($text)
{
    // Bảo toàn xuống dòng để regex nhận diện đầu dòng
    $text = "\n" . $text;

    // Regex:
    // ^\s*Điều\s*\d+\.?
    //  - ^           : đầu dòng
    //  - \s*         : có thể có khoảng trắng đầu dòng
    //  - Điều\s*\d+  : "Điều" + số điều
    //  - \.?         : có hoặc không có dấu chấm
    //
    // Phần sau .*? ăn đến điều tiếp theo đứng đầu dòng
    $pattern = '/^\s*(Điều\s*\d+\.?.*?)(?=^\s*Điều\s*\d+\.?|\z)/uism';

    preg_match_all($pattern, $text, $matches);

    return $matches[1] ?? [];
}

/* ----------------------------------------
   PROCESS FILES
---------------------------------------- */
foreach ($files as $file) {

    $fileName = basename($file);
    echo "\n📄 Processing file: $fileName\n";

    $raw = file_get_contents($file);
    if (!$raw || strlen(trim($raw)) < 3) {
        echo "  ⛔ Empty file, skip.\n";
        continue;
    }

    echo "  Splitting into articles...\n";

    $articles = split_into_articles($raw);

    //print_r($articles);
    //exit(0);

    if (count($articles) == 0) {
        echo "  ⛔ No articles detected. Skip.\n";
        continue;
    }

    echo "  Found " . count($articles) . " articles.\n";

    /* PROCESS EACH ARTICLE */
    foreach ($articles as $index => $articleText) {

        $clean = clean_text($articleText);

        echo "  → Article " . ($index + 1) . " (length " . strlen($clean) . " chars)\n";
        echo "    Creating embedding...\n";
        echo $clean; 
        // continue;

        /* ----------------------------------------
           CREATE EMBEDDING
        ---------------------------------------- */
        $payload = json_encode([
            "model" => $MODEL,
            "input" => $clean
        ], JSON_UNESCAPED_UNICODE);
        var_dump ($payload);

        $ch = curl_init("https://api.openai.com/v1/embeddings");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Authorization: " . "Bearer " . $OPENAI_API_KEY
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true
        ]);

        $res = curl_exec($ch);
        curl_close($ch);

        if (!$res) {
            echo "    ⛔ API failed.\n";
            var_dump($json);
            continue;
        }

        $json = json_decode($res, true);
        if (!isset($json["data"][0]["embedding"])) {
            echo "    ⛔ Invalid API response.\n";
            var_dump($json);
            continue;
        }

        $vector = $json["data"][0]["embedding"];
        $textVector = json_encode($vector, JSON_UNESCAPED_UNICODE);

        /* ----------------------------------------
           SAVE TO DB
        ---------------------------------------- */
        $insert->execute([
            ":file_name" => $fileName,
            ":content" => $clean,
            ":vector_blob" => $textVector
        ]);

        echo "    ✔ Saved article.\n";
        sleep(1); // tránh overload API
    }
}

echo "\n🎉 DONE!\n";