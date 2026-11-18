<?php

class KypanelApi
{
    private $base;
    private $token;
    private $verifyTls;
    private $ids = [];

    // last request, for the module log
    public $lastRequest = '';
    public $lastResponse = '';

    public function __construct($host, $token, $port = 7711, $verifyTls = true)
    {
        $host = trim((string) $host);
        if ($host === '') {
            throw new RuntimeException('The server has no hostname.');
        }
        $this->token = trim((string) $token);
        if ($this->token === '') {
            throw new RuntimeException('The server has no API token.');
        }
        $this->base = 'https://' . $host . ':' . ((int) $port ?: 7711);
        $this->verifyTls = (bool) $verifyTls;
    }

    public function get($path, array $query = [])
    {
        return $this->request('GET', $path, null, $query);
    }

    public function post($path, $body = null)
    {
        return $this->request('POST', $path, $body === null ? new stdClass() : $body);
    }

    public function put($path, $body)
    {
        return $this->request('PUT', $path, $body);
    }

    public function delete($path, array $query = [])
    {
        return $this->request('DELETE', $path, null, $query);
    }

    public function request($method, $path, $body = null, array $query = [])
    {
        $url = $this->base . $path . ($query ? '?' . http_build_query($query) : '');
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
            'User-Agent: KyPanel-Clientexec',
        ];
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $this->lastRequest = $method . ' ' . $path;
        $this->lastResponse = $code . ' ' . (is_string($raw) ? $raw : '');

        if ($raw === false) {
            throw new RuntimeException('Couldn\'t reach ' . $this->base . ': ' . $err);
        }
        $data = $raw === '' ? [] : json_decode($raw, true);
        if ($code >= 200 && $code < 300) {
            return is_array($data) ? $data : [];
        }
        throw new RuntimeException(self::problem($code, is_array($data) ? $data : []));
    }

    // RFC 7807 problem -> "title: detail (field: message)"
    private static function problem($code, array $p)
    {
        $msg = isset($p['title']) ? $p['title'] : 'HTTP ' . $code;
        if (!empty($p['detail'])) {
            $msg .= ': ' . $p['detail'];
        }
        if (!empty($p['fields']) && is_array($p['fields'])) {
            $parts = [];
            foreach ($p['fields'] as $field => $text) {
                $parts[] = $field . ': ' . $text;
            }
            $msg .= ' (' . implode('; ', $parts) . ')';
        }
        return $msg;
    }

    public function accountId($username)
    {
        $username = strtolower(trim((string) $username));
        if ($username === '') {
            throw new RuntimeException('The service has no username.');
        }
        if (isset($this->ids[$username])) {
            return $this->ids[$username];
        }
        $page = $this->get('/api/v1/accounts', ['q' => $username, 'page' => 1, 'per_page' => 100]);
        foreach (isset($page['items']) ? $page['items'] : [] as $a) {
            if (strtolower(isset($a['username']) ? $a['username'] : '') === $username) {
                return $this->ids[$username] = $a['id'];
            }
        }
        throw new RuntimeException('There is no account named "' . $username . '" on this KyPanel server.');
    }

    public function accountExists($username)
    {
        try {
            $this->accountId($username);
            return true;
        } catch (RuntimeException $e) {
            return false;
        }
    }

    public function account($username)
    {
        return $this->get('/api/v1/accounts/' . rawurlencode($this->accountId($username)));
    }

    // accepts the package name or id
    public function packageId($nameOrId)
    {
        $want = trim((string) $nameOrId);
        if ($want === '') {
            throw new RuntimeException('Set the KyPanel package name in the product (Name on server).');
        }
        $names = [];
        $res = $this->get('/api/v1/packages');
        foreach (isset($res['items']) ? $res['items'] : [] as $p) {
            if ($p['id'] === $want || strcasecmp($p['name'], $want) === 0) {
                return $p['id'];
            }
            $names[] = $p['name'];
        }
        throw new RuntimeException('There is no package "' . $want . '" on this KyPanel server. Packages: '
            . ($names ? implode(', ', $names) : 'none yet') . '.');
    }

    public function createAccount($username, $domain, $password, $email, $package, $reseller = false)
    {
        $body = [
            'username' => strtolower($username),
            'domain' => strtolower($domain),
            'password' => $password,
            'contact_email' => (string) $email,
            'package_id' => $this->packageId($package),
        ];
        if ($reseller) {
            $body['make_reseller'] = true;
        }
        $res = $this->post('/api/v1/accounts', $body);
        $this->wait(isset($res['job_id']) ? $res['job_id'] : '');
    }

    public function suspend($username, $reason)
    {
        $res = $this->post('/api/v1/accounts/' . rawurlencode($this->accountId($username)) . '/suspend', ['reason' => $reason]);
        $this->wait(isset($res['job_id']) ? $res['job_id'] : '');
    }

    public function unsuspend($username)
    {
        $res = $this->post('/api/v1/accounts/' . rawurlencode($this->accountId($username)) . '/unsuspend');
        $this->wait(isset($res['job_id']) ? $res['job_id'] : '');
    }

    public function terminate($username)
    {
        $username = strtolower($username);
        $res = $this->delete('/api/v1/accounts/' . rawurlencode($this->accountId($username)), ['confirm' => $username]);
        $this->wait(isset($res['job_id']) ? $res['job_id'] : '');
    }

    public function changePassword($username, $password)
    {
        $this->post('/api/v1/accounts/' . rawurlencode($this->accountId($username)) . '/password', ['new_password' => $password]);
    }

    public function changePackage($username, $package)
    {
        $res = $this->put('/api/v1/accounts/' . rawurlencode($this->accountId($username)) . '/package', [
            'package_id' => $this->packageId($package),
        ]);
        $this->wait(isset($res['job_id']) ? $res['job_id'] : '');
    }

    // one-time sign-in link, valid for 60 seconds
    public function loginUrl($username, $panel = 'user')
    {
        $res = $this->post('/api/v1/accounts/' . rawurlencode($this->accountId($username)) . '/sso', ['panel' => $panel]);
        return isset($res['url']) ? $res['url'] : '';
    }

    public function testConnection()
    {
        $this->get('/api/v1/packages');
        $this->get('/api/v1/accounts', ['page' => 1, 'per_page' => 1]);
    }

    // create/suspend/terminate run as jobs on the server, poll until done
    public function wait($jobId, $seconds = 240)
    {
        if ($jobId === '') {
            return;
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit($seconds + 60);
        }
        $until = time() + $seconds;
        $delay = 1;
        do {
            $res = $this->get('/api/v1/jobs/' . rawurlencode($jobId));
            $job = isset($res['job']) ? $res['job'] : [];
            $status = isset($job['status']) ? $job['status'] : '';
            if ($status === 'succeeded') {
                return;
            }
            if (in_array($status, ['failed', 'canceled', 'cancelled'], true)) {
                throw new RuntimeException(!empty($job['error']) ? $job['error'] : 'The KyPanel job failed.');
            }
            sleep($delay);
            $delay = min($delay + 1, 5);
        } while (time() < $until);
        throw new RuntimeException('KyPanel is still working on it (job ' . $jobId . '). Check Ky Admin before trying again.');
    }
}
