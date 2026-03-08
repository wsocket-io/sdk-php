<?php

/**
 * wSocket PHP SDK — Realtime Pub/Sub client with Presence, History and Connection Recovery.
 *
 * Usage:
 *   $client = new \wSocket\Client('ws://localhost:9001', 'your-api-key');
 *   $client->connect();
 *
 *   $chat = $client->channel('chat:general');
 *   $chat->subscribe(function ($data, $meta) {
 *       echo "[{$meta['channel']}] " . json_encode($data) . "\n";
 *   });
 *
 *   // Presence
 *   $chat->presence()->onEnter(function ($member) { echo "Joined: {$member['clientId']}\n"; });
 *   $chat->presence()->enter(['name' => 'Alice']);
 *
 *   // History
 *   $chat->onHistory(function ($result) { echo count($result['messages']) . " msgs\n"; });
 *   $chat->history(['limit' => 50]);
 *
 *   $client->listen();  // blocking event loop
 */

namespace wSocket;

use WebSocket\Client as WsClient;

// ─── Presence ───────────────────────────────────────────────

class Presence
{
    private string $channelName;
    private Client $client;
    /** @var callable[] */
    private array $enterCbs = [];
    /** @var callable[] */
    private array $leaveCbs = [];
    /** @var callable[] */
    private array $updateCbs = [];
    /** @var callable[] */
    private array $membersCbs = [];

    public function __construct(string $channelName, Client $client)
    {
        $this->channelName = $channelName;
        $this->client = $client;
    }

    /** Enter the presence set with optional data. */
    public function enter($data = null): self
    {
        $msg = ['action' => 'presence.enter', 'channel' => $this->channelName];
        if ($data !== null) $msg['data'] = $data;
        $this->client->send($msg);
        return $this;
    }

    /** Leave the presence set. */
    public function leave(): self
    {
        $this->client->send(['action' => 'presence.leave', 'channel' => $this->channelName]);
        return $this;
    }

    /** Update presence data. */
    public function update($data): self
    {
        $this->client->send(['action' => 'presence.update', 'channel' => $this->channelName, 'data' => $data]);
        return $this;
    }

    /** Get current members. */
    public function get(): self
    {
        $this->client->send(['action' => 'presence.get', 'channel' => $this->channelName]);
        return $this;
    }

    public function onEnter(callable $cb): self { $this->enterCbs[] = $cb; return $this; }
    public function onLeave(callable $cb): self { $this->leaveCbs[] = $cb; return $this; }
    public function onUpdate(callable $cb): self { $this->updateCbs[] = $cb; return $this; }
    public function onMembers(callable $cb): self { $this->membersCbs[] = $cb; return $this; }

    /** @internal */
    public function emitEnter(array $member): void { foreach ($this->enterCbs as $cb) { try { $cb($member); } catch (\Throwable $e) {} } }
    /** @internal */
    public function emitLeave(array $member): void { foreach ($this->leaveCbs as $cb) { try { $cb($member); } catch (\Throwable $e) {} } }
    /** @internal */
    public function emitUpdate(array $member): void { foreach ($this->updateCbs as $cb) { try { $cb($member); } catch (\Throwable $e) {} } }
    /** @internal */
    public function emitMembers(array $members): void { foreach ($this->membersCbs as $cb) { try { $cb($members); } catch (\Throwable $e) {} } }
}

// ─── Channel ────────────────────────────────────────────────

class Channel
{
    public string $name;
    private Client $client;
    private Presence $presence;
    private bool $subscribed = false;
    /** @var callable[] */
    private array $callbacks = [];
    /** @var callable[] */
    private array $historyCbs = [];

    public function __construct(string $name, Client $client)
    {
        $this->name = $name;
        $this->client = $client;
        $this->presence = new Presence($name, $client);
    }

    /** Get presence API for this channel. */
    public function presence(): Presence
    {
        return $this->presence;
    }

    /** Subscribe to messages on this channel. */
    public function subscribe(callable $callback, int $rewind = 0): self
    {
        $this->callbacks[] = $callback;
        if (!$this->subscribed) {
            $msg = ['action' => 'subscribe', 'channel' => $this->name];
            if ($rewind > 0) $msg['rewind'] = $rewind;
            $this->client->send($msg);
            $this->subscribed = true;
        }
        return $this;
    }

