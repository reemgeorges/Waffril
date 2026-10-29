<?php

namespace App\Http\Traits;

use GuzzleHttp\Client;
use Google_Client;

trait Firebase
{
    protected function getKeyFilePath(): string
    {
        return storage_path('app/firebase/sami.json');
    }

    protected function getProjectId(): string
    {
        $path = $this->getKeyFilePath();
        $json = json_decode(file_get_contents($path), true);
        return $json['project_id'] ?? env('FIREBASE_PROJECT_ID', 'sami-s-project-173a2');
    }

    function getAccessToken(): string
    {
        $keyFilePath = $this->getKeyFilePath();
        $client = new Google_Client();
        $client->setAuthConfig($keyFilePath);
        $client->addScope('https://www.googleapis.com/auth/cloud-platform');
        $accessToken = $client->fetchAccessTokenWithAssertion();
        if (!isset($accessToken['access_token'])) {
            throw new \Exception('Failed to obtain access token: ' . json_encode($accessToken));
        }
        return $accessToken['access_token'];
    }

    public function HandelDataAndSendNotify(array $tokens, array $content, string $projectType = 'user', string $link = 'FLUTTER_NOTIFICATION_CLICK'): array
    {
Firebase2::test();

        if (empty($tokens)) {
            return ['success' => 0, 'failed_tokens' => []];
        }

        $accessToken = $this->getAccessToken();
        $projectId = $this->getProjectId();
        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
        $client = new \GuzzleHttp\Client();
        $failed = [];
        $successCount = 0;

        // إرسال في دفعات من 500
        $chunks = array_chunk(array_values($tokens), 500);
        foreach ($chunks as $chunk) {
            foreach ($chunk as $token) {
                try {
                    $payload = [
                        'message' => [
                            'token' => $token,
                            'notification' => [
                                'title' => $content['title'] ?? '',
                                'body'  => $content['body'] ?? '',
                            ],
                            'data' => array_merge(
                                $content['additional_data'] ?? [],
                                [
                                    'click_action' => $link,
                                    'type'         => $content['type'] ?? '',
                                    'object'       => is_array($content['object']) ? json_encode($content['object']) : ($content['object'] ?? ''),
                                    'screen'       => $content['screen'] ?? '',
                                ]
                            ),
                            'apns' => [
                                'payload' => ['aps' => ['sound' => 'default']]
                            ],
                        ],
                    ];

                    $response = $client->post($url, [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $accessToken,
                            'Content-Type'  => 'application/json',
                        ],
                        'json' => $payload,
                        'timeout' => 10,
                    ]);

                    $status = $response->getStatusCode();
                    $body = (string) $response->getBody();

                    \Log::info('FCM send', [
                        'token' => $token,
                        'code'  => $status,
                        'body'  => $body,
                    ]);

                    if ($status >= 200 && $status < 300) {
                        $successCount++;
                    } else {
                        $failed[] = $token;
                    }
                } catch (\Throwable $e) {
                    \Log::warning('FCM send failed: '.$e->getMessage(), ['token' => $token, 'exception' => $e]);
                    $failed[] = $token;
                }
            }
        }

        return ['success' => $successCount, 'failed_tokens' => array_values(array_unique($failed))];
    }


    // send to topic
    public function handleDataAndSendToTopic(string $topic, $content, $link = 'FLUTTER_NOTIFICATION_CLICK')
    {
        $accessToken = $this->getAccessToken();
        $projectId = $this->getProjectId();
        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
        $client = new Client();

        $payload = [
            'message' => [
                'topic' => $topic,
                'notification' => [
                    'title' => $content['title'] ?? '',
                    'body' => $content['body'] ?? '',
                ],
                'data' => $content['additional_data'] ?? [],
                'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
            ],
        ];

        $response = $client->post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
    }
}
