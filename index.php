<?php
/**
 * 1. CONFIGURATION
 * OUTBOUND permission: contact created, contact updated, contact deleted, lead created
 */
$rest_url = "https://test.vortexwebre.com/rest/1/gng7u58v2pl8wpcf/";
$log_file = __DIR__ . '/webhook_log.txt';

function writeLog($message, $file) {
    $timestamp = date("Y-m-d H:i:s");
    $log_entry = "[$timestamp] " . (is_array($message) ? print_r($message, true) : $message) . PHP_EOL;
    file_put_contents($file, $log_entry, FILE_APPEND);
}

function callBitrix($method, $params, $url) {
    global $log_file;

    writeLog("BITRIX CALL START: $method", $log_file);
    writeLog(['method' => $method, 'params' => $params], $log_file);

    $queryUrl = $url . $method . ".json";
    $queryData = http_build_query($params);
    $options = ['http' => ['method' => 'POST', 'header' => 'Content-type: application/x-www-form-urlencoded', 'content' => $queryData]];
    $context = stream_context_create($options);
    $result = file_get_contents($queryUrl, false, $context);

    if ($result === false) {
        writeLog("BITRIX CALL FAILED: $method returned false", $log_file);
        return null;
    }

    $decoded = json_decode($result, true);
    writeLog("BITRIX CALL END: $method", $log_file);
    writeLog($decoded, $log_file);

    return $decoded;
}

/**
 * STRONGER CHECK: Returns null if no actual text is found
 */
function getFirstFilledMultiField($items) {
    if (!is_array($items) || empty($items)) {
        return null;
    }

    foreach ($items as $item) {
        $val = isset($item['VALUE']) ? trim((string)$item['VALUE']) : '';
        if ($val !== '') {
            return ['VALUE' => $val, 'VALUE_TYPE' => $item['VALUE_TYPE'] ?? 'WORK'];
        }
    }
    return null;
}

// 1. CAPTURE WEBHOOK
writeLog("=== PROCESS START ===", $log_file);
writeLog("STEP 1: Incoming webhook received", $log_file);
writeLog($_POST, $log_file);

$lead_id = $_POST['data']['FIELDS']['ID'] ?? null;
writeLog("STEP 1 RESULT: Extracted Lead ID", $log_file);
writeLog(['lead_id' => $lead_id], $log_file);

if (!$lead_id) {
    writeLog("STOP: No Lead ID found in webhook payload.", $log_file);
    writeLog("=== PROCESS END ===", $log_file);
    exit;
}

// 2. FETCH LEAD
writeLog("STEP 2: Fetching full lead details from Bitrix", $log_file);
$lead_result = callBitrix('crm.lead.get', ['id' => $lead_id], $rest_url);
$fields = $lead_result['result'] ?? null;

if (!$fields) {
    writeLog("STOP: Could not fetch lead details or lead result is empty.", $log_file);
    writeLog($lead_result, $log_file);
    writeLog("=== PROCESS END ===", $log_file);
    exit;
}

writeLog("STEP 2 RESULT: Full lead details fetched for Lead #$lead_id", $log_file);
writeLog($fields, $log_file);

// 3. EXTRACT AND VALIDATE
writeLog("STEP 3: Extracting first filled email and phone values", $log_file);
$email_field = getFirstFilledMultiField($fields['EMAIL'] ?? []);
$phone_field = getFirstFilledMultiField($fields['PHONE'] ?? []);

writeLog("STEP 3 RESULT: Extracted contact communication values", $log_file);
writeLog([
    'email_field' => $email_field,
    'phone_field' => $phone_field,
    'has_email_flag' => $fields['HAS_EMAIL'] ?? '',
    'has_phone_flag' => $fields['HAS_PHONE'] ?? '',
    'email_array_exists' => isset($fields['EMAIL']),
    'phone_array_exists' => isset($fields['PHONE']),
    'existing_contact_id' => $fields['CONTACT_ID'] ?? ''
], $log_file);

// --- THE CRITICAL SAFETY STOP ---
writeLog("STEP 4: Validating email/phone presence before contact create/update", $log_file);
if ($email_field === null && $phone_field === null) {
    writeLog("STOP: Lead #$lead_id has no Email or Phone text. Exiting to prevent empty contact logic.", $log_file);
    writeLog("=== PROCESS END ===", $log_file);
    exit; // <--- This ensures crm.contact.add/update is NEVER called.
}

writeLog("STEP 4 RESULT: Validation passed. Email or phone exists.", $log_file);

// 4. PREPARE DATA
writeLog("STEP 5: Preparing contact fields", $log_file);
$contact_fields = [
    'NAME'         => $fields['NAME'] ?? '',
    'LAST_NAME'    => $fields['LAST_NAME'] ?? '',
    'EMAIL'        => $email_field ? [$email_field] : [],
    'PHONE'        => $phone_field ? [$phone_field] : [],
];

writeLog("STEP 5 RESULT: Contact fields prepared", $log_file);
writeLog($contact_fields, $log_file);

// 5. UPDATE OR ADD
writeLog("STEP 6: Checking whether lead already has a linked contact", $log_file);
$existing_contact_id = (int)($fields['CONTACT_ID'] ?? 0);
writeLog(['existing_contact_id' => $existing_contact_id], $log_file);

if ($existing_contact_id > 0) {
    writeLog("STEP 7: Updating existing Contact #$existing_contact_id", $log_file);
    $update_result = callBitrix('crm.contact.update', ['id' => $existing_contact_id, 'fields' => $contact_fields], $rest_url);
    writeLog("SUCCESS: Updated Contact #$existing_contact_id", $log_file);
    writeLog($update_result, $log_file);
} else {
    writeLog("STEP 7: No existing contact found. Creating new contact.", $log_file);
    $res = callBitrix('crm.contact.add', ['fields' => $contact_fields], $rest_url);
    $new_id = $res['result'] ?? null;
    writeLog("STEP 7 RESULT: Contact create response", $log_file);
    writeLog($res, $log_file);

    if ($new_id) {
        writeLog("STEP 8: Linking Lead #$lead_id to new Contact #$new_id", $log_file);
        $link_result = callBitrix('crm.lead.update', ['id' => $lead_id, 'fields' => ['CONTACT_ID' => $new_id]], $rest_url);
        writeLog("SUCCESS: Created Contact #$new_id", $log_file);
        writeLog("SUCCESS: Linked Lead #$lead_id to Contact #$new_id", $log_file);
        writeLog($link_result, $log_file);
    } else {
        writeLog("FAILED: Contact was not created. No contact ID returned.", $log_file);
    }
}

writeLog("=== PROCESS END ===", $log_file);