    /** Publish data to this channel. */
    public function publish($data, bool $persist = true): void
    {
        $msg = [
            'action' => 'publish',
            'channel' => $this->name,
            'data' => $data,
            'id' => $this->generateUuid(),
        ];
        if (!$persist) $msg['persist'] = false;
        $this->client->send($msg);
    }

    /** Query message history. */
    public function history(array $opts = []): self
    {
        $msg = ['action' => 'history', 'channel' => $this->name];
        if (isset($opts['limit'])) $msg['limit'] = $opts['limit'];
        if (isset($opts['before'])) $msg['before'] = $opts['before'];
        if (isset($opts['after'])) $msg['after'] = $opts['after'];
        if (isset($opts['direction'])) $msg['direction'] = $opts['direction'];
        $this->client->send($msg);
        return $this;
    }

    /** Listen for history query results. */
    public function onHistory(callable $cb): self
    {
        $this->historyCbs[] = $cb;
        return $this;
    }

    /** Unsubscribe from this channel. */
    public function unsubscribe(): void
    {
        $this->client->send(['action' => 'unsubscribe', 'channel' => $this->name]);
        $this->subscribed = false;
        $this->callbacks = [];
        $this->historyCbs = [];
    }

    /** @internal */
    public function emit($data, array $meta): void
    {
        foreach ($this->callbacks as $cb) {
            try { $cb($data, $meta); } catch (\Throwable $e) {}
        }
    }

    /** @internal */
    public function emitHistory(array $result): void
    {
        foreach ($this->historyCbs as $cb) {
            try { $cb($result); } catch (\Throwable $e) {}
        }
    }

    /** @internal */
    public function markForResubscribe(): void { $this->subscribed = false; }
    /** @internal */
    public function hasListeners(): bool { return count($this->callbacks) > 0; }

