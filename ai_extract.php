<?php
// ai_extract.php - Read an annual procurement plan (PDF or page images) with an AI model and extract its projects.
//
// Providers are tried in order until one succeeds:
//   1. Google Gemini API (AI Studio key)   - accepts the whole PDF (~20 MB request limit), JSON output via responseSchema
//   2. Anthropic-compatible gateway (KKU)  - ~1 MB request limit, so large/scanned PDFs are sent as page images in
//                                            batches rendered in the browser; JSON output via a strict tool
// Within a provider the models are rotated as well, because each model has its own daily quota.

// ----- Configuration (config.php or environment) -----
// Backwards compatibility: AI_BASE_URL/AI_API_KEY/AI_MODEL describe Gemini when the URL points at Google, else the gateway.
$ai_legacy_is_gemini = defined('AI_BASE_URL') && strpos(AI_BASE_URL, 'generativelanguage.googleapis.com') !== false;
$ai_list = function ($const, $env, array $default) {
    if (defined($const)) return (array)constant($const);
    $value = getenv($env);
    return $value ? array_map('trim', explode(',', $value)) : $default;
};

if (!defined('GEMINI_API_KEY')) define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: ($ai_legacy_is_gemini && defined('AI_API_KEY') ? AI_API_KEY : ''));
if (!defined('GEMINI_BASE_URL')) define('GEMINI_BASE_URL', getenv('GEMINI_BASE_URL') ?: ($ai_legacy_is_gemini ? AI_BASE_URL : 'https://generativelanguage.googleapis.com/v1beta'));
define('AI_GEMINI_MODELS', $ai_list('GEMINI_MODELS', 'GEMINI_MODELS', array_values(array_unique(array_merge(
    $ai_legacy_is_gemini && defined('AI_MODEL') ? [AI_MODEL] : [],
    ['gemini-3.5-flash-lite', 'gemini-3.8-flash', 'gemini-3.5-flash', 'gemini-3.1-pro-preview']
)))));

if (!defined('GATEWAY_BASE_URL')) define('GATEWAY_BASE_URL', getenv('GATEWAY_BASE_URL') ?: (!$ai_legacy_is_gemini && defined('AI_BASE_URL') ? AI_BASE_URL : ''));
if (!defined('GATEWAY_API_KEY')) define('GATEWAY_API_KEY', getenv('GATEWAY_API_KEY') ?: (!$ai_legacy_is_gemini && defined('AI_API_KEY') ? AI_API_KEY : ''));
define('AI_GATEWAY_MODELS', $ai_list('GATEWAY_MODELS', 'GATEWAY_MODELS', defined('AI_MODELS') ? (array)AI_MODELS : (
    !$ai_legacy_is_gemini && defined('AI_MODEL') ? [AI_MODEL] :
    ['claude-sonnet-5.5', 'claude-sonnet-5', 'claude-sonnet-4.6', 'gemini-3.1-pro-preview', 'gpt-6-sol-pro', 'gemini-3.8-flash', 'gpt-5.4']
)));
if (!defined('GATEWAY_MAX_REQUEST_BYTES')) define('GATEWAY_MAX_REQUEST_BYTES', (int)(getenv('GATEWAY_MAX_REQUEST_BYTES') ?: 1000000));
// Optional CA bundle for servers whose PHP has no curl.cainfo configured (TLS verification is never disabled)
if (!defined('AI_CA_BUNDLE')) define('AI_CA_BUNDLE', getenv('AI_CA_BUNDLE') ?: '');
unset($ai_legacy_is_gemini, $ai_list);

const GEMINI_MAX_REQUEST_BYTES = 19 * 1024 * 1024; // Gemini inline data limit is 20 MB per request
const AI_PROCUREMENT_TYPES = ['จัดซื้อ', 'จัดจ้าง', 'เช่า'];
const AI_PROCUREMENT_METHODS = ['เฉพาะเจาะจง', 'ประกวดราคาอิเล็กทรอนิกส์ (e-bidding)', 'คัดเลือก', 'สอบราคา'];
// Raw image bytes per batch that stay under the gateway body limit after base64 (+33%) and JSON overhead
const AI_MAX_BATCH_IMAGE_BYTES = 700 * 1024;

// Thrown when the document is too large for every available provider as a single request:
// the browser should retry by sending page images in batches.
class AiNeedsPageImagesException extends RuntimeException {}

function ai_gemini_enabled() {
    return GEMINI_API_KEY !== '' && !empty(AI_GEMINI_MODELS);
}

