<?php
/**
 * 1. CONFIGURATION
 * OUTBOUND permission: contact created, contact updated, contact deleted, lead created
 */
$rest_url = "https://comma.bitrix24.ae/rest/8/ecvxs1hmfnz91kd9/";
$log_file = __DIR__ . '/webhook_log.txt';

function writeLog($message, $file) {
    $timestamp = date("Y-m-d H:i:s");
    $log_entry = "[$timestamp] " . (is_array($message) ? print_r($message, true) : $message) . PHP_EOL;
    file_put_contents($file, $log_entry, FILE_APPEND);
}

function callBitrix($method, $params, $url) {
    $queryUrl = $url . $method . ".json";
    $queryData = http_build_query($params);
    $options = ['http' => ['method' => 'POST', 'header' => 'Content-type: application/x-www-form-urlencoded', 'content' => $queryData]];
    $context = stream_context_create($options);
    $result = file_get_contents($queryUrl, false, $context);
    return json_decode($result, true);
}

/**
 * STRONGER CHECK: Returns null if no actual text is found
 */
function getFirstFilledMultiField($items) {
    if (!is_array($items) || empty($items)) return null;
    foreach ($items as $item) {
        $val = isset($item['VALUE']) ? trim((string)$item['VALUE']) : '';
        if ($val !== '') {
            return ['VALUE' => $val, 'VALUE_TYPE' => $item['VALUE_TYPE'] ?? 'WORK'];
        }
    }
    return null;
}

// 1. CAPTURE WEBHOOK
$lead_id = $_POST['data']['FIELDS']['ID'] ?? null;
if (!$lead_id) exit;

// 2. FETCH LEAD
$lead_result = callBitrix('crm.lead.get', ['id' => $lead_id], $rest_url);
$fields = $lead_result['result'] ?? null;
if (!$fields) exit;

// 3. EXTRACT AND VALIDATE
$email_field = getFirstFilledMultiField($fields['EMAIL'] ?? []);
$phone_field = getFirstFilledMultiField($fields['PHONE'] ?? []);

// --- THE CRITICAL SAFETY STOP ---
if ($email_field === null && $phone_field === null) {
    writeLog("STOP: Lead #$lead_id has no Email or Phone text. Exiting to prevent empty contact logic.", $log_file);
    exit; // <--- This ensures crm.contact.add/update is NEVER called.
}

// 4. PREPARE DATA
$contact_fields = [
    'NAME'         => $fields['NAME'] ?? '',
    'LAST_NAME'    => $fields['LAST_NAME'] ?? '',
    'EMAIL'        => $email_field ? [$email_field] : [],
    'PHONE'        => $phone_field ? [$phone_field] : [],
];

// 5. UPDATE OR ADD
$existing_contact_id = (int)($fields['CONTACT_ID'] ?? 0);

if ($existing_contact_id > 0) {
    callBitrix('crm.contact.update', ['id' => $existing_contact_id, 'fields' => $contact_fields], $rest_url);
    writeLog("SUCCESS: Updated Contact #$existing_contact_id", $log_file);
} else {
    $res = callBitrix('crm.contact.add', ['fields' => $contact_fields], $rest_url);
    $new_id = $res['result'] ?? null;
    if ($new_id) {
        callBitrix('crm.lead.update', ['id' => $lead_id, 'fields' => ['CONTACT_ID' => $new_id]], $rest_url);
        writeLog("SUCCESS: Created Contact #$new_id", $log_file);
    }
}