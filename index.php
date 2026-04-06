<?php
/**
 * 1. CONFIGURATION
 */
$rest_url = "https://test.vortexwebre.com/rest/1/4xmt5rq9imvnzhv4/";
$log_file = __DIR__ . '/webhook_log.txt';

function callBitrix($method, $params, $url) {
    $queryUrl = $url . $method . ".json";
    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-type: application/x-www-form-urlencoded',
            'content' => http_build_query($params),
        ],
    ];
    $context = stream_context_create($options);
    $result = file_get_contents($queryUrl, false, $context);
    return json_decode($result, true);
}

// 2. CAPTURE LEAD ID FROM WEBHOOK
$lead_id = $_POST['data']['FIELDS']['ID'] ?? null;

if (!$lead_id) {
    exit; // No lead ID, nothing to do
}

// 3. FETCH BUILT-IN LEAD FIELDS
$lead_data = callBitrix('crm.lead.get', ['id' => $lead_id], $rest_url);
$fields = $lead_data['result'] ?? null;

if ($fields) {
    // Extract System Fields
    $first_name = $fields['NAME'] ?? '';
    $last_name  = $fields['LAST_NAME'] ?? '';
    $phones     = $fields['PHONE'] ?? [];
    $emails     = $fields['EMAIL'] ?? [];

    // 4. CREATE THE CONTACT
    // We pass the Name, and the Phone/Email arrays directly
    $contact_params = [
        'fields' => [
            'NAME'      => $first_name,
            'LAST_NAME' => $last_name,
            'OPENED'    => 'Y',
            'PHONE'     => $phones,
            'EMAIL'     => $emails,
            'TYPE_ID'   => 'CLIENT'
        ]
    ];

    $contact_result = callBitrix('crm.contact.add', $contact_params, $rest_url);
    $new_contact_id = $contact_result['result'] ?? null;

    // 5. ATTACH CONTACT TO THE ORIGINAL LEAD
    if ($new_contact_id) {
        callBitrix('crm.lead.update', [
            'id' => $lead_id,
            'fields' => [
                'CONTACT_ID' => $new_contact_id // This "attaches" it
            ]
        ], $rest_url);
    }
}
?>