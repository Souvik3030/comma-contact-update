<?php
/**
 * 1. CONFIGURATION
 */
$rest_url = "https://test.vortexwebre.com/rest/1/4xmt5rq9imvnzhv4/";
$logs = [];

function writeLog($message) {
    global $logs;

    $timestamp = date("Y-m-d H:i:s");
    $logs[] = [
        'time' => $timestamp,
        'message' => $message
    ];
}

function respondWithLogs($success, $extra = []) {
    global $logs;

    header('Content-Type: application/json');
    echo json_encode(array_merge([
        'success' => $success,
        'logs' => $logs
    ], $extra), JSON_PRETTY_PRINT);
    exit;
}

function normalizeMultiField($items) {
    $output = [];

    if (!is_array($items)) {
        return $output;
    }

    foreach ($items as $item) {
        if (!empty($item['VALUE'])) {
            $output[] = [
                'VALUE' => $item['VALUE'],
                'VALUE_TYPE' => $item['VALUE_TYPE'] ?? 'WORK'
            ];
        }
    }

    return $output;
}

function firstNonEmptyValue(...$values) {
    foreach ($values as $value) {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    return '';
}

// 2. CAPTURE INCOMING WEBHOOK (Only contains ID)
writeLog("=== INCOMING BITRIX WEBHOOK POST DATA ===");
writeLog($_POST);

$lead_id = $_POST['data']['FIELDS']['ID'] ?? null;

if (!$lead_id) {
    writeLog("ERROR: No Lead ID received. Exiting.");
    respondWithLogs(false);
}

/**
 * Helper function for Bitrix REST API calls
 */
function callBitrix($method, $params, $url) {
    $queryUrl = $url . $method . ".json";
    $queryData = http_build_query($params);
    writeLog("Calling Bitrix API: " . $method);
    writeLog([
        'url' => $queryUrl,
        'params' => $params
    ]);

    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-type: application/x-www-form-urlencoded',
            'content' => $queryData,
        ],
    ];
    $context  = stream_context_create($options);
    $result = file_get_contents($queryUrl, false, $context);
    $decoded = json_decode($result, true);

    writeLog([
        'method' => $method,
        'response' => $decoded
    ]);

    return $decoded;
}

// =========================================================================
// 3. FETCH FULL LEAD DETAILS (This is where the actual form data is)
// =========================================================================
$lead_result = callBitrix('crm.lead.get', ['id' => $lead_id], $rest_url);
$fields = $lead_result['result'] ?? null;

if ($fields) {
    writeLog("--- FULL EXTRACTED FORM DATA FOR LEAD #$lead_id ---");
    writeLog($fields); // THIS WILL LOG NAME, PHONE, EMAIL, ETC.
} else {
    writeLog("ERROR: Could not fetch details for Lead #$lead_id");
    respondWithLogs(false, ['lead_id' => $lead_id]);
}

$lead_name = firstNonEmptyValue(
    $fields['NAME'] ?? '',
    $fields['TITLE'] ?? '',
    $fields['UF_CRM_69D377EB8B209'] ?? ''
);
$lead_emails = normalizeMultiField($fields['EMAIL'] ?? []);
$lead_phones = normalizeMultiField($fields['PHONE'] ?? []);

if (!$lead_emails && !empty($fields['UF_CRM_69D377EB973D1'][0])) {
    $lead_emails[] = [
        'VALUE' => $fields['UF_CRM_69D377EB973D1'][0],
        'VALUE_TYPE' => 'WORK'
    ];
}

if (!$lead_phones && !empty($fields['UF_CRM_69D377EB91EC4'][0])) {
    $lead_phones[] = [
        'VALUE' => $fields['UF_CRM_69D377EB91EC4'][0],
        'VALUE_TYPE' => 'WORK'
    ];
}

writeLog([
    'prepared_contact_data' => [
        'NAME' => $lead_name,
        'EMAIL' => $lead_emails,
        'PHONE' => $lead_phones
    ]
]);

if ($lead_name === '' && !$lead_emails && !$lead_phones) {
    writeLog("ERROR: Lead does not contain name, email, or phone. Contact was not created.");
    respondWithLogs(false, ['lead_id' => $lead_id]);
}

// 4. CREATE THE CONTACT
$contact_params = [
    'fields' => [
        'NAME' => $lead_name,
        'EMAIL' => $lead_emails,
        'PHONE' => $lead_phones
    ]
];

$contact_result = callBitrix('crm.contact.add', $contact_params, $rest_url);
$new_contact_id = $contact_result['result'] ?? null;

// 5. LINK LEAD TO CONTACT
if ($new_contact_id) {
    $lead_update_result = callBitrix('crm.lead.update', [
        'id' => $lead_id,
        'fields' => [
            'CONTACT_ID' => $new_contact_id,
            'CONTACT_IDS' => [$new_contact_id]
        ]
    ], $rest_url);

    writeLog("SUCCESS: Lead #$lead_id linked to Contact #$new_contact_id");
    writeLog($lead_update_result);
} else {
    writeLog("FAILED to create contact for Lead #$lead_id");
    respondWithLogs(false, ['lead_id' => $lead_id]);
}


respondWithLogs(true, [
    'lead_id' => $lead_id,
    'contact_id' => $new_contact_id
]);
