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
function callBitrix($method, $params, $url)
{
    $queryUrl = $url . $method . ".json";
    $queryData = http_build_query($params);
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-type: application/x-www-form-urlencoded',
            'content' => $queryData,
        ],
    ];
    $context = stream_context_create($options);
    $result = file_get_contents($queryUrl, false, $context);
    return json_decode($result, true);
}

function hasMeaningfulValue($value)
{
    if (is_array($value)) {
        if (array_key_exists('VALUE', $value)) {
            return hasMeaningfulValue($value['VALUE']);
        }

        foreach ($value as $item) {
            if (hasMeaningfulValue($item)) {
                return true;
            }
        }

        return false;
    }

    return trim((string)$value) !== '';
}

function getFirstFilledMultiField($items)
{
    if (!is_array($items)) {
        return null;
    }

    foreach ($items as $item) {
        $value = $item['VALUE'] ?? '';

        if (trim((string)$value) !== '') {
            return [
                'VALUE' => $value,
                'VALUE_TYPE' => $item['VALUE_TYPE'] ?? 'WORK'
            ];
        }
    }

    return null;
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

// Helper to format Multi-fields (Phone/Email)
$formatMultiField = function ($items) {
    $output = [];
    if (is_array($items)) {
        foreach ($items as $item) {
            $output[] = [
                "VALUE" => $item['VALUE'],
                "VALUE_TYPE" => $item['VALUE_TYPE']
            ];
        }
    }
    return $output;
};

$email_field = getFirstFilledMultiField($fields['EMAIL'] ?? []);
$phone_field = getFirstFilledMultiField($fields['PHONE'] ?? []);

$email_value = $email_field['VALUE'] ?? '';
$email_type = $email_field['VALUE_TYPE'] ?? 'WORK';
$phone_value = $phone_field['VALUE'] ?? '';
$phone_type = $phone_field['VALUE_TYPE'] ?? 'WORK';

$has_email = $email_field !== null;
$has_phone = $phone_field !== null;

if (!$has_email && !$has_phone) {
    writeLog("INFO: Skipping contact create/connect - email and phone are both empty for Lead #$lead_id.", $log_file);
    writeLog([
        'email' => $email_value,
        'phone' => $phone_value,
        'has_email_flag' => $fields['HAS_EMAIL'] ?? '',
        'has_phone_flag' => $fields['HAS_PHONE'] ?? '',
        'email_array_exists' => isset($fields['EMAIL']),
        'phone_array_exists' => isset($fields['PHONE'])
    ], $log_file);
    exit;
}

writeLog("INFO: Contact create allowed - email or phone exists for Lead #$lead_id.", $log_file);

// 4. CREATE THE CONTACT
// Map lead name fields to contact name fields (NAME = First, SECOND_NAME = Middle, LAST_NAME = Last)
$contact_params = [
    'fields' => [
        'NAME'        => $fields['NAME'] ?? '',
        'SECOND_NAME' => $fields['SECOND_NAME'] ?? '',
        'LAST_NAME'   => $fields['LAST_NAME'] ?? ''
    ]
];

// Lead PHONE/EMAIL are arrays of {VALUE, VALUE_TYPE} objects.
if (trim($email_value) !== '') {
    $contact_params['fields']['EMAIL'] = [
        [
            'VALUE'      => $email_value,
            'VALUE_TYPE' => $email_type
        ]
    ];
}

if (trim($phone_value) !== '') {
    $contact_params['fields']['PHONE'] = [
        [
            'VALUE'      => $phone_value,
            'VALUE_TYPE' => $phone_type
        ]
    ];
}

$contact_result = callBitrix('crm.contact.add', $contact_params, $rest_url);
$new_contact_id = $contact_result['result'] ?? null;

// 5. LINK LEAD TO CONTACT
if ($new_contact_id) {
    callBitrix('crm.lead.update', [
        'id' => $lead_id,
        'fields' => ['CONTACT_ID' => $new_contact_id]
    ], $rest_url);
    writeLog("SUCCESS: Lead #$lead_id linked to Contact #$new_contact_id", $log_file);
} else {
    writeLog("FAILED to create contact for Lead t #$lead_id", $log_file);
}


?>
