<?php
// ai_extract.php - Read an annual procurement plan PDF with Claude and extract its projects.
//
// Calls the Anthropic Messages API through the configured gateway (AI_BASE_URL) with raw HTTP:
// the gateway ignores `output_config.format`, so the structured result is collected through a
// strict tool (`save_projects`) instead.

if (!defined('AI_BASE_URL')) define('AI_BASE_URL', getenv('AI_BASE_URL') ?: '');
if (!defined('AI_API_KEY')) define('AI_API_KEY', getenv('AI_API_KEY') ?: '');
if (!defined('AI_MODEL')) define('AI_MODEL', getenv('AI_MODEL') ?: 'claude-sonnet-5.5');

const AI_PROCUREMENT_TYPES = ['จัดซื้อ', 'จัดจ้าง', 'เช่า'];
const AI_PROCUREMENT_METHODS = ['เฉพาะเจาะจง', 'ประกวดราคาอิเล็กทรอนิกส์ (e-bidding)', 'คัดเลือก', 'สอบราคา'];
const AI_MAX_PDF_BYTES = 30 * 1024 * 1024; // API request limit is 32 MB including base64 overhead

function ai_is_configured() {
    return AI_BASE_URL !== '' && AI_API_KEY !== '';
}

function ai_project_tool() {
    $month = ['type' => 'string', 'description' => 'Gregorian year-month "YYYY-MM" (convert Buddhist Era years by subtracting 543), or "" if the plan does not state it'];
    return [
        'name' => 'save_projects',
        'description' => 'Save every procurement project listed in the annual procurement plan document.',
        'strict' => true,
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'projects' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'project_name' => ['type' => 'string', 'description' => 'Project / item name exactly as written in the plan (Thai)'],
                            'budget' => ['type' => 'number', 'description' => 'Budget in baht as a plain number, e.g. 450000.00'],
                            'procurement_type' => ['type' => 'string', 'enum' => AI_PROCUREMENT_TYPES],
                            'quantity' => ['type' => 'string', 'description' => 'Quantity with unit as written, e.g. "8 รายการ", "1 ชุด"; "" if not stated'],
                            'procurement_method' => ['type' => 'string', 'enum' => AI_PROCUREMENT_METHODS, 'description' => 'Closest matching method; e-bidding = ประกวดราคาอิเล็กทรอนิกส์'],
                            'required_date' => $month + ['description' => 'Month the goods/work are needed. ' . $month['description']],
                            'request_month' => $month + ['description' => 'Month of the purchase request (ขอซื้อขอจ้าง). ' . $month['description']],
                            'contract_month' => $month + ['description' => 'Month of contract / purchase order. ' . $month['description']],
                            'page' => ['type' => 'integer', 'description' => 'PDF page number (1-based) where the project row appears'],
                            'note' => ['type' => 'string', 'description' => 'Short Thai note for the reviewer when a value was unclear, guessed or mapped; "" otherwise'],
                        ],
                        'required' => ['project_name', 'budget', 'procurement_type', 'quantity', 'procurement_method', 'required_date', 'request_month', 'contract_month', 'page', 'note'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['projects'],
            'additionalProperties' => false,
        ],
    ];
}

/**
 * Extract the projects from a plan PDF.
 *
 * @return array{projects: array<int, array>, usage: array, quota: ?array}
 * @throws RuntimeException with a Thai message suitable for showing to staff
 */
