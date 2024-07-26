<?php

namespace FoskyM\IssueTrackingYoutrack;

use Flarum\Extend;

class YouTrack
{
    protected $url;
    protected $token;
    protected $project;
    public function __construct($url, $token, $project)
    {
        $this->url = rtrim(str_replace("\r\n", '', $url), '/') . '/api';
        $this->token = str_replace("\r\n", '', $token);
        $this->project = str_replace("\r\n", '', $project);
    }

    public function statusCode($path, $method = 'GET', $data = [])
    {
        $response = $this->request($path, $method, $data);
        return $response['code'];
    }
    public function get($path)
    {
        $response = $this->request($path);
        return json_decode($response['response'], true);
    }
    public function post($path, $data)
    {
        $response = $this->request($path, 'POST', $data);
        return json_decode($response['response'], true);
    }
    public function request($path, $method = 'GET', $data = [])
    {
        $curl = curl_init();
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $this->url . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
            ],
        ]);

        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($curl);
        $err = curl_error($curl);
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($err) {
            throw new \Exception($err);
        }

        return [
            'code' => $code,
            'response' => $response,
        ];
    }
}