function ai_gateway_enabled() {
    return GATEWAY_BASE_URL !== '' && GATEWAY_API_KEY !== '' && !empty(AI_GATEWAY_MODELS);
}

function ai_is_configured() {
    return ai_gemini_enabled() || ai_gateway_enabled();
}

// ----- Shared prompt and schema -----

function ai_system_prompt($fiscal_year_be) {
    $fy = (int)$fiscal_year_be;
    return "You extract procurement projects from Thai government annual procurement plans (แผนการจัดซื้อจัดจ้างประจำปี) "
        . "of the School of Medical Sciences, University of Phayao. Documents are often scanned.\n"
        . "The plan is for fiscal year B.E. {$fy}, which runs from October " . ($fy - 544) . " to September " . ($fy - 543) . " (Gregorian). "
        . "Short Thai dates like \"พ.ย. 68\" or \"มี.ค.70\" mean that month of B.E. 2568 / 2570; resolve months without a year using this fiscal year.\n"
        . "Rules:\n"
        . "- Include every project row. Skip cover memos (บันทึกข้อความ), header rows, subtotal/grand-total rows and signature blocks.\n"
        . "- Copy names exactly as written, including codes in parentheses; do not translate or summarise.\n"
        . "- Never invent values: use \"\" for text you cannot find and explain in note. If a budget is unreadable, give your best reading and say so in note.\n"
        . "- Write every note in Thai.";
}

// JSON schema of one extracted project (shared by the Gemini responseSchema and the gateway tool)
function ai_project_schema() {
    $month = ['type' => 'string', 'description' => 'Gregorian year-month "YYYY-MM" (convert Buddhist Era years by subtracting 543), or "" if the plan does not state it'];
    return [
        'type' => 'object',
        'properties' => [
            'project_name' => ['type' => 'string', 'description' => 'Project / item name exactly as written in the plan (Thai)'],
            'budget' => ['type' => 'number', 'description' => 'Budget in baht as a plain number, e.g. 450000.00'],
            'procurement_type' => ['type' => 'string', 'enum' => AI_PROCUREMENT_TYPES],
            'quantity' => ['type' => 'string', 'description' => 'Quantity with unit as written, e.g. "8 รายการ", "1 ชุด"; "" if not stated'],
            'procurement_method' => ['type' => 'string', 'enum' => AI_PROCUREMENT_METHODS, 'description' => 'Closest matching method; e-bidding = ประกวดราคาอิเล็กทรอนิกส์'],
            'required_date' => ['description' => 'Month the goods/work are needed. ' . $month['description']] + $month,
            'request_month' => ['description' => 'Month of the purchase request (ขอซื้อขอจ้าง). ' . $month['description']] + $month,
            'contract_month' => ['description' => 'Month of contract / purchase order. ' . $month['description']] + $month,
            'page' => ['type' => 'integer', 'description' => 'Document page number (1-based) where the project row appears'],
            'note' => ['type' => 'string', 'description' => 'Short Thai note for the reviewer when a value was unclear, guessed or mapped; "" otherwise'],
        ],
        'required' => ['project_name', 'budget', 'procurement_type', 'quantity', 'procurement_method', 'required_date', 'request_month', 'contract_month', 'page', 'note'],
    ];
}

// ----- Public entry points -----

/**
 * Extract the projects from a whole plan PDF.
 *
 * @throws AiNeedsPageImagesException when the file is too large for every provider (send page images instead)
 * @throws RuntimeException with a Thai message suitable for showing to staff
 */
function ai_extract_projects($pdf_path, $fiscal_year_be) {
    if (!is_file($pdf_path)) {
        throw new RuntimeException('ไม่พบไฟล์ PDF ของแผนงานบนเซิร์ฟเวอร์');
    }
    return ai_request_projects([
        ['kind' => 'file', 'mime' => 'application/pdf', 'data' => base64_encode(file_get_contents($pdf_path))],
        ['kind' => 'text', 'text' => 'Extract all procurement projects from this plan.'],
    ], $fiscal_year_be);
}

/**
 * Extract the projects from a batch of page images (JPEG) of a plan.
 *
 * @param array $pages list of ['page' => int, 'path' => string]
 * @throws RuntimeException with a Thai message suitable for showing to staff
 */
