<?php

use App\Support\AiJson;

test('ai json parser extracts object from fenced response', function () {
    $payload = AiJson::object(<<<'TEXT'
Segue o JSON:

```json
{
  "title": "PRD",
  "modules": [
    {"name": "Portfolio"}
  ]
}
```
TEXT, 'teste');

    expect($payload['title'])->toBe('PRD')
        ->and($payload['modules'][0]['name'])->toBe('Portfolio');
});

test('ai json parser extracts balanced json from prose', function () {
    $payload = AiJson::object('Resultado aprovado: {"title":"Blueprint","domain_model":{"entities":[],"relationships":[]}} fim.', 'teste');

    expect($payload['title'])->toBe('Blueprint')
        ->and($payload['domain_model']['entities'])->toBe([]);
});

test('ai json parser rejects empty or list payload for object responses', function () {
    AiJson::object('[{"name":"Modulo"}]', 'teste');
})->throws(RuntimeException::class, 'objeto esperado');
