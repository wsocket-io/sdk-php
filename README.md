# wSocket PHP SDK

Official PHP SDK for [wSocket](https://wsocket.io) — Realtime Pub/Sub over WebSockets.

[![Packagist](https://img.shields.io/packagist/v/wsocket-io/sdk)](https://packagist.org/packages/wsocket-io/sdk)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

## Installation

```bash
composer require wsocket-io/sdk
```

## Quick Start

```php
<?php
require 'vendor/autoload.php';

$client = new \wSocket\Client('wss://node00.wsocket.online', 'your-api-key');
$client->connect();

$chat = $client->channel('chat:general');
$chat->subscribe(function ($data, $meta) {
    echo "[{$meta['channel']}] " . json_encode($data) . "\n";
});

$chat->publish(['text' => 'Hello from PHP!']);
$client->listen(); // blocking event loop
```

## Features

- **Pub/Sub** — Subscribe and publish to channels in real-time
- **Presence** — Track who is online in a channel
- **History** — Retrieve past messages
- **Connection Recovery** — Automatic reconnection with message replay

## Presence

```php
$chat = $client->channel('chat:general');

$chat->presence()->onEnter(function ($member) {
    echo "Joined: {$member['clientId']}\n";
});

$chat->presence()->onLeave(function ($member) {
    echo "Left: {$member['clientId']}\n";
});

$chat->presence()->enter(['name' => 'Alice']);
$members = $chat->presence()->get();
```

## History

```php
$chat->onHistory(function ($result) {
    foreach ($result['messages'] as $msg) {
        echo "[{$msg['timestamp']}] " . json_encode($msg['data']) . "\n";
    }
});

$chat->history(['limit' => 50]);
```

## Requirements

- PHP >= 8.1
- `textalk/websocket >= 1.6`

## Development

```bash
composer install
composer test
```

## License

MIT