function ai_extract_from_images(array $pages, $fiscal_year_be, $total_pages) {
    $parts = [];
    foreach ($pages as $p) {
        $parts[] = ['kind' => 'text', 'text' => 'Page ' . (int)$p['page'] . ' of ' . (int)$total_pages . ':'];
        $parts[] = ['kind' => 'file', 'mime' => 'image/jpeg', 'data' => base64_encode(file_get_contents($p['path']))];
    }
    $parts[] = ['kind' => 'text', 'text' => 'Extract the project rows that appear on these pages.'];
    return ai_request_projects($parts, $fiscal_year_be);
}

// ----- Provider rotation -----

/**
 * Send the content to the first provider/model that succeeds.
 *
 * @param array $parts list of ['kind' => 'text', 'text' => ...] or ['kind' => 'file', 'mime' => ..., 'data' => base64]
 * @return array{projects: array<int, array>, usage: array, quota: ?array, model: string}
 */
function ai_request_projects(array $parts, $fiscal_year_be) {
    if (!ai_is_configured()) {
        throw new RuntimeException('ยังไม่ได้ตั้งค่าบริการ AI (GEMINI_API_KEY หรือ GATEWAY_API_KEY) ในไฟล์ config.php');
    }
    $system = ai_system_prompt($fiscal_year_be);
    $payload_bytes = array_sum(array_map(function ($p) { return strlen($p['data'] ?? $p['text'] ?? ''); }, $parts));

    $targets = [];
    if (ai_gemini_enabled()) {
        foreach (AI_GEMINI_MODELS as $m) $targets[] = ['gemini', $m];
    }
    if (ai_gateway_enabled()) {
        foreach (AI_GATEWAY_MODELS as $m) $targets[] = ['gateway', $m];
    }

    $tried = 0;
    $too_large = 0; // attempts the provider rejected as too large (HTTP 413)
    $quota_exhausted = 0;
    $auth_failed = 0;
    $skipped = 0;   // providers whose request limit is smaller than this document
    $dead_providers = []; // a rejected key fails the same way for every model of that provider
    $failures = [];
    foreach ($targets as [$provider, $model]) {
        if (isset($dead_providers[$provider])) {
            continue;
        }
        $limit = $provider === 'gemini' ? GEMINI_MAX_REQUEST_BYTES : GATEWAY_MAX_REQUEST_BYTES;
        if ($payload_bytes + 20000 > $limit) {
            $skipped++;
            continue; // known to be too large for this provider: don't spend a request on it
        }
        $tried++;
        $outcome = $provider === 'gemini' ? ai_call_gemini($model, $system, $parts) : ai_call_gateway($model, $system, $parts);

        if ($outcome['ok']) {
            $outcome['result']['model'] = $model;
            return $outcome['result'];
        }
        if ($outcome['fatal'] ?? false) {
            throw new RuntimeException($outcome['error']);
        }
        if (($outcome['reason'] ?? '') === 'too_large') {
            $too_large++;
        } elseif (($outcome['reason'] ?? '') === 'quota') {
            $quota_exhausted++;
        } elseif (($outcome['reason'] ?? '') === 'auth') {
            $auth_failed++;
            $dead_providers[$provider] = true;
        }
        $failures[] = "$provider/$model: " . $outcome['error'];
    }

    if ($failures) {
        error_log('AI extract failed: ' . implode(' | ', $failures));
    }
    // The document could not be handled in one request but a provider with a smaller limit is available:
    // the browser should send page images in batches (they also go through every provider again)
    if ($tried === 0 || $too_large === $tried || $skipped > 0) {
        throw new AiNeedsPageImagesException('ไฟล์มีขนาดใหญ่เกินกว่าที่บริการ AI รับได้ในครั้งเดียว');
    }
    if ($auth_failed > 0 && $auth_failed + $quota_exhausted === $tried) {
        throw new RuntimeException('API key ของบริการ AI ไม่ถูกต้องหรือหมดสิทธิ์ใช้งาน (หรือโควตาหมด) กรุณาตรวจสอบการตั้งค่าใน config.php');
    }
    if ($quota_exhausted > 0 && $quota_exhausted === $tried) {
        throw new RuntimeException('โควตา AI ของทุกโมเดล (' . $tried . ' โมเดล) หมดแล้วสำหรับวันนี้ กรุณาลองใหม่พรุ่งนี้');
    }
    throw new RuntimeException('บริการ AI ไม่สามารถประมวลผลได้ในขณะนี้ (ลองครบ ' . $tried . ' โมเดลแล้ว) กรุณาลองใหม่อีกครั้ง');
}

// ----- HTTP -----

