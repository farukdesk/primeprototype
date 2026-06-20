/**
 * 🚀 Simple Firebase Notification Function (Using Fixed Server Key)
 * কোনো OAuth2 বা অতিরিক্ত ফাইলের ঝামেলা ছাড়াই এটি সরাসরি নোটিফিকেশন পাঠাবে।
 */
function send_pumis_broadcast($title, $body) {
    $url = 'https://fcm.googleapis.com/fcm/send';
    
    // ⚠️ আপনার Firebase থেকে পাওয়া Server Key টি শুধু এখানে বসিয়ে দেবেন
    $server_key = 'pusp-e239c'; 

    $payload = [
        'to' => '/topics/pumis_broadcast',
        'notification' => [
            'title' => $title,
            'body' => strlen($body) > 120 ? substr(strip_tags($body), 0, 120) . '...' : strip_tags($body),
            'sound' => 'default',
            'badge' => '1'
        ],
        'priority' => 'high'
    ];

    $headers = [
        'Authorization: key=' . $server_key,
        'Content-Type: application/json'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $response = curl_exec($ch);
    curl_close($ch);

    return $response;
}