    private function generateUuid(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

// ─── Client ─────────────────────────────────────────────────

class Client
{
    private string $url;
    private string $apiKey;
    private bool $autoReconnect;
    private int $maxReconnectAttempts;
    private float $reconnectDelaySec;
    private bool $recover;
    private ?WsClient $ws = null;
    /** @var Channel[] */
    private array $channels = [];
    private int $lastMessageTimestamp = 0;
    private ?string $resumeToken = null;
    private float $lastPingTime = 0;
    private float $pingIntervalSec = 30.0;
    private bool $running = false;

    /** Pub/Sub namespace — $client->pubsub->channel('name') */
    public PubSubNamespace $pubsub;
    /** Push namespace — set via $client->configurePush() */
    public ?PushClient $push = null;

    public function __construct(
        string $url,
        string $apiKey,
        bool $autoReconnect = true,
        int $maxReconnectAttempts = 10,
        float $reconnectDelaySec = 1.0,
        bool $recover = true,
    ) {
        $this->url = $url;
        $this->apiKey = $apiKey;
        $this->autoReconnect = $autoReconnect;
        $this->maxReconnectAttempts = $maxReconnectAttempts;
        $this->reconnectDelaySec = $reconnectDelaySec;
        $this->recover = $recover;
        $this->pubsub = new PubSubNamespace($this);
    }

    /** Connect to the wSocket server. */
    public function connect(): void
    {
        $this->ws = new WsClient($this->url . '/?key=' . $this->apiKey, [
            'timeout' => 60,
        ]);
        $this->lastPingTime = microtime(true);
        $this->resubscribeAll();
    }

    /** Disconnect from the server. */
    public function disconnect(): void
    {
        $this->running = false;
        if ($this->ws !== null) {
            try { $this->ws->close(); } catch (\Throwable $e) {}
            $this->ws = null;
        }
    }

    /**
     * Get or create a channel reference.
     * @deprecated Use $client->pubsub->channel($name) for new code.
     */
    public function channel(string $name): Channel
    {
        if (!isset($this->channels[$name])) {
            $this->channels[$name] = new Channel($name, $this);
        }
        return $this->channels[$name];
    }

    /** Configure push notification access. */
    public function configurePush(string $baseUrl, string $token, string $appId): PushClient
    {
        $this->push = new PushClient($baseUrl, $token, $appId);
        return $this->push;
    }

    /** @internal Send a message. */
    public function send(array $msg): void
    {
        if ($this->ws === null) return;
        try {
            $this->ws->text(json_encode($msg));
        } catch (\Throwable $e) {
            fwrite(STDERR, "[wSocket] Send error: " . $e->getMessage() . "\n");
        }
    }

    /**
     * Blocking event loop — listens for incoming messages.
     * Call this after setting up subscriptions.
     */
    public function listen(): void
    {
        $this->running = true;
        $reconnectAttempts = 0;

        while ($this->running) {
            try {
                // Ping if needed
                $now = microtime(true);
                if ($now - $this->lastPingTime >= $this->pingIntervalSec) {
                    $this->send(['action' => 'ping']);
                    $this->lastPingTime = $now;
                }

                $raw = $this->ws->receive();
                if ($raw === null) continue;
                $reconnectAttempts = 0;
                $this->handleMessage((string) $raw);
            } catch (\WebSocket\ConnectionException $e) {
                fwrite(STDERR, "[wSocket] Connection lost: " . $e->getMessage() . "\n");
                if (!$this->autoReconnect || !$this->running) break;
                $reconnectAttempts++;
                if ($reconnectAttempts > $this->maxReconnectAttempts) {
                    fwrite(STDERR, "[wSocket] Max reconnect attempts reached\n");
                    break;
                }
                $delay = $this->reconnectDelaySec * pow(2, $reconnectAttempts - 1);
                fwrite(STDERR, "[wSocket] Reconnecting in {$delay}s (attempt {$reconnectAttempts}/{$this->maxReconnectAttempts})\n");
                usleep((int) ($delay * 1_000_000));
                try {
                    $this->connect();
                } catch (\Throwable $e) {
                    continue;
                }
            } catch (\Throwable $e) {
                fwrite(STDERR, "[wSocket] Error: " . $e->getMessage() . "\n");
                break;
            }
        }
    }

    private function handleMessage(string $raw): void
    {
        $msg = json_decode($raw, true);
        if (!is_array($msg) || !isset($msg['action'])) return;

        $action = $msg['action'];
        $channel = $msg['channel'] ?? '';

        switch ($action) {
            case 'message':
                if (isset($this->channels[$channel])) {
                    $ts = $msg['timestamp'] ?? 0;
                    if ($ts > $this->lastMessageTimestamp) $this->lastMessageTimestamp = $ts;
                    $this->channels[$channel]->emit($msg['data'] ?? null, [
                        'id' => $msg['id'] ?? '',
                        'channel' => $channel,
                        'timestamp' => $ts,
                    ]);
                }
                break;

            case 'presence.enter':
                if (isset($this->channels[$channel])) {
                    $this->channels[$channel]->presence()->emitEnter($msg['data'] ?? []);
                }
                break;

            case 'presence.leave':
                if (isset($this->channels[$channel])) {
                    $this->channels[$channel]->presence()->emitLeave($msg['data'] ?? []);
                }
                break;

            case 'presence.update':
                if (isset($this->channels[$channel])) {
                    $this->channels[$channel]->presence()->emitUpdate($msg['data'] ?? []);
                }
                break;

            case 'presence.members':
                if (isset($this->channels[$channel])) {
                    $this->channels[$channel]->presence()->emitMembers($msg['data'] ?? []);
                }
                break;

            case 'history':
                if (isset($this->channels[$channel])) {
                    $this->channels[$channel]->emitHistory($msg['data'] ?? []);
                }
                break;

            case 'ack':
                if (($msg['id'] ?? '') === 'resume') {
                    $this->resumeToken = $msg['data']['resumeToken'] ?? null;
                }
                break;

            case 'error':
                fwrite(STDERR, "[wSocket] Error: " . ($msg['error'] ?? 'unknown') . "\n");
                break;
        }
    }

    private function resubscribeAll(): void
    {
        if ($this->recover && $this->lastMessageTimestamp > 0) {
            $names = [];
            foreach ($this->channels as $ch) {
                if ($ch->hasListeners()) {
                    $names[] = $ch->name;
                    $ch->markForResubscribe();
                }
            }
            if (count($names) > 0) {
                $token = $this->resumeToken;
                if (empty($token)) {
                    $payload = json_encode(['channels' => $names, 'lastTs' => $this->lastMessageTimestamp]);
                    $token = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
                }
                $this->send(['action' => 'resume', 'resumeToken' => $token]);
                return;
            }
        }
        foreach ($this->channels as $ch) {
            $ch->markForResubscribe();
            if ($ch->hasListeners()) {
                $this->send(['action' => 'subscribe', 'channel' => $ch->name]);
            }
        }
    }
}

// ─── PubSub Namespace ────────────────────────────────────────

/** Thin proxy exposing pub/sub channel access. */
class PubSubNamespace
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /** Get or create a channel reference. */
    public function channel(string $name): Channel
    {
        return $this->client->channel($name);
    }
}

// ─── Push Notifications ─────────────────────────────────────

/**
 * REST-based push notification client for wSocket.
 *
 * Usage:
 *   $push = new PushClient('http://localhost:9001', 'admin-token', 'app-id');
 *   $push->registerFCM('device-token', 'user1');
 *   $push->sendToMember('user1', ['title' => 'Hello', 'body' => 'World']);
 */
class PushClient
{
    private string $baseUrl;
    private string $token;
    private string $appId;