// One HTTP call; returns [status, decoded response or null, raw body or transport error]
function ai_http_post($url, array $headers, array $body) {
    $ch = curl_init($url);
    $options = [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 240,
        CURLOPT_HTTPHEADER => array_merge(['content-type: application/json'], $headers),
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    ];
    if (AI_CA_BUNDLE !== '' && is_file(AI_CA_BUNDLE)) {
        $options[CURLOPT_CAINFO] = AI_CA_BUNDLE;
    }
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        if (stripos($error, 'certificate') !== false || stripos($error, 'SSL') !== false) {
            error_log('AI extract TLS error: ' . $error . ' - set curl.cainfo in php.ini or AI_CA_BUNDLE in config.php');
        }
        return [0, null, $error];
    }
    return [$status, json_decode($raw, true), $raw];
}

// ----- Google Gemini API -----

// Convert the JSON schema to Gemini's OpenAPI-style responseSchema
function ai_gemini_schema(array $schema) {
    $out = [];
    foreach ($schema as $key => $value) {
        if ($key === 'type') {
            $out['type'] = strtoupper($value);
        } elseif ($key === 'properties') {
            $out['properties'] = array_map('ai_gemini_schema', $value);
            $out['propertyOrdering'] = array_keys($value);
        } elseif ($key === 'items') {
            $out['items'] = ai_gemini_schema($value);
        } elseif (in_array($key, ['required', 'enum', 'description'], true)) {
            $out[$key] = $value;
        }
    }
    return $out;
}

function ai_call_gemini($model, $system, array $parts) {
    $gemini_parts = [];
    foreach ($parts as $p) {
        $gemini_parts[] = $p['kind'] === 'text'
            ? ['text' => $p['text']]
            : ['inline_data' => ['mime_type' => $p['mime'], 'data' => $p['data']]];
    }
    $body = [
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'contents' => [['role' => 'user', 'parts' => $gemini_parts]],
        'generationConfig' => [
            'responseMimeType' => 'application/json',
            'responseSchema' => ai_gemini_schema([
                'type' => 'object',
                'properties' => ['projects' => ['type' => 'array', 'items' => ai_project_schema()]],
                'required' => ['projects'],
            ]),
            'maxOutputTokens' => 32768, // includes thinking tokens on Gemini 2.5+/3
        ],
    ];
    // Key goes in a header, not the URL, so it never ends up in proxy/access logs
    $url = rtrim(GEMINI_BASE_URL, '/') . '/models/' . rawurlencode($model) . ':generateContent';
    [$status, $resp, $raw] = ai_http_post($url, ['x-goog-api-key: ' . GEMINI_API_KEY], $body);

    if ($status === 200 && is_array($resp)) {
        $candidate = $resp['candidates'][0] ?? [];
        $finish = $candidate['finishReason'] ?? '';
        $text = '';
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (empty($part['thought']) && isset($part['text'])) $text .= $part['text'];
        }
        $parsed = json_decode($text, true);
        $projects = is_array($parsed) ? ($parsed['projects'] ?? (array_is_list($parsed) ? $parsed : null)) : null;
        if ($finish === 'MAX_TOKENS') {
            return ['ok' => false, 'fatal' => true, 'error' => 'เอกสารมีโครงการจำนวนมากเกินกว่าที่ AI จะตอบได้ในครั้งเดียว กรุณาแยกไฟล์ PDF เป็นส่วนย่อย'];
        }
        if (!is_array($projects)) {
            $block = $resp['promptFeedback']['blockReason'] ?? '';
            return ['ok' => false, 'error' => "no JSON result (finishReason=$finish $block)"];
        }
        $usage = $resp['usageMetadata'] ?? [];
        return ['ok' => true, 'result' => [
            'projects' => array_values(array_map('ai_normalize_project', $projects)),
            'usage' => [
                'input_tokens' => (int)($usage['promptTokenCount'] ?? 0),
                'output_tokens' => (int)($usage['candidatesTokenCount'] ?? 0) + (int)($usage['thoughtsTokenCount'] ?? 0),
            ],
            'quota' => null,
        ]];
    }

    $error = is_array($resp) ? ($resp['error'] ?? []) : [];
    $message = is_array($error) ? (string)($error['message'] ?? '') : (string)$error;
    $api_status = is_array($error) ? (string)($error['status'] ?? '') : '';
    $detail = "HTTP $status $api_status " . substr($message !== '' ? $message : (string)$raw, 0, 200);
    if ($status === 413) {
        return ['ok' => false, 'reason' => 'too_large', 'error' => $detail];
    }
    if ($status === 429 || $api_status === 'RESOURCE_EXHAUSTED') {
        return ['ok' => false, 'reason' => 'quota', 'error' => $detail];
    }
    if ($status === 401 || $status === 403 || stripos($message, 'API key') !== false) {
        return ['ok' => false, 'reason' => 'auth', 'error' => $detail];
    }
    // Overloaded (503), model not found (404), server or network error: try the next model/provider
    return ['ok' => false, 'error' => $detail];
}

