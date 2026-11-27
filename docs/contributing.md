# Contributing

## Roadmap

- [x] Schema generator and generated types (v1)
- [x] JSON-RPC core: bidirectional requests, notifications, errors, `$/cancel_request`
- [x] Transports: stdio subprocess (amphp v3), in-memory for tests
- [x] Client API: `initialize`, `authenticate`, sessions, prompt turns, cancellation, `session/load`, modes
- [x] Client handlers: permissions, file system
- [x] `FakeAgent`: a scriptable agent to test ACP clients without a real one
- [ ] Client handlers: elicitation, terminals
- [ ] `session/list`, `session/resume`, `session/close`, config options
- [ ] Integration tests against `claude-agent-acp` with real credentials
- [ ] Agent side, to write ACP agents in PHP
- [ ] Protocol v2, behind version negotiation

## Development

```bash
composer install
composer test           # PHPUnit, both suites
composer test:feature   # use cases, through the public API
composer test:contract  # generated src/Schema against the JSON schema
composer analyse        # PHPStan
composer check-schema   # fails if src/Schema is out of date
```

`src/Schema` is generated: never edit it by hand. To follow a new release of the protocol:

```bash
composer update-schema               # latest release, or pass a tag: composer update-schema -- schema-v1.36.0
composer generate
git diff src/Schema                  # review what changed in the protocol
```
