# Transformer PHP

A small, self-contained PHP 8.5 / Symfony 8.1 web application that implements a real (tiny) Transformer language model from the paper [_Attention Is All You Need_](https://arxiv.org/abs/1706.03762). Designed to be read end-to-end with a step debugger.

## What's in here

- A character-level tokenizer
- A pre-norm Transformer with single-head attention, FFN, and LayerNorm
- Hand-derived backward pass for every layer, with finite-difference gradient tests
- Adam optimizer (no bias correction)
- Hexagonal architecture: pure-PHP Domain, Application CQRS, Infrastructure (Doctrine, Messenger, Transformer math)
- Symfony Messenger with two async transports (`async_training`, `async_inference`) so the training worker can be killed between epochs and resumed
- All weights stored as one row per matrix element in MariaDB — you can `SELECT * FROM model_attention_weights` to see a real matrix
- Xdebug 3.4 + VS Code launch configurations for stepping through the math while the worker runs

## Quickstart (5 minutes)

```bash
cp .env.local.example .env.local
docker compose up -d --build
docker compose exec php composer install
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
open http://localhost:8080
```

In two more terminals, start the two workers:

```bash
docker compose exec php bin/console messenger:consume async_training -vv
docker compose exec php bin/console messenger:consume async_inference -vv
```

Then in the browser: paste a corpus, create a model, click "Train 10 epochs", click "Generate" on the model page.

## Documentation

- [`SPECKIT.md`](./SPECKIT.md) — the full specification. Read sections 1–7 first.
- [`docs/QUICKSTART.md`](./docs/QUICKSTART.md) — five-minute walkthrough.
- [`docs/CHECKLIST.md`](./docs/CHECKLIST.md) — implementation checklist by phase.
- [`docs/ACCEPTANCE.md`](./docs/ACCEPTANCE.md) — what "done" means.
- [`docs/DECISIONS.md`](./docs/DECISIONS.md) — every significant design decision.

## Project layout

```
src/
├── Kernel.php
├── Shared/                       # AggregateRoot, DomainEvent, Clock, EventCollector
└── LanguageModel/
    ├── Domain/                   # pure PHP, no framework imports
    │   ├── Model/                # LanguageModel AR, ModelConfig, Weights, ModelStatus
    │   ├── Token/                # Vocabulary AR, TokenSequence, Character, TokenId
    │   ├── Corpus/               # Corpus AR
    │   ├── Training/             # TrainingJob AR, TrainingConfig, TrainingLoss
    │   ├── Inference/            # Prediction AR, SamplingConfig, SamplingStrategy
    │   ├── Event/                # Domain events
    │   └── Repository/           # Repository interfaces
    ├── Application/              # use-cases; depends on Domain only
    │   ├── Command/  CommandHandler/  Query/  QueryHandler/  Port/
    ├── Infrastructure/           # Symfony, Doctrine, Messenger, math
    │   ├── Persistence/Doctrine/ # Entities, XML mappings, repositories
    │   ├── Tokenizer/            # CharacterTokenizer
    │   ├── Transformer/          # Tensor, Embedding, Attention, FFN, LayerNorm, SoftmaxCE, Adam, ModelTrainer, ModelPredictor
    │   └── Messenger/            # Messages and handlers
    └── HttpInterface/            # Controllers, Forms, Views, Twig templates
```

## Run the tests

```bash
make test-unit         # 99 unit tests for Domain + math; no DB needed
make test-integration  # needs MariaDB up
make test-functional   # needs MariaDB up
make stan              # PHPStan level 9
```

## License

MIT.