    public function __construct(string $baseUrl, string $token, string $appId)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
        $this->appId = $appId;
    }

    private function apiRequest(string $method, string $path, ?array $body = null): array
    {
        $url = $this->baseUrl . $path;
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->token,
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode >= 400) {
            throw new \RuntimeException("Push API error $httpCode: $response");
        }
        return json_decode($response, true) ?? [];
    }

    /** Register an FCM device token (Android). */
    public function registerFCM(string $deviceToken, ?string $memberId = null): string
    {
        $res = $this->apiRequest('POST', "/api/admin/apps/{$this->appId}/push/register", [
            'platform' => 'fcm',
            'memberId' => $memberId,
            'deviceToken' => $deviceToken,
        ]);
        return $res['subscriptionId'];
    }

    /** Register an APNs device token (iOS). */
    public function registerAPNs(string $deviceToken, ?string $memberId = null): string
    {
        $res = $this->apiRequest('POST', "/api/admin/apps/{$this->appId}/push/register", [
            'platform' => 'apns',
            'memberId' => $memberId,
            'deviceToken' => $deviceToken,
        ]);
        return $res['subscriptionId'];
    }

    /** Unregister push subscriptions for a member. */
    public function unregister(string $memberId, ?string $platform = null): int
    {
        $body = ['memberId' => $memberId];
        if ($platform) $body['platform'] = $platform;
        $res = $this->apiRequest('DELETE', "/api/admin/apps/{$this->appId}/push/unregister", $body);
        return $res['removed'] ?? 0;
    }

    /** Delete a specific push subscription by its ID. */
    public function deleteSubscription(string $subscriptionId): bool
    {
        $res = $this->apiRequest('DELETE', "/api/admin/apps/{$this->appId}/push/subscriptions/{$subscriptionId}");
        return $res['deleted'] ?? false;
    }

    /** Send a push notification to a specific member. */
    public function sendToMember(string $memberId, array $payload): array
    {
        return $this->apiRequest('POST', "/api/admin/apps/{$this->appId}/push/send",
            array_merge(['memberId' => $memberId], $payload));
    }

    /** Broadcast a push notification to all app subscribers. */
    public function broadcast(array $payload): array
    {
        return $this->apiRequest('POST', "/api/admin/apps/{$this->appId}/push/send",
            array_merge(['broadcast' => true], $payload));
    }

    /** Get push notification statistics. */
    public function getStats(): array
    {
        $res = $this->apiRequest('GET', "/api/admin/apps/{$this->appId}/push/stats");
        return $res['stats'] ?? [];
    }
}