function ai_extract_projects($pdf_path, $fiscal_year_be) {
    if (!ai_is_configured()) {
        throw new RuntimeException('ยังไม่ได้ตั้งค่า AI_BASE_URL / AI_API_KEY ในไฟล์ config.php');
    }
    if (!is_file($pdf_path)) {
        throw new RuntimeException('ไม่พบไฟล์ PDF ของแผนงานบนเซิร์ฟเวอร์');
    }
    if (filesize($pdf_path) > AI_MAX_PDF_BYTES) {
        throw new RuntimeException('ไฟล์ PDF มีขนาดใหญ่เกินกว่าที่ AI รองรับ (30MB)');
    }

    $fy = (int)$fiscal_year_be;
    $system = "You extract procurement projects from Thai government annual procurement plans (แผนการจัดซื้อจัดจ้างประจำปี) "
        . "of the School of Medical Sciences, University of Phayao.\n"
        . "The plan is for fiscal year B.E. {$fy}, which runs from October " . ($fy - 544) . " to September " . ($fy - 543) . " (Gregorian). "
        . "Short Thai dates like \"พ.ย. 68\" mean November B.E. 2568; resolve months without a year using this fiscal year.\n"
        . "Rules:\n"
        . "- Include every project row, including rows continued across pages. Skip header, subtotal and grand-total rows.\n"
        . "- Copy names exactly; do not translate or summarise.\n"
        . "- Never invent values: use \"\" for text you cannot find and explain in note. If a budget is unreadable, give your best reading and say so in note.\n"
        . "- Return a pure JSON array containing the project items.";

    $is_gemini_native = (strpos(AI_BASE_URL, 'generativelanguage.googleapis.com') !== false);

    if ($is_gemini_native) {
        // Direct Google Gemini API
        $url = rtrim(AI_BASE_URL, '/') . '/models/' . AI_MODEL . ':generateContent?key=' . AI_API_KEY;
        $prompt = $system . "\n\nExtract all procurement projects from this Thai annual procurement plan PDF. Return a JSON array where each object has fields: "
            . "project_name (string), budget (number), procurement_type (string: 'จัดซื้อ','จัดจ้าง','เช่า'), quantity (string), "
            . "procurement_method (string: 'เฉพาะเจาะจง','ประกวดราคาอิเล็กทรอนิกส์ (e-bidding)','คัดเลือก','สอบราคา'), "
            . "required_date (YYYY-MM), request_month (YYYY-MM), contract_month (YYYY-MM), page (integer), note (string).";
        
        $body = [
            'contents' => [[
                'parts' => [
                    ['text' => $prompt],
                    ['inline_data' => [
                        'mime_type' => 'application/pdf',
                        'data' => base64_encode(file_get_contents($pdf_path))
                    ]]
                ]
            ]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'maxOutputTokens' => 16000
            ]
        ];

        $ch = curl_init($url);
        $curl_opts = [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HTTPHEADER => ['content-type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        ];
    } else {
        // Anthropic Messages API compatible gateway
        $body = [
            'model' => AI_MODEL,
            'max_tokens' => 16000,
            'stream' => false,
            'system' => $system . "\n- Call the save_projects tool exactly once with all projects. Do not answer in plain text.",
            'tools' => [ai_project_tool()],
            'tool_choice' => ['type' => 'auto'],
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode(file_get_contents($pdf_path))]],
                    ['type' => 'text', 'text' => 'Extract all procurement projects from this plan.'],
                ],
            ]],
        ];

        $ch = curl_init(rtrim(AI_BASE_URL, '/') . '/messages');
        $curl_opts = [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HTTPHEADER => [
                'content-type: application/json',
                'anthropic-version: 2023-06-01',
                'x-api-key' => AI_API_KEY,
            ],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        ];
    }

    // Check for standard CA bundles on Windows PHP environments
    $ca_paths = [
        'C:\Program Files (x86)\PHP\8.2.14\extras\ssl\cacert.pem',
        ini_get('curl.cainfo'),
        ini_get('openssl.cafile')
    ];
    $ca_found = false;
    foreach ($ca_paths as $cap) {
        if (!empty($cap) && is_file($cap)) {
            $curl_opts[CURLOPT_CAINFO] = $cap;
            $ca_found = true;
            break;
        }
    }
    if (!$ca_found) {
        $curl_opts[CURLOPT_SSL_VERIFYPEER] = false;
        $curl_opts[CURLOPT_SSL_VERIFYHOST] = 0;
    }

    curl_setopt_array($ch, $curl_opts);
    $raw = curl_exec($ch);
    $curl_error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        error_log('AI extract transport error: ' . $curl_error);
        throw new RuntimeException('ไม่สามารถเชื่อมต่อบริการ AI ได้ กรุณาลองใหม่อีกครั้ง');
    }
    $resp = json_decode($raw, true);
    if ($status !== 200 || !is_array($resp)) {
        error_log('AI extract HTTP ' . $status . ': ' . substr($raw, 0, 1000));
        if ($status === 429) {
            throw new RuntimeException('ใช้งาน AI เกินโควตาที่กำหนดแล้ว กรุณาลองใหม่ภายหลัง');
        }
        if ($status === 401 || $status === 403) {
            throw new RuntimeException('API key ของบริการ AI ไม่ถูกต้องหรือหมดสิทธิ์ใช้งาน');
        }
        if ($status === 503) {
            throw new RuntimeException('บริการ AI มีผู้ใช้งานหนาแน่นชั่วคราว กรุณากดลองใหม่อีกครั้งในอีกสักครู่');
        }
        throw new RuntimeException('บริการ AI ตอบกลับผิดพลาด (HTTP ' . $status . ') กรุณาลองใหม่อีกครั้ง');
    }

    $projects = null;
    $usage = [];

    if ($is_gemini_native) {
        $text = $resp['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $parsed = json_decode($text, true);
        if (is_array($parsed)) {
            $projects = isset($parsed['projects']) ? $parsed['projects'] : $parsed;
        }
        $usage = $resp['usageMetadata'] ?? [];
    } else {
        $stop = $resp['stop_reason'] ?? '';
        if ($stop === 'refusal') {
            throw new RuntimeException('AI ปฏิเสธการประมวลผลเอกสารนี้');
        }
        if ($stop === 'max_tokens') {
            throw new RuntimeException('เอกสารมีโครงการจำนวนมากเกินกว่าที่ AI จะตอบได้ในครั้งเดียว กรุณาแยกไฟล์ PDF เป็นส่วนย่อย');
        }

        foreach ($resp['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === 'save_projects') {
                $projects = $block['input']['projects'] ?? null;
                break;
            }
        }
        $usage = $resp['usage'] ?? [];
    }

    if (!is_array($projects)) {
        error_log('AI extract: could not parse projects: ' . substr($raw, 0, 1000));
        throw new RuntimeException('AI ไม่สามารถดึงรายการโครงการจากเอกสารนี้ได้ กรุณาตรวจสอบว่าเป็นไฟล์แผนจัดซื้อจัดจ้าง');
    }

    return [
        'projects' => array_values(array_map('ai_normalize_project', $projects)),
        'usage' => $usage,
        'quota' => $resp['model_quota'] ?? null,
    ];
}

// Coerce one extracted row into the values the project form accepts
function ai_normalize_project($p) {
    $month = function ($v) {
        $v = trim((string)$v);
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v) ? $v : '';
    };
    $type = (string)($p['procurement_type'] ?? '');
    $method = (string)($p['procurement_method'] ?? '');
    return [
        'project_name' => trim((string)($p['project_name'] ?? '')),
        'budget' => round(max(0, (float)($p['budget'] ?? 0)), 2),
        'procurement_type' => in_array($type, AI_PROCUREMENT_TYPES, true) ? $type : '',
        'quantity' => trim((string)($p['quantity'] ?? '')),
        'procurement_method' => in_array($method, AI_PROCUREMENT_METHODS, true) ? $method : '',
        'required_date' => $month($p['required_date'] ?? ''),
        'request_month' => $month($p['request_month'] ?? ''),
        'contract_month' => $month($p['contract_month'] ?? ''),
        'page' => (int)($p['page'] ?? 0),
        'note' => trim((string)($p['note'] ?? '')),
    ];
}
