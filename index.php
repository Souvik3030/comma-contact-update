<?php
/**
 * 1. CONFIGURATION
 */
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
$formatMultiField = function($items) {
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

// 4. CREATE THE CONTACT
$contact_params = [
    'fields' => [
        'NAME' => $fields['UF_CRM_69D377EB8B209'] ?? '',

        'EMAIL' => [
            [
                "VALUE" => $fields['UF_CRM_69D377EB91EC4'][0] ?? '',
                "VALUE_TYPE" => "WORK"
            ]
        ],

        'PHONE' => [
            [
                "VALUE" => $fields['UF_CRM_69D377EB973D1'][0] ?? '',
                "VALUE_TYPE" => "WORK"
            ]
        ]
    ]
];

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
    writeLog("FAILED to create contact for Lead #$lead_id", $log_file);
}


/* ================================
 LINK CONTACT TO LEAD
================================ */
if ($contact_id) {

    $update = callBitrix(
        'crm.lead.update',
        [
            'id' => $lead_id,
            'fields' => [
                'CONTACT_IDS' => [$contact_id]
            ]
        ],
        $rest_url
    );

    writeLog("Lead Updated With Contact ID: $contact_id", $log_file);
    writeLog($update, $log_file);

} else {

    writeLog("ERROR: Contact creation failed", $log_file);

}
?>