// ----- Anthropic-compatible gateway -----

function ai_call_gateway($model, $system, array $parts) {
    $content = [];
    foreach ($parts as $p) {
        if ($p['kind'] === 'text') {
            $content[] = ['type' => 'text', 'text' => $p['text']];
        } else {
            $type = $p['mime'] === 'application/pdf' ? 'document' : 'image';
            $content[] = ['type' => $type, 'source' => ['type' => 'base64', 'media_type' => $p['mime'], 'data' => $p['data']]];
        }
    }
    // The gateway ignores output_config.format, so the result is collected through a strict tool
    $tool = [
        'name' => 'save_projects',
        'description' => 'Save every procurement project listed in the annual procurement plan document.',
        'strict' => true,
        'input_schema' => [
            'type' => 'object',
            'properties' => ['projects' => ['type' => 'array', 'items' => ai_project_schema() + ['additionalProperties' => false]]],
            'required' => ['projects'],
            'additionalProperties' => false,
        ],
    ];
    $body = [
        'model' => $model,
        'max_tokens' => 16000,
        'stream' => false,
        'system' => $system . "\n- Call the save_projects tool exactly once with all projects (an empty list if there are none). Do not answer in plain text.",
        'tools' => [$tool],
        'tool_choice' => ['type' => 'auto'],
        'messages' => [['role' => 'user', 'content' => $content]],
    ];
    [$status, $resp, $raw] = ai_http_post(rtrim(GATEWAY_BASE_URL, '/') . '/messages', [
        'anthropic-version: 2023-06-01',
        'x-api-key: ' . GATEWAY_API_KEY,
    ], $body);

    if ($status === 200 && is_array($resp)) {
        $stop = $resp['stop_reason'] ?? '';
        if ($stop === 'max_tokens') {
            return ['ok' => false, 'fatal' => true, 'error' => 'เอกสารมีโครงการจำนวนมากเกินกว่าที่ AI จะตอบได้ในครั้งเดียว กรุณาแยกไฟล์ PDF เป็นส่วนย่อย'];
        }
        foreach ($resp['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === 'save_projects' && is_array($block['input']['projects'] ?? null)) {
                return ['ok' => true, 'result' => [
                    'projects' => array_values(array_map('ai_normalize_project', $block['input']['projects'])),
                    'usage' => [
                        'input_tokens' => (int)($resp['usage']['input_tokens'] ?? 0),
                        'output_tokens' => (int)($resp['usage']['output_tokens'] ?? 0),
                    ],
                    'quota' => $resp['model_quota'] ?? null,
                ]];
            }
        }
        // Refusal or a plain-text answer: let the next model try
        return ['ok' => false, 'error' => "no save_projects call (stop_reason=$stop)"];
    }

    $error = is_array($resp) ? ($resp['error'] ?? $resp['message'] ?? '') : '';
    $message = is_array($error) ? (string)($error['message'] ?? json_encode($error)) : (string)$error;
    $detail = "HTTP $status " . substr($message !== '' ? $message : (string)$raw, 0, 200);
    if ($status === 413) {
        return ['ok' => false, 'reason' => 'too_large', 'error' => $detail];
    }
    // The gateway reports an exhausted daily quota as 401 "This model reached daily limit."
    if ($status === 429 || stripos($message, 'limit') !== false || stripos($message, 'quota') !== false) {
        return ['ok' => false, 'reason' => 'quota', 'error' => $detail];
    }
    if (($status === 401 || $status === 403) && stripos($message, 'model') === false) {
        return ['ok' => false, 'reason' => 'auth', 'error' => $detail];
    }
    return ['ok' => false, 'error' => $detail];
}

// Coerce one extracted row into the values the project form accepts
function ai_normalize_project($p) {
    $month = function ($v) {
        $v = trim((string)$v);
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v) ? $v : '';
    };
    $p = is_array($p) ? $p : [];
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
