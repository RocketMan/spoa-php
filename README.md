# spoa-php

A lightweight PHP framework for building HAProxy **Stream Processing
Offload Agent (SPOA)** services

This library implements the SPOE protocol and allows PHP applications
to receive messages from HAProxy, process request or session metadata,
and return variables back to HAProxy for use in ACLs and routing
decisions.

[![Latest Version](https://img.shields.io/packagist/v/rocketman/spoa-php)](https://packagist.org/packages/rocketman/spoa-php)
[![CI](https://github.com/rocketman/spoa-php/actions/workflows/ci.yml/badge.svg)](https://github.com/rocketman/spoa-php/actions/workflows/ci.yml)
[![License](https://img.shields.io/badge/license-MIT-green)](https://github.com/rocketman/spoa-php?tab=readme-ov-file#MIT-1-ov-file)

---

## Features

* Native SPOE protocol implementation
* Event-driven, asynchronous I/O using ReactPHP
* Simple handler-based programming model
* Support for all HAProxy variable scopes
* Minimal runtime dependencies
* PHPUnit and PHPStan coverage

---

## Requirements

* PHP **8.2** or later
* A running HAProxy instance with SPOE enabled

---

## Installation

Install via Composer:

```bash
composer require rocketman/spoa-php
```

### Dependencies

**Runtime**

* `react/socket`

**Development**

* `phpunit/phpunit`
* `phpstan/phpstan`
* `react/event-loop`

---

## Core Concepts

### The `Connection` class

The central abstraction in this framework is `SPOA\Server\Connection`.

Each incoming SPOE connection from HAProxy is represented by a
`Connection` instance.  Applications register message handlers on the
connection and return variables (or Promises that resolve to variables)
to HAProxy in response.

The framework handles:

* SPOE handshake and negotiation
* Message decoding
* Frame fragmentation
* Action encoding and responses

Your application code only needs to define handlers.

---

### Registering message handlers

Handlers are registered by message name:

```php
$conn->on('get-ip-reputation', function (array $args): PromiseInterface|array {
    // ...
});
```

* **Message Name**: The event name (e.g., `get-ip-reputation`) **must
    match** the `spoe-message` identifier in your HAProxy
    configuration.

* **Arguments (**`$args`**)**: An associative array of
    `SPOA\Protocol\Arg` object instances.

    * **Named Arguments**: Accessed via their name defined in HAProxy
        (e.g., `$args['client_ip']`).

    * **Nameless Arguments**: Accessed via the key `arg(n)`, where `n`
        is the zero-based position (e.g., `$args['arg(0)']`).

    * **Ordinal Access**: As the array maintains the order defined in
        HAProxy, you can retreive any argument by its position
        regardless of its name (e.g., `$fourthArg =
        $args[array_keys($args)[3]]`).

---

## Returning variables to HAProxy

Handlers return an associative array of variables to set or unset.

```php
use SPOA\Protocol\Arg;

return [
    'sess.one' => Arg::str('two'),
    'txn.score' => Arg::uint32(42),
];
```

Alternatively, a handler may return a `React\Promise\PromiseInterface`
that resolves to the associative array.  This allows handlers to perform
asynchronous operations before returning data to HAProxy.

#### Variable scopes

Variable names may be prefixed with a scope:

| Prefix  | Scope       |
| ------- | ----------- |
| `proc.` | Process     |
| `sess.` | Session     |
| `txn.`  | Transaction |
| `req.`  | Request     |
| `res.`  | Response    |

If no prefix is provided, the variable defaults to **process scope**.

Specifying `null` as a value will **unset** the variable.  To set a
variable to null, use `Arg::null()`.

---

## Getting Started

Below is a minimal SPOA agent implemented using this library.  The
example is intentionally procedural to focus on the framework’s
programming model.

### Minimal PHP agent

```php
<?php
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use React\Socket\Server;

use SPOA\Protocol\Arg;
use SPOA\Server\Connection;

$loop = Loop::get();

$server = new Server('127.0.0.1:12345', $loop);

$server->on('connection', function ($conn) {
    $spoa = new Connection($conn);

    $spoa->on('get-ip-reputation', function (array $args): PromiseInterface|array {
        $srcIp = $args['client_ip']?->value;

        // check IP address and set reputation
        $rep = 42;

        return [
            'sess.score' => Arg::uint32($rep),
        ];
    });
});

$loop->run();
```

This example:

* Listens on a TCP socket for SPOE connections from HAProxy
* Registers a handler for a single SPOE message
* Returns a session-scoped variable to HAProxy

The application runs inside a standard ReactPHP event loop.

---

## HAProxy Configuration

### SPOE configuration (`spoe-test.cfg`)

```cfg
spoe-agent iprep-agent
    messages get-ip-reputation
    option var-prefix iprep

    timeout hello 200ms
    timeout idle 3m
    timeout processing 500ms

    use-backend iprep-server

spoe-message get-ip-reputation
    args client_ip=src
    event on-frontend-http-request
```

---

### HAProxy configuration excerpt

```cfg
frontend http
    filter spoe config /etc/haproxy/spoe-test.cfg
    http-request set-header X-Pass 1 if { var(sess.iprep.score) -m int gt 20 }

backend iprep-server
    mode tcp
    option spop-check
    timeout connect 5s
    timeout server 3m
    server iprep 127.0.0.1:12345 # check (check is optional)
```

This configuration:

* Sends the client source IP to the SPOA agent
* Receives variables set by the agent
* Uses those variables in standard HAProxy ACL logic

---

## Testing

Run the test suite:

```bash
vendor/bin/phpunit
```

Static analysis:

```bash
vendor/bin/phpstan analyse src tests
```

Both are executed automatically via GitHub Actions on push.

---

## License

MIT License. See `LICENSE` for details.
