<?php
/**
 * 1. CONFIGURATION
 * OUTBOUND permission: contact created, contact updated, contact deleted, lead created
 */
$rest_url = "https://comma.bitrix24.ae/rest/8/ecvxs1hmfnz91kd9/";
$log_file = __DIR__ . '/webhook_log.txt';

function writeLog($message, $file)
{
    $timestamp = date("Y-m-d H:i:s");
    $log_entry = "[$timestamp] " . (is_array($message) ? print_r($message, true) : $message) . PHP_EOL;
    file_put_contents($file, $log_entry, FILE_APPEND);
}

/**
 * Helper function for Bitrix REST API calls
 */
function callBitrix($method, $params, $url)
{
    $queryUrl = $url . $method . ".json";
    $queryData = http_build_query($params);
    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-type: application/x-www-form-urlencoded',
            'content' => $queryData,
        ],
    ];
    $context = stream_context_create($options);
    $result = file_get_contents($queryUrl, false, $context);
    return json_decode($result, true);
}

/**
 * Helper to get the first non-empty value from Multi-fields
 */
function getFirstFilledMultiField($items)
{
    if (!is_array($items)) return null;

    foreach ($items as $item) {
        $value = $item['VALUE'] ?? '';
        if (trim((string)$value) !== '') {
            return [
                'VALUE'      => $value,
                'VALUE_TYPE' => $item['VALUE_TYPE'] ?? 'WORK'
            ];
        }
    }
    return null;
}

// 1. CAPTURE INCOMING WEBHOOK
$lead_id = $_POST['data']['FIELDS']['ID'] ?? null;

if (!$lead_id) {
    exit; // Silently exit if no ID is provided
}

// 2. FETCH FULL LEAD DETAILS
$lead_result = callBitrix('crm.lead.get', ['id' => $lead_id], $rest_url);
$fields = $lead_result['result'] ?? null;

if (!$fields) {
    writeLog("ERROR: Could not fetch details for Lead #$lead_id", $log_file);
    exit;
}

// 3. EXTRACT EMAIL AND PHONE
$email_field = getFirstFilledMultiField($fields['EMAIL'] ?? []);
$phone_field = getFirstFilledMultiField($fields['PHONE'] ?? []);

// 4. THE SAFETY CHECK (SKIP CREATION IF EMPTY)
// If both are empty, we stop. We do NOT unlink or delete anything.
if (!$email_field && !$phone_field) {
    writeLog("SKIP: Lead #$lead_id has no Email or Phone. No contact will be created/updated.", $log_file);
    exit;
}

// 5. PREPARE CONTACT DATA
$contact_params = [
    'fields' => [
        'NAME'         => $fields['NAME'] ?? '',
        'SECOND_NAME'  => $fields['SECOND_NAME'] ?? '',
        'LAST_NAME'    => $fields['LAST_NAME'] ?? '',
        'OPENED'       => 'Y',
        'EXPORT'       => 'Y'
    ]
];

if ($email_field) {
    $contact_params['fields']['EMAIL'] = [$email_field];
}

if ($phone_field) {
    $contact_params['fields']['PHONE'] = [$phone_field];
}

// 6. UPDATE OR CREATE
$existing_contact_id = $fields['CONTACT_ID'] ?? null;

if ($existing_contact_id > 0) {
    // Update existing contact
    callBitrix('crm.contact.update', [
        'id'     => $existing_contact_id,
        'fields' => $contact_params['fields']
    ], $rest_url);
    writeLog("SUCCESS: Updated existing Contact #$existing_contact_id for Lead #$lead_id", $log_file);
} else {
    // Create new contact
    $contact_add_result = callBitrix('crm.contact.add', $contact_params, $rest_url);
    $new_contact_id = $contact_add_result['result'] ?? null;

    if ($new_contact_id) {
        // Link Lead to the new Contact
        callBitrix('crm.lead.update', [
            'id'     => $lead_id,
            'fields' => ['CONTACT_ID' => $new_contact_id]
        ], $rest_url);
        writeLog("SUCCESS: Created Contact #$new_contact_id and linked to Lead #$lead_id", $log_file);
    } else {
        writeLog("FAILED: Could not create contact for Lead #$lead_id", $log_file);
    }
}
