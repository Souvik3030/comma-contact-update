<?php
/** * 1. CONFIGURATION */
$rest_url = "https://test.vortexwebre.com/rest/1/4xmt5rq9imvnzhv4/";
$log_file = __DIR__ . '/webhook_log.txt';

function writeLog($message, $file) {
    $timestamp = date("Y-m-d H:i:s");
    $log_entry = "[$timestamp] " . (is_array($message) ? print_r($message, true) : $message) . PHP_EOL;
    file_put_contents($file, $log_entry, FILE_APPEND);
}

// 2. CAPTURE INCOMING WEBHOOK (Only contains ID)
writeLog("=== INCOMING BITRIX WEBHOOK POST DATA ===", $log_file);
writeLog($_POST, $log_file);

$lead_id = $_POST['data']['FIELDS']['ID'] ?? null;

if (!$lead_id) {
    writeLog("ERROR: No Lead ID received. Exiting.", $log_file);
    exit;
}

/**
 * Helper function for Bitrix REST API calls
 */
function callBitrix($method, $params, $url) {
    $queryUrl = $url . $method . ".json";
    $queryData = http_build_query($params);
    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-type: application/x-www-form-urlencoded',
            'content' => $queryData,
        ],
    ];
    $context  = stream_context_create($options);
    $result = file_get_contents($queryUrl, false, $context);
    return json_decode($result, true);
}

function getLeadName($fields) {
    $fullName = trim(implode(' ', array_filter([
        $fields['NAME'] ?? '',
        $fields['SECOND_NAME'] ?? '',
        $fields['LAST_NAME'] ?? ''
    ])));

    return $fullName;
}

function getMultiFieldValue($fields, $fieldKey) {
    if (!isset($fields[$fieldKey])) {
        return '';
    }

    $value = $fields[$fieldKey];

    if (is_array($value)) {
        if (isset($value[0]['VALUE'])) {
            return (string)$value[0]['VALUE'];
        }

        if (isset($value[0])) {
            return (string)$value[0];
        }
    }

    return (string)$value;
}

// =========================================================================
// 3. FETCH FULL LEAD DETAILS (This is where the actual form data is)
// =========================================================================
$lead_result = callBitrix('crm.lead.get', ['id' => $lead_id], $rest_url);
$fields = $lead_result['result'] ?? null;

if ($fields) {
    writeLog("--- FULL EXTRACTED FORM DATA FOR LEAD #$lead_id ---", $log_file);
    writeLog($fields, $log_file); // THIS WILL LOG NAME, PHONE, EMAIL, ETC.
} else {
    writeLog("ERROR: Could not fetch details for Lead #$lead_id", $log_file);
    exit;
}

$contact_name = getLeadName($fields);
$contact_email = getMultiFieldValue($fields, 'EMAIL');
$contact_phone = getMultiFieldValue($fields, 'PHONE');


// 4. CREATE THE CONTACT
$contact_fields = [
    'NAME' => $contact_name
];

if ($contact_email !== '') {
    $contact_fields['EMAIL'] = [
        [
            "VALUE" => $contact_email,
            "VALUE_TYPE" => "WORK"
        ]
    ];
}

if ($contact_phone !== '') {
    $contact_fields['PHONE'] = [
        [
            "VALUE" => $contact_phone,
            "VALUE_TYPE" => "WORK"
        ]
    ];
}

writeLog("Preparing contact from lead #$lead_id: NAME={$contact_name}, EMAIL={$contact_email}, PHONE={$contact_phone}", $log_file);

$contact_params = [
    'fields' => $contact_fields
];

$contact_result = callBitrix('crm.contact.add', $contact_params, $rest_url);
$new_contact_id = $contact_result['result'] ?? null;
writeLog($contact_result, $log_file);

// 5. LINK LEAD TO CONTACT
if ($new_contact_id) {
    $attach = callBitrix('crm.lead.contact.items.set', [
        'id' => $lead_id,
        'items' => [
            ['CONTACT_ID' => $new_contact_id]
        ]
    ], $rest_url);

    $update = callBitrix('crm.lead.update', [
        'id' => $lead_id,
        'fields' => ['CONTACT_ID' => $new_contact_id]
    ], $rest_url);
    
    writeLog("SUCCESS: Lead #$lead_id linked to Contact #$new_contact_id", $log_file);
    writeLog($attach, $log_file);
    writeLog($update, $log_file); // Log the update response for debugging
} else {
    writeLog("FAILED to create contact for Lead #$lead_id", $log_file);
}
?>
